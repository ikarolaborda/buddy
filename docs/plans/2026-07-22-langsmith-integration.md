# LangChain / LangSmith Integration Plan for Buddy

**Status:** Complete (L1 + L2 shipped and live-verified; L3 intentionally not started per §2)
**Date:** 2026-07-22

Delivery notes: L1 tracing live since `78a5cb9` (deployed trace `73c53778`). L2 datasets
live since `4c8e270` (dataset `601f88a6`); replay engine + human promotion gate close the
loop — first real replay caught a genuine regression (stricter-evidence candidate dropped
golden accuracy 1.0 → 0.5, experiment `15693111…`) and a live provider incompatibility
(gpt-5.4 rejects the `temperature` parameter; attributes removed). Promotion decisions go
through `buddy:cil-decide` and are attributable by name.
**Prerequisites:** `LANGSMITH_API_KEY` in Key Vault (`langsmith-api-key`) and wired into
`ca-buddy-api-dev` / `ca-buddy-worker-dev` alongside `LANGSMITH_ENDPOINT`,
`LANGSMITH_PROJECT`, `LANGSMITH_TRACING` (done; also in Bicep so IaC runs preserve it).

## 1. The honest constraint

Buddy is PHP/Laravel on `laravel/ai`. LangChain has no PHP runtime — "incorporating
LangChain" cannot mean running LangChain chains inside Buddy's process. What IS available
to a PHP service, per current LangSmith docs:

1. **OTLP trace ingestion** — LangSmith accepts OpenTelemetry traces at
   `https://api.smith.langchain.com/otel/v1/traces` with headers
   `x-api-key: <LANGSMITH_API_KEY>` and `Langsmith-Project: <project>`. Language-agnostic.
2. **REST runs API** — direct run-tree creation over HTTP, used by the Java/OTel examples;
   no SDK required.
3. **Datasets + experiments API** — evaluation suites, also plain REST.

LangChain-proper capabilities (chains, LangGraph, prompt hub) require a Python/JS process.

## 2. Recommended scope, in three phases

### Phase L1 — Observability (recommended first, small)

Trace every Buddy agent run into LangSmith. Buddy already records the raw material on
`buddy_runs` (provider, model, prompt hash, module list, timing, token usage, error class),
and `EvaluatorOptimizerService::executeRun()` is the single choke point for every
evaluation/refinement.

- Add `app/Services/Observability/LangSmithTracer.php`: builds a run tree
  (root run = Buddy task evaluation; child runs = LLM call, memory search, tool calls)
  and POSTs OTLP-or-runs-API payloads. Feature-flagged by `LANGSMITH_TRACING`;
  fire-and-forget with short timeout so tracing can never fail an evaluation.
- Instrument `executeRun()` + `HubMemoryGateway::search/store` (memory spans carry
  memory IDs and degraded state — LangSmith becomes the place to see grounding quality).
- Redaction: reuse the plan §5.4 policy — prompt content is sent only when
  `LANGSMITH_SEND_PROMPTS=true`; default sends hashes, module IDs, token counts, and
  outcome metadata. Secrets never leave (pepper, keys are not part of run payloads).
- Tests: `Http::fake` contract tests like `HubMemoryGatewayTest`.

Deliverable: every dev evaluation visible as a LangSmith trace under project `buddy-dev`.

### Phase L2 — CIL evaluation backend (high leverage)

The Controlled Improvement Loop (plan §7) needs frozen suites, replay, and comparison —
exactly LangSmith's datasets/experiments model.

- Map `evaluation_suites` → LangSmith datasets (suite cases pushed via REST).
- Map `evaluation_runs` → LangSmith experiments; store the experiment URL on the run.
- `buddy:cil-report` gains LangSmith links; promotion decisions stay human-gated in Buddy.
- Buddy remains the source of truth (plan §4 invariants); LangSmith is the measurement
  and visualization plane.

### Phase L3 — Optional LangChain sidecar (only if L1/L2 create the need)

If LangChain-only capabilities become necessary (LLM-as-judge evaluators from the
LangChain ecosystem, prompt hub, LangGraph flows), run them as a small Python FastAPI
sidecar Container App — same pattern as the MiniLM embedding sidecar: internal ingress,
REST contract defined in Buddy, called through a `LangChainGateway` mirroring
`MemoryGateway` (typed degraded state, never silent failure). This is a separate
approval-gated deployment; do not start here.

## 3. Explicit non-goals

- No LangChain runtime inside PHP (impossible) and no rewrite of Buddy agents to Python.
- No replacement of the Go memory hub — LangSmith traces reference memory IDs, it does
  not store memories.
- No automatic CIL promotion because a LangSmith experiment "looks better" — §7.3 bounds
  stand.

## 4. Environment contract (live in dev)

| Var | Source | Purpose |
|---|---|---|
| `LANGSMITH_API_KEY` | KV `langsmith-api-key` (secretref) | auth (`x-api-key`) |
| `LANGSMITH_ENDPOINT` | env | API base, default SaaS |
| `LANGSMITH_PROJECT` | env (`buddy-dev` / `buddy-prod`) | trace project routing |
| `LANGSMITH_TRACING` | env | kill switch for the tracer |
| `LANGSMITH_SEND_PROMPTS` | env (Phase L1, default false) | content-redaction toggle |

## 5. Risks

- Prompt/PII egress to LangSmith SaaS — mitigated by default-off content sending and §5.4
  redaction; revisit for any client data classification.
- Tracing latency in the hot path — mitigated by fire-and-forget + timeouts ≤2s; a failed
  trace must never fail or delay an evaluation.
- Vendor coupling in CIL — mitigated by keeping suites/decisions in PostgreSQL; LangSmith
  is projection, not truth.
