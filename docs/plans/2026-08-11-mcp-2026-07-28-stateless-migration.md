# Migrating buddy to MCP 2026-07-28 (the stateless spec)

- Status: plan accepted (buddy design review 01KZR1KHV3RGZK0A8NFXQ7Y0TJ, accepted, medium confidence)
- Date: 2026-08-11
- Hard constraint from the owner: **buddy's memories must be preserved**
- Source of protocol truth: the RC announcement (2026-07-28) and the Tasks extension
  `io.modelcontextprotocol/tasks` (SEP-2663). Re-read both before each phase; the RC locked
  2026-05-21 and the final spec ships 2026-07-28.

---

## 1. The headline: buddy is already stateless

The spec's central break — removing the `initialize` handshake, `Mcp-Session-Id`, and
protocol-level sessions — costs buddy **nothing**, because buddy never had them. Verified today:

| Fact | Evidence |
|---|---|
| No session header anywhere | grep for `Mcp-Session-Id` across `app/`, `routes/` → zero hits |
| No SSE, no held streams | grep for `event-stream` / `StreamedResponse` → zero hits; `McpController::get()` returns **405** with the comment *"Stateless server: no server-initiated stream is offered"* |
| Already multi-instance in production | `ca-buddy-api-dev` runs `minReplicas: 1, maxReplicas: 5` behind Container Apps ingress with **no session affinity** — statefulness would already be broken |
| Explicit-handle pattern already used | every tool takes `task_id` back as an ordinary argument, which is exactly what the RC prescribes for stateful apps |
| No SDK to wait on | MCP is hand-rolled; no MCP package in `composer.json` (only `laravel/ai`, which is an LLM gateway, not MCP). We control the protocol layer |
| Deprecations are non-events | `sampling` unused (buddy calls GPT-5.4 via `laravel/ai` directly); `roots` unused; `prompts/list` and `resources/list` return empty |
| Escaped the Tasks break | buddy never shipped against the `2025-11-25` experimental Tasks API, so the forced migration named in the RC does not apply |

### Corrections from the deep assessment — things that make this harder than "one constant"

1. **There are THREE divergent MCP implementations, already drifted:**
   | # | Implementation | Transport | Tools |
   |---|---|---|---|
   | 1 | `app/Mcp/RemoteMcpHandler.php` (438 ln) — **what production serves** | Streamable HTTP, POST only | 7 |
   | 2 | `bin/buddy-mcp-bridge` (259 ln) | stdio → REST shim | 6 |
   | 3 | `app/Console/Commands/McpServerCommand.php` (204 ln) | stdio, in-process | 8 (incl. search/store memory) |

   `SUPPORTED_PROTOCOL_VERSIONS` is **byte-identical in all three** (`RemoteMcpHandler.php:33`,
   `McpServerCommand.php:98`, `bin/buddy-mcp-bridge:20`). Bumping only the first silently widens
   the drift. **Nothing on this machine invokes #2 or #3** — zero clients use stdio; all 36
   `~/.claude.json` project entries point at the HTTP URL. Decide per §1.1 whether they live or die.

2. **`negotiate()` fails OPEN.** `RemoteMcpHandler.php:70-75` returns `'2025-06-18'` for any
   unrecognised version — *including* `2026-07-28`. A migrated client gets a plausible success
   response and silently speaks the old protocol. This is the single most dangerous line for this
   migration and the first thing Phase B must fix.

3. **`initialize` is buddy's ONLY teaching channel.** `RemoteMcpHandler.php:59` is the sole
   delivery point for `UsageInstructions::forInitialize()` — the close-protocol text whose own
   header comment says *"the server itself teaches the protocol instead of relying on each
   machine's local instructions."* In a stateless world with no handshake, **that channel
   disappears**. This was missed in the first draft and is a genuine design hole: see §1.1.

4. **No `outputSchema`, no `structuredContent`** on any tool — results are JSON-encoded inside a
   text block. The new spec's structured-output affordances are unused; out of scope here, worth
   a follow-up.

