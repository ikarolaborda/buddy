# ADR 0004: Region selection is gated on full-dependency availability

**Status:** Accepted — 2026-07-21 (region confirmation pending provisioning approval)

## Decision

Prefer North Europe to align with existing resources **only if** every required SKU is
available there: Container Apps workload profiles, PostgreSQL Flexible Server HA, Azure
Managed Redis, and Qdrant Managed Cloud via Azure Marketplace. If any dependency is missing,
place the **entire** new data plane in West Europe. A latency-sensitive split deployment
across regions is not acceptable.

## Notes

- `Microsoft.Cache` is not registered in the subscription; registration is an explicit,
  approval-gated provisioning step.
- Azure Cache for Redis is on a retirement path; only Azure Managed Redis is used.
- Existing East US AI resources need a latency/data-residency review before production use.
