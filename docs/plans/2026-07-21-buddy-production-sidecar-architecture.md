# Buddy Production Sidecar Architecture and Azure Rollout Plan

**Status:** Proposed; implementation and infrastructure changes require approval
**Date:** 2026-07-21
**Scope:** Buddy prompts, grounded memory, controlled recursive improvement, concurrent callers, API authentication, Redis, Go memory-hub integration, and Azure deployment

## 1. Executive decisions

1. **Keep Buddy and the memory hub as separate services.** Buddy owns decision orchestration, prompt policy, task/run state, API clients, feedback, and audit records. The Go `qdrant-memory` hub owns governed long-term vector memory, retrieval, curation, arbitration, and knowledge-graph features.
2. **Do not add pgvector to Buddy now.** PostgreSQL should become Buddy's transactional source of truth, but adding a second vector store would duplicate the mature Go/Qdrant data plane. Keep pgvector as a measured fallback/consolidation option, not the initial migration target.
3. **Replace direct Qdrant access with a `MemoryGateway`.** Buddy must call the Go hub's authenticated REST interface rather than bypassing its tenancy, content-addressing, hybrid retrieval, curation, and evaluation controls.
4. **Use Azure Container Apps for Buddy and the Go hub, but not for Qdrant storage.** Run Qdrant as Qdrant Managed Cloud in an Azure region through Azure Marketplace where possible. Qdrant requires POSIX block storage; Azure Container Apps exposes ephemeral storage or Azure Files, neither of which is the correct Qdrant production data volume.
5. **Use Azure Database for PostgreSQL Flexible Server and Azure Managed Redis.** Existing PostgreSQL servers in the account are public, burstable B1ms instances without HA and are not production-ready as-is. Do not create a new Azure Cache for Redis dependency because that service is on a retirement path.
6. **Scale Buddy horizontally; initially keep the Go hub at one replica.** Buddy's API and workers become stateless/lease-based. The Go hub already supports concurrent goroutines and per-content-ID locking within one process, but its locks and job/schedule/revocation persistence are not safe across replicas. Horizontal hub scaling is a later hardening milestone.
7. **Expose an API-key-authenticated REST API first.** Keep MCP local through a thin stdio-to-HTTPS bridge that receives only `BUDDY_BASE_URL` and `BUDDY_API_KEY`. Native remote Streamable HTTP MCP is deferred until OAuth 2.1, audience binding, and protocol-compliant authorization are implemented.
8. **Treat recursive self-improvement as a controlled offline improvement loop.** It may propose prompt, routing, or memory-policy candidates, but cannot modify production prompts, code, evaluators, thresholds, secrets, or infrastructure without evaluation and human promotion.

## 2. Evidence from the current systems

### Buddy local repository

- Laravel 13 / PHP 8.5 / `laravel/ai` 0.3.2.
- Two agents with large inline `instructions()` prompts and hard-coded model attributes.
- A custom, sequential stdio JSON-RPC loop advertising MCP protocol `2024-11-05`.
- Direct Qdrant calls, no canonical relational memory record, and no repository abstraction.
- Redis configuration exists, but application queues/cache default to the database and there are no application locks or Redis service in Compose.
- The API has throttling but no caller authentication or tenant/client identity.
- Async evaluation can run more than once: no atomic claim, idempotency key, unique job, overlap lock, or unique run-number constraint.
- Job timeout is 180 seconds while Redis/database `retry_after` defaults to 90 seconds; that can redeliver a still-running job.
- Job exceptions are caught without being rethrown, bypassing ordinary retry/failed-job behavior.
- The development image uses `artisan serve`, installs dependencies at startup, lacks `pdo_pgsql`, and has no production Azure topology.

### Remote `qdrant-memory` repository

Remote `origin/main` at inspected commit `80b824cd8d8e9fac64eb649b01c7ac0d419e4336` is Go-native; the Node runtime has been removed. Its latest inspected CI run passed. It already provides:

- REST, Streamable HTTP MCP, stdio MCP, and an admin UI from one Go broker.
- JWT scopes and project-claim tenancy fences.
- Per-project Qdrant collections, dual dense vectors, optional sparse/hybrid search, reranking, and filtered retrieval.
- Content-addressed memory IDs, revisions, feedback, curation, deduplication, temporal KG, fact checking, confidence, arbitration, and retrieval evaluation.
- Go-native import/export and R2 disaster-recovery tooling.
- In-process per-content-ID write locks and concurrency tests.

Important production limits remain:

- Same-memory read-modify-write locks are process-local. Two hub replicas can race.
- Job, schedule, and token-revocation registries are JSON files unless explicitly relocated.
- Scheduler leadership is process-local.
- A one-replica deployment has a short availability gap during restart/deployment unless writes are drained.

