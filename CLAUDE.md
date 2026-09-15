# Buddy — Claude Code Project Instructions

## Current handoff and production target

Read the [2026-09-15 Cloudflare handoff](docs/handoffs/2026-09-15-cloudflare-buddy.md)
and [implementation plan](docs/plans/2026-09-15-cloudflare-buddy-usability-performance.md) before resuming this work.
The plan covers all six Cloudflare capabilities and the Redis autoscaling fault.
Its proposed features are not deployed behavior.

Run real evaluations through the deployed Azure Buddy at `https://buddy.aerolambda.tech/api/mcp`.
Do not use local or Pointerpro Buddy containers for real evaluations.
Local tests can use AI and HTTP fakes.
The Azure council uses Astra `xhigh` as chairman and three GPT-5.5 `high` reviewers.
Keep Azure as the default and `BUDDY_COUNCIL_WORKERS_AI_SOL=false` under the credit-only constraint.
Search shared memory with project `buddy` and query `Cloudflare implementation plan September 15 2026 P0 P8`.

## What This Is

Buddy is an evaluator-optimizer sidecar agent for engineering workflows. It is called by primary coding agents (Claude, Cursor, Copilot, etc.) when work becomes slow, ambiguous, or repeatedly unsuccessful.

Buddy exposes MCP tools and an authenticated REST API from Azure Container Apps.
It uses PHP 8.5, Laravel, allowed Azure model deployments, and the governed memory hub.
The [council and interventions guide](docs/recipes/azure-council-and-interventions.md) describes the current operational behavior.
The Cloudflare edge work (branch `ikaro/cloudflare-p0-p8`) is documented in
[ADR 0012](docs/adr/0012-worker-autoscaling-signal.md) (worker autoscaling signal),
[ADR 0013](docs/adr/0013-bounded-browser-diagnostics.md) (bounded browser diagnostics),
[redis-autoscaling-repair.md](docs/recipes/redis-autoscaling-repair.md),
[cloudflare-edge-rollout.md](docs/recipes/cloudflare-edge-rollout.md) (flags, budgets, rollback),
[cloudflare-edge-deployment.md](docs/recipes/cloudflare-edge-deployment.md) (Worker package under `cloudflare/buddy-edge/`)
and the [evidence manifest](docs/releases/2026-09-15-cloudflare-evidence-manifest.md).
Every `BUDDY_EDGE_*` flag ships false; PostgreSQL stays the only authority for tasks, claims, leases and recovery.
The production architecture is described in docs/plans/2026-07-21-buddy-production-sidecar-architecture.md and docs/adr/.

## Stack

- Laravel 13.x, PHP 8.5+
- laravel/ai v0.3.2 (agents, structured output, tools, embeddings, testing fakes)
- Qdrant through the governed Go memory hub (episodic memory, semantic search)
- SQLite (dev) / PostgreSQL (prod)
- Azure Container Apps (production API and worker), Docker for builds and local development

## Commands

Local development commands do not change the production evaluation target.
Do not reset a database or replace an archive without explicit authorization for that database.

- `composer dev` — Start dev server + queue + logs + vite
- `php artisan test` — Run test suite
- `./vendor/bin/pint` — Format code
- `php artisan buddy:mcp-server` — Start MCP stdio server
- `php artisan buddy:client:create <name>` — Create an API client and issue a key
- `php artisan buddy:outbox-relay --once` — Republish unprocessed outbox messages
- `php artisan buddy:outbox-replay --dry-run` — List or replay remote (Cloudflare) outbox deliveries; `--list-quarantined` shows rejected unknown topics
- `php artisan buddy:queue:synthetic --count=30 --seconds=90 --confirm` — Bounded no-inference backlog for the autoscaling proof (P0)
- `php artisan buddy:artifacts:cleanup --dry-run` — Expire abandoned uploads, release quota, purge past retention (P5)
- `php artisan buddy:cil-report` — Report-only Controlled Improvement Loop metrics
- `php artisan buddy:cil-sync-suites` — Sync CIL suites to LangSmith datasets
- `php artisan buddy:cil-replay <candidate> <suite>` — Replay baseline vs candidate prompts
- `php artisan buddy:cil-decide <candidate>` — Record a human promotion decision
- `php artisan migrate:fresh` — Reset an explicitly authorized disposable development database
- `docker compose build` — Build Docker image
- `docker compose up -d` — Start app + queue worker + redis
- `bin/buddy-mcp-bridge` — Thin stdio-to-HTTPS MCP bridge (needs BUDDY_BASE_URL + BUDDY_API_KEY)

## Code Conventions

- PSR-12, single quotes, trailing commas in multiline arrays
- Laravel 13 PHP attributes: `#[Middleware]`, `#[Tries]`, `#[Timeout]`, `#[FailOnTimeout]`
- Typed properties and return types everywhere
- Early returns, happy-path-last
- DTOs as readonly classes in `app/DTOs/`
- Enums as backed enums in `app/Enums/`
- AI agents in `app/Ai/Agents/`, tools in `app/Ai/Tools/`
- Services in `app/Services/`, MCP tools in `app/Mcp/Tools/`
- Form requests for validation, API resources for response formatting
- Laravel Pint for formatting — run before committing

## Testing

- PHPUnit 12.x with `laravel/ai` fakes
- `Agent::fake()` requires PHP **arrays** (not JSON strings) for structured output agents
- All OpenAI structured output schema fields must have `->required()` (OpenAI strict mode)
- Local tests use SQLite and fakes. PostgreSQL suites cover database-specific concurrency and constraints.

## Key Architecture Decisions

- Two AI agents: `EvaluatorOptimizerAgent` (code evaluation) and `PromptRefinementAgent` (task refinement)
- Single escalation hop only — Buddy never spawns other Buddy instances
- Production memory uses the governed Go hub through `MemoryGateway`.
- Production MCP uses native Streamable HTTP. The Artisan stdio server is for local development.
- Keep deployment secrets in their secret stores. Preserve local `.env` files and database archives.

## MCP Configuration

Agents consume the deployed Buddy over native Streamable HTTP MCP with a bearer
key (ADR 0006). Follow `docs/recipes/remote-machine-onboarding.md`; the short
form:

```bash
claude mcp add --scope user --transport http buddy "$BUDDY_URL" \
  --header "Authorization: Bearer $BUDDY_API_KEY"
```

The legacy local Docker stdio invocation (mounting `.env` and the SQLite
database into a `buddy-app` container) is retired; do not re-add it. The stdio
fallback for environments without HTTP MCP support is `bin/buddy-mcp-bridge`
with `BUDDY_BASE_URL` and `BUDDY_API_KEY` (ADR 0003).

## Commenting Rules

Default to no comments. Code should read like it was written by a careful senior engineer.

**Forbidden:** Line comments that narrate code (`// Set the user id`, `// Return the response`, `// Loop through items`). These are AI-comment noise and a quality smell.

**Allowed only when:** The code alone cannot communicate the reasoning — business rules, non-obvious constraints, compatibility quirks, security decisions, surprising edge cases, intentional deviations.

**Style:** Prefer one concise block comment above a section over scattered inline comments. Explain **why**, not **what**. No docblocks unless required by tooling.

**Review check:** Before committing, ask: can this comment be eliminated by renaming or restructuring? Does it explain why, not what? Would a strong human reviewer keep it?

## Git

- Author: configured per-repo (not global)
- `.serena/` excluded via `.git/info/exclude`
- `.agent/` excluded via `.gitignore`
- Never commit `.env` or `database/database.sqlite`
