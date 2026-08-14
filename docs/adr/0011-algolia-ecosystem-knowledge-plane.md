# ADR 0011: Algolia ecosystem knowledge plane

Status: accepted

## Context

Buddy needs fresh, exact repository knowledge across Aerolambda, Buddy, Theravista, Falinha, and Ritmovida. Qdrant remains the governed store for episodic memories and outcomes; it is not an ideal source for current paths, API operations, configuration keys, or code signatures.

The Algolia application is shared by the ecosystem. Buddy's evaluation latency and availability must not depend on Algolia.

## Decision

Use one private index per product and environment:

- `<env>_internal_aerolambda_knowledge`
- `<env>_internal_buddy_knowledge`
- `<env>_internal_theravista_knowledge`
- `<env>_internal_falinha_knowledge`
- `<env>_internal_ritmovida_knowledge`

The scheduled/manual indexing workflow extracts documentation, ADRs, API and configuration contracts, test names, and code symbol signatures. It excludes full source bodies, dependency/build directories, local environment files, lockfiles, and key material. Every record carries repository, revision, source path, visibility, status, and a stable canonical identifier. Product indices are replaced atomically only after all repositories for that product have been extracted.

Extraction is an object pipeline, not a procedural script: immutable source/chunk/record value objects move through a repository scanner, path and content policies, strategy implementations for each source type, and a record factory. The console command delegates index planning, Git metadata discovery, manifest writing, settings, and publishing to dedicated collaborators.

Task submission writes an outbox event for a background prefetch. The job makes one analytics-free multi-index query using a restricted search-only key and persists a bounded snapshot on the task. Evaluation never calls Algolia: it consumes the snapshot only when its status is `ready`, combines it with the Qdrant page already fetched by the evaluator, and caps the complete grounding block. A missing, slow, or failed Algolia request therefore cannot add evaluation latency or block readiness.

Recommendations store only verified citations that resolve to the supplied snapshot. Decision logs include snapshot status, query hash, and cited record IDs.

## Key separation

- Runtime: search-only key restricted to `<env>_internal_*_knowledge`, with query parameters enforcing `visibility:internal AND status:current` where supported.
- Index workflow: write key restricted to the same index pattern; it is available only as a GitHub Actions secret.
- Never expose either key to browsers or mobile clients.

## Activation

1. Add `ECOSYSTEM_REPOSITORY_TOKEN`, `ALGOLIA_APPLICATION_ID`, and the restricted `ALGOLIA_WRITE_API_KEY` to the Buddy repository secrets.
2. Run the workflow manually against `staging` and inspect record counts and sample searches.
3. Configure the Buddy runtime with `ALGOLIA_APPLICATION_ID`, a restricted `ALGOLIA_SEARCH_API_KEY`, `BUDDY_KNOWLEDGE_DRIVER=algolia`, and `BUDDY_ALGOLIA_INDEX_ENV=staging`.
4. Enable `BUDDY_ALGOLIA_PREFETCH_ENABLED=true` while leaving context disabled; observe `ready`/`degraded` rates and queue duration.
5. Enable `BUDDY_ALGOLIA_CONTEXT_ENABLED=true`, then promote the same sequence to production.
6. Set the repository variable `ALGOLIA_INDEX_ENVIRONMENT=production` only after production promotion; scheduled runs default to `staging` until then.

The feature defaults off, is not part of health/readiness, and can be disabled without affecting Qdrant memory or evaluation.