### Azure account read-only inventory

- Existing workload resources are primarily in **North Europe**.
- Container Apps, PostgreSQL, ACR, and Key Vault providers are registered.
- `Microsoft.Cache` is not registered; registration is an explicit provisioning step.
- Two existing PostgreSQL 16 Flexible Servers are burstable `Standard_B1ms`, public-access, 32 GiB, and HA-disabled. They may support a development database, but should not host Buddy production without a separate capacity/security decision.

## 3. Target architecture

```text
External AI agents
  |
  | local MCP stdio bridge or HTTPS REST
  | Authorization: Bearer bdy_live_...
  v
Azure public ingress / optional Front Door + WAF
  |
  v
Buddy API Container App (N stateless replicas)
  |-- PostgreSQL: tasks, runs, prompts, clients, audit, outbox
  |-- Azure Managed Redis: queues, locks, rate limits, hot cache
  |-- AI provider: bounded provider/model concurrency
  `-- enqueue only; long evaluations return 202
            |
            v
Buddy Worker Container App (N replicas, Redis queue scaling)
  |-- prompt compiler + agents
  |-- transactional run/result persistence
  `-- MemoryGateway over private HTTPS
            |
            v
qdrant-memory Go hub (internal ingress, initially exactly 1 replica)
  |-- MiniLM embedding sidecar in the same Container App replica
  |-- JWT project claim: buddy
  |-- small JSON registries on Azure Files during phase 1
  `-- gRPC/TLS + API key
            |
            v
Qdrant Managed Cloud on Azure (same region/geography)
```

Supporting resources:

- Azure Container Registry with immutable commit-SHA image tags.
- Azure Key Vault with managed-identity references.
- Log Analytics + Application Insights / OpenTelemetry.
- VNet integration, private endpoints/private DNS where supported, and controlled egress.
- Container Apps Jobs for database migrations, outbox repair, memory curation, and improvement evaluations.

## 4. Service boundaries and invariants

### Buddy is authoritative for

- API clients, API-key scopes, quotas, revocation, and usage audit.
- Problem packets, task state, run attempts, recommendations, decision logs, artifacts, outcomes, and feedback.
- Prompt source/version/deployment metadata and improvement experiments.
- Idempotency records and transactional outbox messages.
- Which memory IDs influenced each decision.

### The Go hub is authoritative for

- Durable memory content and memory revisions.
- Vector generation/indexing and filtered/hybrid retrieval.
- Memory feedback, stale/archive state, deduplication, arbitration, and KG state.
- Memory tenant isolation through its JWT `project` claim.

### Cross-service invariants

1. Buddy never addresses a Qdrant collection directly in production.
2. Retrieved memory is evidence, never executable instruction or a higher-priority prompt.
3. PostgreSQL commits domain state before work is published through the outbox.
4. Processing is **at least once**; correctness comes from claims, idempotency, unique constraints, and idempotent effects—not an exactly-once claim.
5. No database transaction remains open during an LLM, embedding, Qdrant, or other network call.
6. Redis loss may delay work or reduce cache performance but must not destroy domain truth. The outbox can republish unfinished work.
7. One embedding identity/dimension applies to a memory collection. Model changes require a new collection or explicit reindex migration.
8. Every recommendation records its prompt bundle, model/provider configuration, retrieved memory IDs, and evaluation trace.

## 5. System-prompt architecture

### 5.1 Proper placement

Git is the source of truth for prompt text. The production database is an immutable deployment registry and active-version pointer, not an ad-hoc prompt editor.

Planned layout:

```text
resources/prompts/
  core/
    identity.md
    epistemic-discipline.md
    decision-policy.md
    memory-policy.md
    security-boundaries.md
    output-contract.md
  domains/
    technology-stacks.md
    computer-science.md
    cybersecurity.md
    performance-and-memory.md
  agents/
    evaluator-optimizer.md
    prompt-refiner.md
    improvement-proposer.md
    improvement-evaluator.md
app/Ai/Prompting/
  PromptBundle.php
  PromptCompiler.php
  PromptModuleRouter.php
  PromptRegistry.php
  ContextEnvelope.php
