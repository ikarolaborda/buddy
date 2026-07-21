# ADR 0001: Buddy and the memory hub remain separate services

**Status:** Accepted — 2026-07-21

## Decision

Buddy (Laravel) owns decision orchestration, prompt policy, task/run state, API clients,
feedback, and audit records. The Go `qdrant-memory` hub owns governed long-term vector
memory: retrieval, curation, arbitration, and knowledge-graph features. Buddy talks to the
hub only through its authenticated REST interface via `MemoryGateway`; it never addresses a
Qdrant collection directly in production.

## Rationale

The hub already provides tenancy fences, content-addressed revisions, hybrid retrieval,
deduplication, and evaluation tooling. Reimplementing any of that in Buddy duplicates a
mature data plane and splits authority over memory correctness.

## Consequences

- `MemoryGateway` (`app/Contracts/MemoryGateway.php`) is the only memory seam; backends are
  swappable via `BUDDY_MEMORY_BACKEND` (`legacy` | `hub` | `shadow`).
- Memory failures surface as typed degraded states, never as silent empty results.
- The hub runs one replica until distributed locks and externalized registries land (ADR gate
  in plan §11.3 / Phase 7).
