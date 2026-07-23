# ADR 0010: Caller-curated context boundary

**Status:** Accepted — 2026-07-23

## Context

Buddy never reads repositories: `repo` and `branch` are plain string labels
on the problem packet, and all code context arrives as text the caller
chooses to pass — evidence entries and artifacts (diffs, logs, test
output). The 2026-07-23 gap analysis flagged this as a potential product
gap ("no repo/diff awareness") and noted the council packet silently
truncated artifacts at a hardcoded 4,000 characters. The alternative —
Buddy cloning repos or reading files — would require distributing repo
credentials to a shared sidecar that today holds none.

## Decision

The caller-curated boundary is deliberate and stays:

1. **Buddy holds no repo credentials and never reads code itself.** The
   consuming agent — which already has the repository open and knows what
   is relevant — curates the context into evidence and artifacts. Buddy's
   verdicts are honest about this: the council's ceiling is already
   "best surviving explanation of the testimony provided" (ADR 0009),
   and the same epistemics apply to single-model evaluation.
2. **The truncation budget is explicit and configurable.** The council
   packet's per-artifact cap is now `BUDDY_COUNCIL_ARTIFACT_CHARS`
   (default 4000) instead of a buried literal. Operators trading tokens
   for context can raise it without a code change.
3. **Callers are told the boundary exists.** Tool schemas describe
   artifacts as the way to supply code context; a caller that pastes too
   little gets a shallower verdict, not an error.

Rejected alternative: repo ingestion (clone/checkout, diff parsing, or a
read-only code-host token). It would break the trust model (a shared
sidecar with credentials for ~20 private repos becomes a high-value
target), couple Buddy to code-host APIs, and duplicate work the calling
agent has already done. It stays rejected unless a concrete need emerges
that curation demonstrably cannot meet.

## Consequences

- Evaluation quality is bounded by caller curation; garbage in, shallow
  verdict out. This is accepted and disclosed rather than hidden.
- No secret sprawl: Buddy's blast radius on compromise remains its own
  data plane, never the callers' source code.
- Larger artifact budgets are an env-var decision with a linear token
  cost in council calls (12 large-model calls per deliberation).