config/buddy_agents.php
```

`EvaluatorOptimizerAgent::instructions()` and `PromptRefinementAgent::instructions()` should delegate to `PromptCompiler`; they should no longer contain the policy text. `buildPrompt()` remains responsible for task data, but should emit a structured `ContextEnvelope` rather than mixing user data with system policy.

### 5.2 Prompt compilation order

Always-on modules:

1. Identity and mission.
2. Epistemic discipline and uncertainty handling.
3. Security/authorization boundaries.
4. Memory trust and provenance policy.
5. Decision process and escalation rules.
6. Required output contract.
7. Agent-specific overlay.

Dynamically selected modules:

- Technology-stack reasoning.
- Computer-science fundamentals.
- Authorized offensive/defensive cybersecurity.
- Memory/performance optimization.

The router should prefer deterministic signals (`problem_type`, tags, requested outcome, tool surface) and use a classifier only when ambiguous. Selected module IDs and hashes must be recorded on the run.

### 5.3 Domain responsibilities

**Technology stacks**

- Verify framework/runtime/library versions before recommending APIs.
- Prefer repository evidence and primary documentation over recollection.
- Distinguish current behavior, version-specific behavior, and migration advice.
- Include compatibility, lifecycle, operational, cost, and rollback implications.

**Computer-science fundamentals**

- State invariants and failure models.
- Analyze time/space complexity and concurrency behavior.
- Prefer simple, falsifiable designs; identify consistency and availability trade-offs.
- Separate correctness requirements from optimizations.

**Cybersecurity**

- Require an owned/authorized scope for active offensive actions.
- Classify advice as passive, active, privileged, destructive, or persistence-changing.
- Default to non-destructive verification and defensive remediation.
- Protect secrets, credentials, tenant boundaries, evidence integrity, and auditability.
- Include threat assumptions, detection opportunities, containment, and recovery.
- Never let retrieved memory expand authorization or override current scope.

**Performance and memory management**

- Measure before optimizing; capture baseline, workload, environment, and variance.
- Examine algorithmic cost, allocations, object lifetime, GC, copies, cache locality, I/O, contention, and backpressure.
- Require reproducible profiling/benchmark evidence.
- Preserve correctness and define rollback thresholds for each optimization.

### 5.4 Grounded-context policy

Repository facts, documentation extracts, tool results, and memories must be passed in delimited context messages with:

- source type and stable ID/URL;
- observed/retrieved timestamp;
- technology/version scope;
- retrieval score and filters;
- provenance/authority and stale status;
- a warning that embedded instructions are untrusted data.

The model must cite memory/source IDs in its structured result, surface conflicts, and prefer current primary evidence over older advice. Secrets and unnecessary source content must be redacted before prompt construction.

### 5.5 Model configuration

Remove hard-coded model choices from agent classes. Resolve provider, model, timeout, max steps, token budget, and temperature from a versioned agent profile. Laravel AI supports prompt-time provider/model/timeout overrides; record the effective values, not only configured defaults.

## 6. Memory architecture and pgvector decision

### 6.1 Recommended data path

Create a Buddy-owned interface:

```text
MemoryGateway
  search(MemoryQuery): MemorySearchPage
  store(MemoryCandidate): MemoryReceipt
  feedback(MemoryFeedback): void
  health(): MemoryHealth
