# ADR 0009: Falsification-first LLM council

**Status:** Accepted — 2026-07-22

## Context

Single-model evaluation (ADR 0008 routing) is fast and cheap but shares one
model's blind spots. For high-stakes or repeatedly-stuck problems the operator
wants a second opinion that is *more objective than a vote*: conclusions must
survive an explicit falsification attempt and be traceable to the evidence the
caller actually supplied. The deliberation protocol adapts the operator's
Master's research on multi-critic verification and falsification-first agent
reasoning (see `docs/plans/2026-07-22-llm-council.md` for the full mapping).

## Decision

A five-member council (GPT-5.6-sol at xhigh reasoning effort, Claude Fable 5,
Claude Opus 4.8, Claude Sonnet 5, Gemini 3.1 Pro) chaired by Claude Fable 5,
all reached through OpenRouter with direct `Http` calls (not `laravel/ai`,
which cannot set `reasoning_effort`, pool per-member requests, or force
`response_format` per call). Protocol:

- **R0 frame (chairman):** claims, hypotheses, and *kill conditions* — each
  hypothesis must state what observation would falsify it, or the round fails.
- **R1 positions (5 members, parallel):** stance per hypothesis with
  `evidence_refs` into the shared packet; uncited reasoning must be flagged
  `reasoning_only`.
- **R2 falsification (parallel, anonymized):** members attack the R1 record
  with authorship replaced by shuffled `Member A–E` aliases, so brand
  deference and self-preference cannot steer the attack.
- **R3 verdict (chairman):** narrates a **binding mechanical tally computed in
  PHP**, not by any model: support fractions, family counts, defeat status,
  and a lexicographic ranking (fewest unanswered challenges, widest family
  spread, highest support).

Constitutional rules enforced in code, not prompts:

1. **Testimony-defeat ceiling.** Everything in the packet (evidence lines,
   artifacts, memory hits) is *testimony*, not verified observation. A defeat
   requires evidence refs that resolve to real packet ids **and** a verbatim
   kill-condition hit; even then the verdict discloses
   `defeat_ceiling: testimony`. LLM-only reasoning may propose falsifiers but
   cannot execute a defeat — the research's Tier 1–3 rule.
2. **Fabricated citations are counted against the hypothesis' supporters**,
   never silently dropped: refs that match no packet id increment
   `fabricated_ref_count` and downgrade the stance to reasoning-only.
3. **`underdetermined` is a normal, honest outcome** (support gap ≤ 0.2 or no
   survivor), shipped with proposed discriminators — the checks a human or
   tool could run to break the tie. It is not an error.
4. **Correlated-family disclosure:** 3 of 5 members are Anthropic models and
   the chairman is also a member model; both facts are disclosed mechanically
   in every verdict rather than assumed away.

Operational shape: explicit invocation only (`buddy.council_evaluate` MCP tool
or `POST /tasks/{task}/council`), never auto-routed and never inline — the job
runs on the worker with `Tries(1)`, `Timeout(900)` under
`REDIS_QUEUE_RETRY_AFTER=1200`, a 1200 s task lease with inter-round
heartbeats, per-round transcript artifacts as crash checkpoints, a per-UTC-day
run cap, and a lease reaper piggybacked on the outbox relay. `OPENROUTER_API_KEY`
lives in Key Vault (prod) or the untracked `.env` (dev) — never in the repo.

## Consequences

- One council ≈ 12 large-model calls, 2–10 minutes, dollars not cents; the
  daily cap and single-attempt policy bound the spend.
- Verdicts can only ever be as strong as the packet: the council cannot run
  tests or read the repo, so its strongest honest claim is "best surviving
  explanation of the testimony provided". Callers get discriminators, not
  false certainty.
- A second provider dependency (OpenRouter) exists only when a council is
  explicitly convened; the evaluate/refine paths are untouched.
