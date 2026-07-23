# Buddy improvement roadmap (2026-07-23)

Follow-up to the post-Langfuse gap analysis. Every gap below was verified at
HEAD `cda655e` (file:line evidence in the analysis memory `2d48fc56`). Waves
are ordered by a single dependency: the loop-closing features consume labeled
feedback data that only started accruing on 2026-07-22, so data-accrual
switches come first and data-consuming features come last.

Measured 2026-07-23 via the LangSmith API (session `buddy-dev`, b35a8256):
17 traced runs, exactly 1 `task_outcome` feedback — the implementation-session
verification probe. No organic outcome labels have arrived yet, so outcome
adoption by callers is itself a Wave 1 item (see item 4).

Out of scope by standing operator decision: API-key split/rotation (deferred),
OpenRouter production traffic (dormant until a winning bake-off).

## Wave 1 — quick wins and data-accrual switches (~1 day)

1. **Dependency update.** `composer update` clears most of the 22 advisories
   across guzzle/psr7/framework/symfony; re-run `composer audit` and pin
   anything that cannot move. Deploy with the usual zero-traffic canary.
2. **Fix the CIL experiment-name 409 collision.** Candidate/run ids restart
   after `migrate:fresh` while LangSmith session names persist, so
   `startExperiment` needs a unique suffix. (An earlier draft also claimed
   `buddy:cil-replay` was missing; that was wrong — the command has existed
   since 52b3afc.)
3. **LangSmith automation rules (console-side, near-zero code):**
   - rule: `task_outcome` score = 0 → add trace to a `buddy-failures` dataset
     and annotation queue;
   - rule: score = 1 on non-trivial tasks → add to a candidate-suite dataset;
   - alert on feedback-score degradation and on error-rate.
   From day one, every closed task starts building the eval corpus that Wave 3
   consumes. Verify rule/evaluator availability on the current LangSmith plan
   tier when configuring.
4. **Make outcomes actually flow.** The feedback pipeline works but callers do
   not use it: strengthen the `close_task` tool description (MCP + REST docs)
   so agents always pass `outcome` + `notes` on close, and update the
   onboarding recipe accordingly. The Wave 3 clock starts when organic
   outcomes appear, not at rule creation.
5. **Wire or delete `EscalationService`.** It is a registered singleton with no
   call sites; `CouncilGate` does the real gating. Recommendation: fold its
   trigger logic into `council_eligible` or remove it.

## Wave 2 — risk reduction (~1–2 days)

6. **Memory-hub backup.** Postgres has 35-day geo-redundant backup; the Qdrant
   volume has none. Add a scheduled ACA job (pattern exists: outbox relay cron)
   that snapshots Qdrant (native snapshot API) to Azure Blob or R2 with
   retention. The memory corpus is the least-replaceable asset in the system.
7. **Throttle re-key by api_client.** Named limiter keyed on the authenticated
   `api_client` id (falling back to IP for unauthenticated routes) so agents on
   one machine stop sharing a bucket.
8. **Enforce memory scopes.** `MemoryRead`/`MemoryWrite` exist in `ApiScope`
   but gate nothing; the MCP memory tools check only task scopes. Enforce them
   in `RemoteMcpHandler` and any future REST memory surface; issue keys
   accordingly.

## Wave 3 — close the loop (after 2–4 weeks of labeled data; ~2–4 days)

9. **Suite growth from real tasks.** `buddy:cil-harvest` command: mine closed
   tasks with feedback (and the LangSmith failure dataset) into reviewable CIL
   suite candidates; human curates, then `cil-import-suite`. Kills the 8-case
   thinness structurally.
10. **Graded scoring.** Replace binary accepted-match in `CilReplayService` with
   a rubric (verdict match, confidence calibration, risk-flag overlap), so
   replays discriminate more than pass/fail.
11. **Outcome-weighted memory.** Feed `TaskFeedback` into the memory plane:
    post `useful`/`not_useful` via the existing `MemoryGateway::feedback()`
    when a task closes with an outcome, so hub-side curation can weight
    retrieval. Buddy-side re-ranking only if hub-side proves insufficient.
    Deliberately last: weighting on a thin corpus overfits noise.

## Wave 4 — spec adoption and strategic ADRs (~2–3 days, after 2026-07-28)

12. **MCP spec 2026-07-28 adoption ADR.** The final spec ships days from now:
    Tasks primitive (native fit for Buddy's async evaluate/council + poll
    pattern), formalized OAuth 2.1 (revisits ADR 0006 bearer-only), stateless
    core (Buddy already aligned). Decide against the final text, not the RC.
13. **Context-boundary ADR (repo/diff awareness).** Buddy sees only
    caller-passed text; the council packet truncates artifacts at 4,000 chars.
    Recommended stance: keep the caller-curated boundary (Buddy never holds
    repo credentials — that is a feature of the trust model), but make the
    truncation budget explicit/configurable and document the boundary as ADR.
    Repo ingestion stays a rejected-alternative section unless a concrete need
    emerges.
14. **Provider threading in CIL replay** (~10 lines, per the OpenRouter
    decision memory): enables evaluator bake-offs (Claude/Gemini vs gpt-5.4)
    on the by-then-grown golden suite. Any production provider move remains
    gated on a winning bake-off.

## Standing discipline (applies to every wave)

- Deploys: zero-traffic canary revision with status-checked probes; migrations
  via the manual `caj-buddy-migrate` job before traffic shift.
- Every schema change lands with its migration step in the deploy checklist
  (the `langsmith_run_id` incident).
- New suites/datasets: keep Buddy as source of truth; LangSmith stays a
  projection.