```

Initial implementation: `QdrantHubMemoryGateway`, using the Go hub's REST endpoints and a short-lived, project-scoped JWT. The existing `QdrantMemoryService` becomes a temporary compatibility backend behind the same interface.

Do not silently degrade memory failures to `[]` in production. Return a typed degraded state so the recommendation explicitly reports that memory grounding was unavailable.

### 6.2 Memory lifecycle

1. **Retrieve:** search the Go hub with project isolation and structured filters.
2. **Assess:** use hub ranking/arbitration plus Buddy's task-context checks; do not duplicate the hub's curation logic.
3. **Use:** inject bounded excerpts with provenance into the context envelope.
4. **Reference:** persist the returned memory ID, score, status, revision/embedder identity, and how it influenced the run.
5. **Observe outcome:** collect explicit caller feedback and task-close outcomes.
6. **Candidate generation:** derive a `MemoryCandidate` containing problem, solution/decision, impact/outcome, evidence, technology versions, source references, and tags.
7. **Quarantine:** redact secrets/PII, reject prompt-injection instructions, require minimum evidence, and deduplicate.
8. **Promote:** store through the Go hub only after the quality gate; save the resulting canonical ID in PostgreSQL.
9. **Curate:** send useful/not-useful/stale feedback and use the hub's archive, dedup, alignment, and arbitration workflows.

Live operational facts are queried live and are not promoted as durable memory.

### 6.3 Why pgvector is deferred

PostgreSQL + pgvector is technically valid and Azure Flexible Server supports the `vector` extension. HNSW is usually the better speed/recall starting point; filtered ANN requires iterative scans, partial indexes, or partitioning. However, replacing the inspected Go hub with pgvector would require rebuilding:

- dual-vector/hybrid retrieval;
- content-addressed revisions;
- curation/feedback/deduplication;
- temporal knowledge graph and arbitration;
- import/export and evaluation tooling;
- multi-project memory contracts.

That is high-risk duplication with no demonstrated benefit. PostgreSQL should first solve the transactional problems it is best at.

### 6.4 pgvector reconsideration gate

Reconsider only through an ADR and bake-off using the same corpus and queries. Require measured comparison of Precision@k, Recall@k, MRR, p50/p95 latency, filtered recall, ingestion cost, operational cost, backup/restore, and tenant isolation. If pgvector wins materially, migrate behind `MemoryGateway`; never dual-write indefinitely.

## 7. Controlled recursive self-improvement

Call the production capability **Controlled Improvement Loop (CIL)** to distinguish it from unrestricted runtime self-modification.

### 7.1 Inputs

- Immutable task/run/recommendation records.
- Prompt bundle and agent-profile versions.
- Tool calls, memory references, latency, errors, tokens, and cost.
- Caller/human outcome labels and corrections.
- Frozen golden cases, recent replay cases, and adversarial/security cases.

### 7.2 Loop

1. Detect a bounded improvement opportunity after a minimum evidence threshold.
2. Generate one or more candidate changes in an isolated job.
3. Store candidates as data with parent version, rationale, and expected metric effect.
4. Replay baseline and candidate on frozen and recent suites.
5. Run deterministic schema/invariant checks, prompt-injection tests, cyber authorization tests, and memory-poisoning tests.
6. Compare quality by domain, cost, latency, refusal/escalation behavior, and regressions.
7. Reject candidates that modify the evaluator, thresholds, holdout set, approval policy, or their own evidence.
8. Require human approval for promotion.
9. Shadow or canary the candidate to a small percentage of eligible tasks.
10. Promote an immutable version or atomically restore the previous active pointer.

### 7.3 Hard bounds

- Maximum candidate generations and evaluation budget per cycle.
- No direct repository write, merge, Azure deployment, secret access, or production prompt mutation.
- No runtime recursive Buddy spawning.
- Improvement proposer and evaluator use separate prompts; high-impact promotions require a human decision.
- Holdout cases are inaccessible to the proposer.
- Every lineage and promotion decision is auditable.

Memory improvement follows a separate quarantine → dedup/conflict → alignment check → promotion path. Model-generated advice is never automatically canonical.

## 8. Concurrency, queues, and state transitions

### 8.1 Request flow

1. Authenticate API key and derive client/project/scopes.
2. Apply Redis-backed per-key rate and concurrency limits.
3. Require `Idempotency-Key` on mutating submissions.
4. In one PostgreSQL transaction, create or return the existing task and append an outbox event.
5. An outbox relay publishes the job to Redis after commit.
6. Return `202 Accepted` with task ULID and polling location.
7. A worker atomically claims the task, creates a run attempt, and performs network work outside a transaction.
8. Persist the result and terminal state transactionally; acknowledge the queue job afterward.

### 8.2 Database correctness

Add or enforce:

- unique `(api_client_id, idempotency_key)`;
- unique `(buddy_task_id, operation, attempt_number)` or equivalent run identity;
- a task `state_version`, `claimed_by`, `lease_expires_at`, and heartbeat;
- atomic `UPDATE ... WHERE status IN (...) AND lease is available` claims;
- terminal-state transition guards;
- recommendation uniqueness per successful run;
- outbox message uniqueness and processed timestamps.

Do not use `runs()->count() + 1` outside a locked transaction.

### 8.3 Queue controls

Use a shared Redis cache/queue backend so Laravel locks work across replicas:

- `ShouldBeUniqueUntilProcessing` to collapse duplicate publication.
- `WithoutOverlapping("buddy:task:{ulid}")->shared()` as defense in depth.
- Database claim/lease as the final correctness boundary.
- Retry only transient provider/network/rate-limit failures; fail validation/auth errors immediately.
- Rethrow failures so Laravel records retries and failed jobs.
- Use bounded exponential backoff and honor provider `Retry-After`.

Initial timing invariant:

```text
provider HTTP timeout < job timeout < worker/Horizon timeout < Redis retry_after
example: 120s < 180s < 210s < 240s
```

Tune from observed model latency. Graceful shutdown must exceed the longest admitted job or release its lease safely.

### 8.4 Scaling model

- Buddy API: HTTP concurrency scaling, minimum 1 production replica, stateless.
- Buddy workers: separate no-ingress Container App, Redis queue-depth scaling, fixed bounded processes per replica. Avoid two competing aggressive autoscalers.
- Maintenance/CIL: dedicated low-priority queue or scheduled Container Apps Jobs.
- Apply a Redis semaphore per provider/model and per API client so adding workers cannot exceed provider quotas.
- Backpressure submissions when queue age or provider saturation exceeds policy.

## 9. Redis design

Use **Azure Managed Redis**, preferably a non-clustered configuration initially if Laravel queue/lock command compatibility has not been proven against clustered mode. Validate `phpredis` TLS and Microsoft Entra token refresh in a spike; if unsupported, use a rotated access credential from Key Vault while retaining private networking and TLS.

Use explicit key prefixes/connections rather than relying on numbered Redis databases:

```text
buddy:queue:{name}
buddy:lock:task:{ulid}
buddy:lock:outbox:{shard}
buddy:limit:client:{id}
buddy:semaphore:model:{provider}:{model}
buddy:cache:memory:{tenant}:{epoch}:{query-hash}
buddy:idempotency:response:{client}:{key}
```

### Cache policy

- Memory search cache: 30–120 seconds, keyed by normalized query, filters, tenant, top-k, hub schema/embedder identity, and a corpus epoch.
- Negative memory results: very short TTL.
- Prompt bundles: cache by immutable content hash; no invalidation needed.
- API-key lookup: short positive cache and shorter revocation-safe cache; revocation publishes invalidation.
- Exact idempotent submission response: bounded by the PostgreSQL idempotency record.
- Final LLM recommendations: do not cache by default.
- Secrets, raw authorization headers, and sensitive prompts: never cache.

Buddy cannot fully invalidate memory caches when other clients write directly to the hub. Keep TTLs short initially. Later, add a hub corpus-version/ETag or change event and include it in cache keys.

Redis is not the source of truth. A flush may lose queued copies and cache entries; the PostgreSQL outbox and task leases must recover unfinished work.

## 10. Authentication and MCP transport

### 10.1 External API keys

Use a format such as:

```text
bdy_live_<public-id>_<256-bit-secret>
```

Store only public ID/prefix and an HMAC-SHA-256 digest using a Key Vault-held pepper. Compare in constant time. Show the secret once. Each key has:

- API client/project;
- scopes (`tasks:write`, `tasks:read`, `memory:read`, `memory:write`, `admin`);
- rate and concurrency limits;
- expiration and revocation timestamps;
- last-used metadata updated asynchronously;
- rotation overlap and an audit trail.

Accept it as `Authorization: Bearer ...` over TLS. Never place raw keys in logs, database events, URLs, image layers, or general Buddy `.env` mounts.

### 10.2 MCP compatibility

Phase 1 keeps MCP local:

```text
Kiro/agent --stdio--> buddy-mcp-bridge --HTTPS+API-key--> Buddy REST
```

The bridge contains no database/provider credentials. A dedicated mode-0600 env file or OS secret supplies only the endpoint and Buddy key. The current configuration's mount of the whole Buddy `.env` and local database should be removed after cutover.

The bridge maps MCP calls to asynchronous task submissions and status/recommendation polling. It must use an official MCP SDK and negotiate protocol versions rather than hard-code `2024-11-05`.

Phase 2 may expose native Streamable HTTP MCP. Before that, require:

- OAuth 2.1 resource-server behavior rather than treating an API key as spec-complete MCP authorization;
- audience/resource binding and no token passthrough;
- Origin validation and host protections;
- stateless operation unless server-to-client notifications/resumability are genuinely required;
- a shared session/event store if stateful resumability is enabled;
- cross-client isolation and multi-connection tests.

### 10.3 Internal hub identity

Buddy uses a separate Go-hub JWT with least-privilege scopes and `project=buddy`. The hub remains internal-only. Store the hub signing material/token and Qdrant key in Key Vault; do not reuse external Buddy API keys.

## 11. Azure topology

### 11.1 Region gate

Prefer North Europe to align with existing resources **only if** all required SKUs are available there: Container Apps workload profile, PostgreSQL HA, Azure Managed Redis, and Qdrant Managed Cloud on Azure. If Azure Managed Redis or Qdrant is unavailable, place the entire new data plane in West Europe rather than create a latency-sensitive split deployment.

### 11.2 Resource set

Provision through Bicep in environment-specific modules:

```text
infra/azure/
  main.bicep
  modules/network.bicep
  modules/acr.bicep
  modules/key-vault.bicep
  modules/postgres.bicep
  modules/redis.bicep
  modules/container-apps-environment.bicep
  modules/buddy-api.bicep
  modules/buddy-worker.bicep
  modules/memory-hub.bicep
  modules/jobs.bicep
  modules/observability.bicep
  parameters/dev.bicepparam
  parameters/prod.bicepparam
