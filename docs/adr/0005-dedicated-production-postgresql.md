# ADR 0005: New dedicated, private PostgreSQL for production

**Status:** Accepted — 2026-07-21

## Decision

Provision a dedicated PostgreSQL 16 Flexible Server for Buddy production: private access
(VNet-delegated subnet + private DNS), zone-redundant HA, geo-redundant backups. The two
existing public, burstable `Standard_B1ms`, HA-disabled servers are **not** reused for
production; at most they may back a development database after a separate capacity/security
decision.

## Rationale

Public network access, burstable capacity, and missing HA make the existing servers
unsuitable for a service whose correctness depends on transactional claims, idempotency
records, and the outbox. Modifying existing servers is an approval-gated infrastructure
change.

## Consequences

- `infra/azure/modules/postgres.bicep` provisions the dedicated server.
- The Buddy production image ships `pdo_pgsql`; expand/contract migrations run as a one-shot
  Container Apps Job that never suppresses failures.
