# ADR 0002: Keep Go/Qdrant for vectors; PostgreSQL stays transactional-only

**Status:** Accepted — 2026-07-21

## Decision

PostgreSQL becomes Buddy's transactional source of truth (tasks, runs, prompts, clients,
audit, outbox). pgvector is **not** added now. The Go hub + Qdrant remain the vector data
plane.

## Rationale

pgvector on Azure Flexible Server is technically valid, but replacing the hub would require
rebuilding dual-vector hybrid retrieval, content-addressed revisions, curation/feedback/
dedup, the temporal knowledge graph, arbitration, and import/export tooling — high-risk
duplication with no demonstrated benefit.

## Reconsideration gate

Only through a new ADR backed by a bake-off on the same corpus and queries measuring
Precision@k, Recall@k, MRR, p50/p95 latency, filtered recall, ingestion and operational
cost, backup/restore, and tenant isolation. If pgvector wins materially, migrate behind
`MemoryGateway`; never dual-write indefinitely.