5. **Test coverage is thinner than the headline.** 179 tests pass in 8.5 s (the assessor ran
   them), but only **13 assert protocol/transport behaviour**, `RemoteMcpTest.php:75` asserts
   merely `assertCount(7, tools)` with **zero `inputSchema` assertions**, the negotiation
   *fallback* branch is untested, and implementations #2/#3 have **no tests at all**.

Current: `SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05']` (×3 files).
Safety net: **33 test files / 179 test methods** — with the gaps above.

### 1.1 The instructions problem (decide before Phase C)

If `initialize` stops being called, buddy stops teaching the close protocol, and outcome labelling
decays — the same corpus damage the Tasks-facade decision exists to prevent, arriving by a
different door. Options, owner's call:
- **(a)** keep `initialize` answering forever as a compatibility endpoint (allowed: deprecated
  features stay functional ≥1 year) and accept that new stateless clients never see instructions;
- **(b)** move the text into each tool's `description` — always visible, costs tokens on every
  `tools/list`;
- **(c)** expose it as an MCP **resource** the client may read;
- **(d)** return it in the `_meta` of the first `tools/call` response.
Recommendation: **(a) + (b-lite)** — keep the handshake for old clients, and put a one-line close-
protocol reminder in `buddy.close_task`'s description, where it is needed at the moment of use.

**Net: still not a rewrite — a protocol-framing change on an architecture that already satisfies
the spec's intent — but with three implementations to keep in step, one fail-open negotiation bug
to fix first, and a teaching channel to relocate.**

## 2. Track 1 — memory preservation (DONE, with evidence)

The memories are **not** in buddy's Postgres — buddy stores only *references* to them
(`buddy_memory_references.qdrant_point_id`). There are **two distinct stores**, and conflating
them is the mistake to avoid:

| Store | What it is | Backed up? |
|---|---|---|
| **Qdrant `mem_buddy_v4`** — the hub corpus. 3 named vectors: `colbert_vector` (128, multivector/max_sim), `problem_vector` (384), `solution_vector` (384) | production path: `BUDDY_MEMORY_BACKEND=hub` (forced in `buddy-api.bicep:119`) → Go memory hub → **Qdrant Managed Cloud, provisioned outside the IaC** | **yes**, daily 03:00 by `caj-buddy-memory-backup-dev` → `stbuddyhubdev/memory-backups` |
| **`buddy_episodes` / `buddy_knowledge`** — 1536-dim, `text-embedding-3-small` (`config/buddy.php:186-188`) | the **legacy** direct-Qdrant backend, still selectable | **unverified** — if these were ever written in production, that content is *not* in the snapshot I drilled. **Open item.** |
| **Relational history** — 768 tasks, 746 runs, 734 recommendations, 385 memory references, 735 decision logs | `database/database.sqlite.backup`, 7.4 MB, **gitignored, single copy on one laptop, mtime 2026-06-08** | **was not** — now copied into the preservation set with a checksum |

The protocol migration cannot touch this data — it changes request framing, not storage. But
the durability posture was thin, and hardening it first is cheap and de-risks everything after.

**Gaps found, and what was done today:**

| Gap | Action | State |
|---|---|---|
| Blob soft-delete **disabled**, no versioning, no container soft-delete | enabled blob soft-delete 30d, container soft-delete 30d, **versioning on** | **done** |
| Every copy inside the OLD subscription (mid-decommission) | pulled an independent verified copy outside it: 7 blobs, 8.4 MB, all valid POSIX tar, `SHA256SUMS.txt` recorded → `azure-migration-backups/buddy-memory-preflight-2026-08-11/` | **done** |
| A restore had **never** been drilled | drilled into an isolated scratch Qdrant — see below | **done, PASS** |
| Retention only 14 daily snapshots | lengthen; add an off-Azure target (`qdrant-memory` already has an R2 bucket + `r2-push.sh`) | **open** |
| Relational history existed as **one gitignored copy on one laptop** | copied to the preservation set, checksummed, read-back verified (768 tasks) | **done** |
| Legacy `buddy_episodes`/`buddy_knowledge` never checked for unique content | query both collections; if non-empty, snapshot them too | **open** |