```

Logical resources:

- ACR.
- Log Analytics / Application Insights.
- VNet-integrated Container Apps environment.
- Public Buddy API app.
- Private/no-ingress Buddy worker app.
- Internal Go memory-hub app with MiniLM sidecar.
- Migration, outbox-repair, curation, and CIL jobs.
- Dedicated PostgreSQL Flexible Server/database with private access, backups, and production HA as required.
- Azure Managed Redis with private endpoint and TLS.
- Key Vault and managed identities.
- NAT/fixed egress if Qdrant/provider IP allow-listing is used.
- Qdrant Managed Cloud subscription/cluster via Azure Marketplace, supplied to IaC as an external endpoint/key reference if its lifecycle is not Bicep-managed.

Registering `Microsoft.Cache`, creating private DNS/network resources, or modifying existing PostgreSQL are infrastructure changes and require explicit approval.

### 11.3 Go hub deployment constraints

Initial settings:

- `HUB_TENANCY=multi`.
- Exactly one active replica, minimum and maximum both 1.
- MiniLM sidecar in the same replica; set a readiness probe that waits for it.
- Internal ingress only.
- Persist `HUB_JOBS_FILE`, `HUB_SCHEDULES_FILE`, and `HUB_REVOCATION_FILE` beneath `/app/data` on Azure Files.
- Do **not** place Qdrant data on Azure Files.
- Qdrant gRPC/TLS and API key to managed Qdrant.
- Minimum replica 1 to avoid model cold starts.

A one-replica Go hub is acceptable for an initial controlled release if load tests pass and a short restart window is accepted. It is not the final HA posture. Before `maxReplicas > 1`:

1. replace per-ID process locks with a distributed, fencing-token-aware lock or atomic storage operation;
2. externalize jobs/schedules/revocations;
3. separate scheduler leadership or use a singleton job;
4. make all HTTP/MCP state stateless or externally stored;
5. run same-ID multi-replica lost-update tests.

Until then, hub deployment must drain/pause memory writes before switching revisions so old and new processes do not overlap on read-modify-write operations.

### 11.4 Production images and deployment

- Build from clean clones/worktrees pinned to reviewed SHAs; never deploy either dirty local worktree.
- Pin base images and Composer/Go dependencies; generate an SBOM and scan images.
- Install dependencies and compile caches in the image, not at startup.
- Buddy image must include `pdo_pgsql` and `phpredis`.
- Replace `artisan serve` with a production server. Start with a conventional Nginx/PHP-FPM image; benchmark Octane/FrankenPHP separately before adopting long-lived workers.
- Run migrations as a one-shot Container Apps Job using expand/contract migrations. Never suppress migration failures.
- Deploy images by immutable SHA, use revision traffic controls, and restart queue workers gracefully.

## 12. Data-model changes

New tables/entities:

- `api_clients`, `api_keys`, and API-key audit events.
- `idempotency_records`.
- `outbox_messages` and optional consumer inbox.
- `prompt_versions`, `prompt_deployments`, and `agent_profiles`.
- `task_feedback` / `task_outcomes`.
- `improvement_candidates`, `evaluation_suites`, `evaluation_runs`, and `promotion_decisions`.
- `memory_candidates` for quarantine before hub promotion.

Existing changes:

- Tasks: client/project, operation, state version, claim owner/lease, idempotency link.
- Runs: attempt number, run type, provider/model, prompt version/hash/modules, tool trace, token usage, cost, timing, error class, candidate/baseline lineage.
- Recommendations/refinements: persist the complete refinement result instead of converting away normalized task, final prompt, constraints, and verification plan.
- Memory references: replace Qdrant-specific naming with backend-neutral `memory_id`, backend, project, score, revision/embedder identity, status, provenance, and use rationale.

## 13. Observability, SLOs, and operations

Propagate one trace/correlation ID through API request, task, outbox message, queue job, LLM calls, MemoryGateway, Go hub, and Qdrant. Log structured metadata, not raw secrets or unrestricted prompt content.

Required metrics:

- API latency/status by client and operation.
- Authentication failures, rate-limit events, and active-key counts.
- Queue depth, oldest age, retries, failures, claim/lease recovery, and duplicate-claim rejection.
- LLM latency, error class, token usage, cost, and provider throttling.
- Memory search latency, result count, degraded calls, cache hit rate, and referenced-memory outcomes.
- Go hub/Qdrant latency, embedding readiness, write conflicts, scheduler/job failures, and collection counts.
- PostgreSQL connections/locks/storage and Redis memory/evictions/latency.
- Prompt quality by version/domain plus CIL promotion/rollback events.

Initial release objectives, refined after load testing:

- zero duplicate successful recommendations for one idempotency key;
- zero cross-client/project data exposure;
- API submission p95 under 500 ms, excluding evaluation;
- queue oldest-age alert before the user-facing latency objective is breached;
- no retrieval-quality regression against the frozen baseline;
- tested PostgreSQL and memory restore procedures;
- explicit degraded-grounding signal when memory is unavailable.

## 14. Migration and rollout sequence

### Phase 0 — freeze contracts and create clean build inputs

- Create clean worktrees/clones for Buddy and `qdrant-memory`; preserve current dirty trees untouched.
- Pin the inspected Go baseline and rerun its CI suite in the clean build context.
- Record ADRs for service boundary, Qdrant-vs-pgvector, external REST/local MCP, Azure region, and production PostgreSQL choice.
- Inventory AI model deployments/quotas and Qdrant/Redis regional availability.

**Gate:** no production deployment from uncommitted local state.

### Phase 1 — Buddy correctness and security

- Introduce `MemoryGateway` and compatibility adapters.
- Add API-key middleware/scopes, idempotency, state-machine claims, run uniqueness, and outbox.
- Move queues/cache/rate limiting/locks to Redis.
- Correct timeout ordering and job exception/retry behavior.
- Add health/readiness endpoints and remove exception details from public 500 responses.
- Modularize/version prompts and capture effective run configuration.

**Gate:** concurrency, auth, idempotency, and prompt-injection suites pass against PostgreSQL + Redis.

### Phase 2 — deploy the Go memory plane in staging

- Provision Qdrant Managed Cloud on Azure in the selected region.
- Build remote `qdrant-memory` `origin/main` from a clean clone.
- Add a `buddy` project profile and issue a project-scoped hub token.
- Deploy the one-replica internal hub + embedding sidecar.
- Configure persistent registry paths, probes, backup, and alerts.
- Run Go integration, tenancy, concurrency, golden retrieval, and restore checks.

**Gate:** no direct Qdrant access from Buddy staging; hub load/restore tests pass.

### Phase 3 — migrate Buddy memory

- Export the legacy `buddy_episodes` collection without deleting it.
- Define and review a deterministic transform from legacy summary/payload fields to the hub's problem/solution/impact schema; preserve legacy IDs and timestamps as provenance.
- Dry-run import, sample records, then backfill.
- Store old-to-new ID mappings in PostgreSQL.
- Run shadow searches against both backends and compare Precision@k, Recall@k, MRR, and latency.
- Enable temporary dual-write only if needed, with an outbox and explicit per-backend status. Avoid blind write retries because repeated content-addressed stores may append revisions.
- Switch reads through a feature flag; keep the old collection read-only for the rollback window.

**Gate:** count/checksum reconciliation and retrieval thresholds pass; no silent empty-memory fallback.

### Phase 4 — Azure staging for Buddy

- Provision ACR, Container Apps, dedicated PostgreSQL, Managed Redis, Key Vault, networking, and observability through Bicep.
- Deploy migration job, API, workers, and maintenance jobs.
- Configure secrets by Key Vault reference and identities/RBAC.
- Run API/MCP bridge end-to-end tests and load tests with realistic provider quotas.
- Perform Redis loss/outbox recovery, hub outage, provider 429, and worker-kill tests.

**Gate:** restore drill, security review, budget alerts, and acceptance SLOs pass.

### Phase 5 — production canary and cutover

- Create production API keys with least privilege.
- Route a small set of agents to the online Buddy endpoint.
- Compare quality, latency, cost, queue age, cache behavior, and memory outcomes.
- Increase traffic gradually; retain the local configuration and old memory read path during the rollback window.
- After stability, replace the current full Buddy Docker MCP invocation with the thin bridge.

### Phase 6 — controlled improvement

- Start CIL in report-only mode.
- Build frozen domain/security/retrieval suites and human promotion workflow.
- Enable prompt shadowing, then a small canary; never begin with automatic promotion.

### Phase 7 — HA hardening

- Externalize Go hub state and implement cross-replica write serialization/leader election.
- Test multi-replica same-ID writes and revision deployments.
- Increase hub replicas only after those gates pass.

## 15. Validation matrix

- **Unit:** state transitions, API-key verification/scopes, prompt compilation/hashes, cache keys, retry classification, memory DTO mapping.
- **PostgreSQL integration:** constraints, atomic claims, leases, outbox publication, duplicate idempotency submissions.
- **Redis integration:** atomic locks, overlap behavior, rate limits, provider semaphores, queue redelivery, cache invalidation.
- **Memory contract:** search/store/feedback/degraded behavior against the exact Go-hub version.
- **Concurrency:** same task from many agents, duplicate queue delivery, worker death, lease expiry, same-memory writes.
- **Security:** cross-client and cross-project isolation, key rotation/revocation, log redaction, prompt injection through evidence/memory, SSRF/egress controls, cyber authorization boundaries.
- **MCP:** initialize/version negotiation, tool schemas, async lifecycle, malformed requests, stdio shutdown, no secrets in output.
- **Retrieval:** fixed corpus and Precision@k/Recall@k/MRR/Hit Rate regression gates.
- **Load:** API and worker scaling under real provider quota, Redis/DB connection pressure, embedding sidecar saturation.
- **Chaos/DR:** Redis flush, worker termination, hub restart, Qdrant timeout, PostgreSQL failover, restore from backups.
- **Deployment:** migration job, readiness/liveness, revision rollback, queue drain, old-client compatibility.

## 16. Rollback strategy

- **Application:** Azure Container Apps revision rollback by immutable image SHA.
- **Database:** expand/contract migrations; do not remove old columns/tables until the rollback window closes.
- **Prompts:** atomic active-version pointer back to the previous immutable prompt bundle.
- **Memory:** `MEMORY_BACKEND=legacy|hub|shadow`; retain legacy collection read-only and ID mappings.
- **Queues:** pause workers, recover from PostgreSQL outbox/leases, then republish.
- **Go hub:** pin previous image; pause/drain writes during one-replica revision switching.
- **CIL:** disable candidate routing and restore baseline immediately; candidates never overwrite baseline records.
- **Keys:** overlap during rotation; revoke compromised IDs without changing unrelated clients.

## 17. Principal risks and required decisions

| Risk/decision | Recommended default | Gate |
|---|---|---|
| Qdrant or pgvector | Keep Go/Qdrant; PostgreSQL transactional only | Reconsider only after a retrieval/ops bake-off |
| Qdrant hosting | Managed Qdrant on Azure via Marketplace | Confirm region, networking, backups, and cost |
| Go hub availability | One replica initially | Accept restart window; distributed locks/state before HA |
| Azure region | North Europe if every dependency is available | Otherwise move all new data-plane resources to West Europe |
| PostgreSQL | New dedicated/private production server | Do not reuse public B1ms instances as-is |
| Redis | Azure Managed Redis | Register provider; validate North Europe and `phpredis` auth/cluster behavior |
| MCP remote auth | REST API key + local stdio bridge first | Native HTTP MCP only with OAuth 2.1/audience binding |
| AI provider | Provider abstraction; select by quota/region/eval | Existing East US AI resources need latency/data-residency review |
| CIL promotion | Human-gated | Define named approver and quality/security thresholds |
| Data policy | Minimize/redact and retain by class | Define tenant, PII, security-data, and retention requirements |

## 18. Documentation sources

- [Laravel 13 queues](https://laravel.com/docs/13.x/queues)
- [Laravel 13 cache and atomic locks](https://laravel.com/docs/13.x/cache)
- [Laravel Horizon](https://laravel.com/docs/13.x/horizon)
- [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk)
- [pgvector project documentation](https://github.com/pgvector/pgvector)
- [Azure PostgreSQL pgvector performance](https://learn.microsoft.com/en-us/azure/postgresql/extensions/how-to-optimize-performance-pgvector)
- [MCP Streamable HTTP transport, 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
- [MCP authorization, 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
- [MCP security best practices](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices)
- [Azure Container Apps scaling](https://learn.microsoft.com/en-us/azure/container-apps/scale-app)
- [Azure Container Apps health probes](https://learn.microsoft.com/en-us/azure/container-apps/health-probes)
- [Azure Container Apps Key Vault secret references](https://learn.microsoft.com/en-us/azure/container-apps/manage-secrets)
- [Azure Container Apps storage mounts](https://learn.microsoft.com/en-us/azure/container-apps/storage-mounts)
- [Azure Managed Redis security](https://learn.microsoft.com/en-us/azure/redis/secure-azure-managed-redis)
- [Azure Cache for Redis retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq)
- [Azure PostgreSQL business continuity](https://learn.microsoft.com/en-us/azure/postgresql/flexible-server/concepts-business-continuity)
- [Qdrant Managed Cloud](https://qdrant.tech/documentation/cloud/)
- [Qdrant installation/storage requirements](https://qdrant.tech/documentation/install/)
- [Qdrant on Azure Marketplace](https://marketplace.microsoft.com/en-us/product/saas/qdrantsolutionsgmbh1698769709989.qdrant-db)

Content was rephrased for compliance with licensing restrictions.
