# LLM Council — falsification-first multi-model deliberation

Date: 2026-07-22 · Status: shipped · ADR: `docs/adr/0009-llm-council.md`

## Goal

Give Buddy a deliberate, expensive, *evidence-grounded* second opinion for
problems where a single evaluation is not enough. The design goal, verbatim
from the operator: "always avoid shallow conclusions and ground every
recommendation into REAL and tangible EVIDENCE."

## Research mapping

The protocol adapts two publications (the operator's Master's research) on
multi-critic verification and falsification-first reasoning. What carried
over, and what deliberately did not:

| Research concept | Council implementation |
| --- | --- |
| Evidence tiers; "LLM-only reasoning may propose falsifiers but cannot execute a defeat" | Packet items are all tier `testimony`. A "testimony-defeat" needs valid packet refs + a verbatim kill-condition hit, and every verdict discloses `defeat_ceiling: testimony`. No council output can claim a Tier 1–3 defeat. |
| Multi-critic support aggregation with adversarial gating | R1 support fractions over respondents + R2 anonymized falsification round; PHP counts, models never self-score the tally. |
| Hard-defeat vs soft-evidence separation | `testimony_defeated` status is separate from low support; a live hypothesis with weak support ranks below a well-supported one but is never called defeated. |
| Dominance / lexicographic ranking | `[unanswered_challenges asc, family_spread desc, support desc]` computed in `adjudicate()`. |
| Output modes: unique_survivor / underdetermined / exhaustion | Same three modes; `underdetermined` (support gap ≤ 0.2) is the expected modal outcome and ships with proposed discriminators. |
| Info-gain test selection | Projected onto `proposed_discriminators`: the council names the checks that would break the tie; executing them stays with the caller. |
| Confidence cap for unverified claims | Chairman confidence is clamped by the tally; a narrated "high" cannot survive a mechanically underdetermined record. |

Not carried over: numeric σ*-style aggregation (five samples per hypothesis is
too few for calibrated fractions to mean anything — the numbers are ordering
signals, not probabilities), and automated falsifier execution (Buddy has no
sandbox; pretending otherwise would violate the papers' own defeat rule).

## Roster (verified live on OpenRouter, 2026-07-22)

Chairman `anthropic/claude-fable-5`. Members: `openai/gpt-5.6-sol`
(`reasoning_effort: xhigh`), `anthropic/claude-fable-5`,
`anthropic/claude-opus-4.8`, `anthropic/claude-sonnet-5`,
`google/gemini-3.1-pro-preview`. Family skew (3/5 Anthropic, chairman is also
a member model) is disclosed in every verdict.

## Architecture

- `app/Services/Council/CouncilClient.php` — OpenRouter chat-completions via
  the `Http` facade: `askAll()` pools the five member calls, `interpret()`
  extracts JSON with one repair pass and one re-ask before giving up, usage is
  captured per call. Direct HTTP (not laravel/ai) because the council needs
  `reasoning_effort`, per-call `response_format`, and request pooling.
- `app/Services/Council/CouncilService.php` — R0→R3 orchestration,
  `packet()` (evidence + artifacts + memory hits, ids `E*/A*/M*`, all
  testimony), `anonymize()` (shuffled Member A–E aliases for R2),
  `adjudicate()` (pure-PHP binding tally: ref validation, fabricated-ref
  downgrade, defeat check, support fractions, lexicographic ranking, output
  mode, disclosure), per-round `checkpoint()` transcript artifacts, lease
  heartbeats between rounds.
- `app/Jobs/CouncilDeliberateJob.php` — `Tries(1)`, `Timeout(900)`,
  `FailOnTimeout`, `WithoutOverlapping` keyed by task, daily-cap check
  (council `BuddyRun`s per UTC day), claim with the 1200 s council lease.
- Entry points: MCP `buddy.council_evaluate`, REST
  `POST /api/buddy/tasks/{task}/council` (202 only, never inline), both gated
  on `BUDDY_COUNCIL`. Outbox payload carries `operation: council`; the relay
  dispatches the council job instead of the evaluate job.
- Persistence: verdict projected into the normal `EvaluationResult` shape for
  API/MCP consumers; full tally + transcript stored on
  `buddy_recommendations.council` and as `council_transcript` artifacts.
- `TaskStateService::reapExpiredLeases()` (piggybacked on the outbox relay
  cron) fails tasks whose worker died mid-council; grace of one lease period
  avoids racing a merely-late heartbeat.

## Timing chain (the invariant that keeps redelivery impossible)

`per-call timeout 300 s × sequential rounds ≤ job Timeout 900 s <
REDIS_QUEUE_RETRY_AFTER 1200 s = council lease 1200 s`, with heartbeats
between rounds. `BUDDY_QUEUE_RETRY_AFTER` only feeds the overlap lock; the
variable Horizon-less Redis queues actually honor is `REDIS_QUEUE_RETRY_AFTER`
— it must be set to 1200 on the worker app explicitly.

## Cost controls

Explicit invocation only; `CouncilGate` (task distress markers — 2+ attempts,
failed run, rejected evaluation — or declared `criticality: critical` with an
audited >= 30-char reason; one council per task; `council_eligible` surfaced on
task status; `BUDDY_COUNCIL_GATE` kill switch); `Tries(1)` (a failed council
is reported, not retried); `BUDDY_COUNCIL_MAX_PER_DAY` (default 10) across all
clients;
round-checkpoint artifacts preserve partial transcripts on crash so spend is
never fully lost; `max_output_tokens` 8000 per call.

## Deployment notes

- `OPENROUTER_API_KEY`: Key Vault secret `openrouter-api-key` + `secretRef`
  env on worker (and API for the enabled-gate); local dev uses the untracked
  `.env`. Never in the repo — the repo is public.
- Worker env: `REDIS_QUEUE_RETRY_AFTER=1200`, `BUDDY_COUNCIL=true`.
- Migration `2026_07_22_180000_add_council_to_buddy_recommendations_table`
  must be run manually via `az containerapp exec` — rollouts do not migrate.