### The restore drill (2026-08-11) — PASS

Because *"the job succeeded"* and *"a snapshot blob exists"* are not the claim *"the memories
come back"* — the same gap that produced two prior incidents in this estate (a green deploy over
a broken schema; an HTTP 200 on an mp3 that never played).

- restored `mem_buddy_v4` into a fresh Qdrant on port 6399, production untouched
- collection **green**, 2 segments, **248 points**
- all three named vectors present with correct dimensions and `max_sim` multivector config
- scrolled real points: genuine payloads (Jira/review/Raygun work items, `project=buddy`,
  timestamps Jul–Aug 2026) with real 384-dim `problem_vector` and `solution_vector`

Recorded in `RESTORE-DRILL-2026-08-11.md` beside the artifacts. Noted there and not treated as a
failure: sampled points carry problem/solution vectors but not `colbert_vector`, consistent with
the ColBERT sidecar being an optional enrichment (`qdrant-mcp-colbert-sidecar` runs separately).

**Still owed** (buddy's review, and correct): written **RPO/RTO** and acceptance criteria — a
restore performed *from the offsite artifacts alone, into an empty environment, by a different
operator following the runbook, within a bounded time*. Until that exists, "preserved" is proven
only for the happy path I personally ran.

## 3. Track 2 — the protocol migration, phased and reversible

Every phase is independently deployable and independently revertible. Buddy is the review sidecar
for its own migration, so **it must not be down while reviewing**: no phase takes the API offline.

### Phase A — dark launch: observe before changing anything
Log, do not enforce: presence and values of `Mcp-Method` / `Mcp-Name` headers, and whether
requests carry `_meta`. This tells us what the real client (Claude Code) actually sends before we
depend on it. No behaviour change. Reverts by removing a log line.

### Phase B — dual-version negotiation
Add `'2026-07-28'` at the head of `SUPPORTED_PROTOCOL_VERSIONS`, keeping the existing three for
the ≥1-year deprecation window.

**Deterministic precedence, specified now so it cannot become a heisenbug** (buddy's catch):

> If a request carries `_meta.protocolVersion`, **that wins for that request**. Otherwise fall
> back to any `initialize`-negotiated value, treated as **advisory only**. Otherwise the highest
> mutually-supported version. A conflict between the two is resolved in favour of `_meta` and
> logged with client identity — never the reverse.

### Phase C — `_meta` on every request
Refactor request parsing so `protocolVersion`, `clientInfo`, and `capabilities` are read from
`_meta` per request, and **`initialize` stops being required**. Keep answering `initialize` for
old clients; treat anything derived from it as advisory, because the server is stateless.

### Phase D — routing headers
Accept and validate `Mcp-Method` / `Mcp-Name`. Start **permissive** (accept, log mismatches),
tighten to spec-compliant rejection only after Phase A telemetry shows real clients comply.

### Phase E — the Tasks extension, as a facade
Adopt `io.modelcontextprotocol/tasks` **over** buddy's existing task state, while keeping
`buddy.submit_problem` and `buddy.close_task` canonical.

**Why not adopt it wholesale:** `close_task` carries an **outcome**
(`resolved` / `partially_resolved` / `not_useful` / `abandoned`). That label is the training
signal buddy's entire learning corpus depends on — a close without it is, in buddy's own words, a
lost signal. The generic task API has nowhere to put it. Routing all closures through
`tasks/update` would silently degrade the corpus, and the damage would be invisible for months.

**Invariants (from the design review, non-negotiable):**
1. every facade task id maps **1:1** to an existing buddy task;
2. status exposed via `tasks/get` derives from the **same source of truth** as the native tools —
   no second projection that can drift;
3. **`tasks/cancel` must deterministically assign a terminal outcome (`abandoned`)** — it may
   never produce an unlabelled terminal state;
4. task responses point clients at `buddy.close_task` when a meaningful outcome classification is
   available, so the facade never *looks* like the complete surface.

The facade earns its place on the 2–10 minute council runs, where generic polling is exactly
right.

### Phase F — logging deprecation
MCP `logging` → `stderr` for stdio and OpenTelemetry for structured observability. Buddy already
has Application Insights (`appi-buddy-dev`); this is a wiring change, not new infrastructure.

## 4. Tests to add before rollout (all in `tests/Feature/RemoteMcpTest.php` unless noted)

- a request with `_meta` and **no** prior `initialize` succeeds;
- an old client that calls `initialize` still works;
- `_meta.protocolVersion` **conflicting** with an initialize-negotiated version resolves to `_meta`;
- missing / malformed `Mcp-Method` / `Mcp-Name` behaves per the current enforcement mode;
- facade task id ↔ buddy task id is 1:1;
- **`tasks/cancel` lands the task in `abandoned`, never unlabelled** — the corpus-protection test;
- `tools/list` shape is unchanged for existing clients (no accidental break of the 8 tools).

## 5. Rollout order and rollback

| # | Phase | Rollback |
|---|---|---|
| 1 | Track 1 preservation (done) + retention/offsite (open) | n/a — additive protection only |
| 2 | A: dark-launch telemetry | remove logging |
| 3 | B: dual-version | drop `'2026-07-28'` from the array |
| 4 | C: `_meta` parsing | feature flag; `initialize` path still intact |
| 5 | D: header validation | flag flips back to permissive |
| 6 | E: Tasks facade | unregister the extension; native tools untouched |
| 7 | F: OTel logging | revert wiring |

No phase requires downtime; each is a config or small code change behind the existing test suite.

## 6. Accepted risks and open items

- **Leaked bearer tokens — rotation declined by the owner.** Two `bdy_live_` tokens were printed
  into a session transcript by me. The owner's assessment: sole user, endpoint unpublished, so
  rotation buys nothing. Recorded as an **accepted risk**, with the one fact that bears on it:
  `ca-buddy-api-dev` ingress is `external: true` with `ipSecurityRestrictions: null`, so the token
  is the only control in front of the API. Buddy's own review recommended rotation-with-overlap.
  The decision is the owner's and stands.
- **Retention (14 days) and offsite automation remain open** — the largest surviving durability gap.
- **Legacy collections unverified** — `buddy_episodes` / `buddy_knowledge` (1536-dim) may hold
  content absent from the drilled snapshot. Until queried, "all memories are preserved" is
  proven for the hub corpus only.
- **Cost drift, unrelated but found:** month-to-date spend is **€53.93 on day 11** ≈ **€150/month
  run rate**, against a €100 budget (`budget-buddy-dev`) and above the €109 carried in the estate
  plan. Two API revisions each hold a replica and one serves **zero traffic**; the memory hub and
  Redis are each pinned to a single replica while the API scales to 5.
- **RPO/RTO and a third-party-operator restore runbook remain unwritten.**
- Phase A telemetry may reveal the real client sends neither `_meta` nor the new headers yet, in
  which case B–D are ready but stay dormant behind their flags until the client catches up. That
  is a success, not a delay: the server becoming spec-ready before clients is the point.

## 7. What was verified vs assumed

**Verified (Tier 1–2, today):** every row of §1; the memory location, schema, snapshot growth and
backup schedule; the restore drill; the storage protections now enabled; ingress and replica
config; test counts.
**Tier 3 (authoritative docs):** the 2026-07-28 RC changes and the Tasks extension identity.
**Assumed:** that the final spec matches the RC (it locked 2026-05-21; re-verify per phase); that
Claude Code will adopt `_meta`/headers on its own timetable — which is why Phase A observes first.
