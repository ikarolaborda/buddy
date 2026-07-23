# Recipe: Onboard a New Machine to the Remote Buddy — Agent-Executable

Audience: a coding agent (Claude Code or equivalent) running on a machine that
should consume the deployed Buddy. The operator provides exactly one input —
a Buddy API key (`bdy_live_...`) — and may have already added the MCP config.
Everything else in this recipe is yours to execute autonomously. Do not ask
the operator for anything besides the key unless a step explicitly says so.

Deployed endpoint (update here if the deployment moves):

```
https://ca-buddy-api-dev.salmonglacier-699d423d.northeurope.azurecontainerapps.io/api/mcp
```

## Step 0 — Inputs and preconditions

Required input: `BUDDY_API_KEY` (`bdy_live_...`), provided by the operator.
Never write it into any file inside a git repository, shell history helper,
or log. Its only legitimate destinations are the Claude Code MCP config and
transient environment variables.

Optional inputs (only if Step 5 applies): `QDRANT_CLOUD_API_KEY`, or an
authenticated `az login` with Key Vault access. Absence of these must not
block onboarding — Steps 1–4 complete without them.

## Step 1 — Verify the endpoint and key (fail fast)

```bash
curl -s -m 20 -X POST "$BUDDY_URL" \
  -H "Authorization: Bearer $BUDDY_API_KEY" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Expected: a JSON-RPC result listing **7 tools** (`buddy.submit_problem`,
`buddy.get_task_status`, `buddy.evaluate_task`, `buddy.refine_prompt`,
`buddy.attach_artifact`, `buddy.close_task`, `buddy.council_evaluate`). If
the live tool list differs from this document, trust the live endpoint and
flag the doc as drifted in your report.

Also confirm auth is actually enforced: the same call **without** the
Authorization header must return 401.

- HTTP 401 with the key → the key is wrong or revoked. STOP and report.
- Connection failure → report the endpoint as unreachable; do not continue.

## Step 2 — Install the MCP config

If the operator already added the `buddy` entry, verify it matches the shape
below and continue to Step 3. Otherwise install one **user-scope** entry so
every project on the machine gets it:

```bash
claude mcp add --scope user --transport http buddy "$BUDDY_URL" \
  --header "Authorization: Bearer $BUDDY_API_KEY"
```

Equivalent raw JSON (goes under `mcpServers` in the user-level config):

```json
"buddy": {
  "type": "http",
  "url": "<endpoint above>",
  "headers": { "Authorization": "Bearer bdy_live_..." }
}
```

Installation is **additive** — do not remove anything in this step. Prefer
the CLI over hand-editing JSON: it merges correctly and cannot clobber
unrelated `mcpServers` entries.

## Step 3 — Validate from the agent side

After a session restart (MCP config loads at startup), confirm the `buddy`
server connects and the seven tools are callable — `claude mcp list` or a
`buddy.get_task_status` call with a bogus ULID (a clean "Task not found"
proves auth + routing end to end). If the server connects and then silently
drops, check the per-project MCP logs under
`~/Library/Caches/claude-cli-nodejs/<project-slug>/mcp-logs-buddy/` (macOS;
path varies by OS).

Do not proceed to cleanup until this validation passes.

Usage rule to carry into the machine's agent instructions: every
`buddy.close_task` call must pass `outcome` (`resolved`,
`partially_resolved`, `not_useful`, or `abandoned`) plus short `notes`.
Outcomes label the trace corpus that Buddy's recommendation quality is
measured and improved against; a close without one is a lost signal.

## Step 4 — Sweep stale local entries (only after Step 3 passes)

Older machines may carry per-project `buddy` entries that spawn a local
Docker container or the stdio bridge. These waste local resources and shadow
the remote entry. Sweep conservatively:

1. Back up first: copy `~/.claude.json` to
   `~/.claude.json.bak-<YYYYMMDD>-buddy-remote`.
2. Remove ONLY entries that match a known legacy signature:
   - `"command": "docker"` with args containing `buddy-app` or
     `buddy:mcp-server`, or
   - a `command` path ending in `bin/buddy-mcp-bridge`.
3. Leave untouched any `buddy` entry that is already `"type": "http"` (it may
   hold a different, intentionally scoped key) and anything that does not
   match the signatures above — list unmatched oddities in your report
   instead of deleting them.

Suggested implementation (adapt paths, keep the backup):

```python
import json, shutil
path = '<home>/.claude.json'
cfg = json.load(open(path))
shutil.copy2(path, path + '.bak-<YYYYMMDD>-buddy-remote')

def is_legacy(e):
    args = ' '.join(e.get('args') or [])
    return (e.get('command') == 'docker' and ('buddy-app' in args or 'buddy:mcp-server' in args)) \
        or str(e.get('command', '')).endswith('bin/buddy-mcp-bridge')

for proj in (cfg.get('projects') or {}).values():
    entry = (proj.get('mcpServers') or {}).get('buddy')
    if entry and is_legacy(entry):
        del proj['mcpServers']['buddy']
json.dump(cfg, open(path, 'w'), indent=2)
```

## Step 5 — Sync local agent memories to the cloud (conditional)

Only applies if this machine runs a local Qdrant with agent memory
collections. Probe: `curl -s http://127.0.0.1:6333/collections`. "Nothing to
sync" means: local Qdrant unreachable, absent, or no `mem_*_v4` collections —
in all three cases record "no local memories to sync" and continue; this step
must never block or fail onboarding.

Otherwise, from the machine's `qdrant-memory` checkout (the repo that runs
the local memory broker; script lives at `scripts/qdrant-cloud-push.py` on
`main`, runbook in `scripts/qdrant-cloud-push.md`):

```bash
scripts/qdrant-cloud-push.py --all --dry-run   # preflight: schema parity + counts
scripts/qdrant-cloud-push.py --all             # live push
scripts/qdrant-cloud-push.py --all             # rerun: verifies idempotence (same counts)
```

Credentials, in order of preference:
1. `QDRANT_CLOUD_API_KEY` env var — ask the operator only if this step applies.
2. `az login` with access to Key Vault `kv-buddy-dev` (secret
   `qdrant-api-key`) — the script resolves it automatically.

The script is additive-only and idempotent: it never deletes cloud points,
never overwrites hub-written points (they lack the `_synced_from` provenance
marker), and fails closed on schema mismatch. Safe to re-run any time.

## Step 6 — Report

Tell the operator, factually: endpoint verified (tool count), config entries
installed/swept (with backup path), memory sync outcome (pushed counts per
collection, or skipped and why), and any step that failed with its exact
error.

## Rollback (per mutation)

- User-scope MCP entry: `claude mcp remove --scope user buddy`.
- Swept per-project entries: restore `~/.claude.json` from the timestamped
  backup.
- Exported env vars: unset them in the current shell.
- Memory sync: nothing to roll back — the push is additive; stop re-running
  and inspect the script's JSON summary if something looks wrong.

## Notes for maintainers

- Key issuance stays operator-side: `bin/buddy-issue-key` against the
  deployed API (needs an admin key), or headless via a one-off run of the
  Azure migrate job (`az containerapp job start` — replicate the job env with
  `secretref:` values; the minted key appears in `ContainerAppConsoleLogs_CL`
  after ingestion lag).
- Per-machine keys are the intended granularity for personal machines; name
  clients after the machine (`ikaros-mac`) so revocation is per-device.
- If the deployment URL changes, update this file and the README section —
  they are the only two places the URL lives in this repo.
