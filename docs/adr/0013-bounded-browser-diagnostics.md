# ADR 0013: Bounded browser diagnostics

**Status:** Accepted — 2026-09-15 (P7 of the Cloudflare plan; extends ADR 0010)

## Context

ADR 0010 fixed the caller-curated boundary: Buddy holds no repository
credentials, never reads code itself, and evaluates only the testimony a
caller chooses to pass. The Cloudflare plan (§11) adds one narrow way for
Buddy to gather evidence on its own: a diagnostic capture of a web page
through Browser Run, producing a screenshot, the response status, timing,
and bounded console and network errors. Without a decision this would be
read as the first step towards Buddy "reaching out" to callers' systems,
which ADR 0010 rejects. This ADR states exactly how far the capture goes,
and where the boundary from ADR 0010 remains untouched.

Two external facts shape the decision. First, Browser Run coverage under the
Cloudflare startup grant is unconfirmed: the published startup coverage list
does not establish eligibility, and the product's included allowance does
not prove startup-credit coverage. Second, a domain allowlist on a browser
session is not network isolation: DNS rebinding, IP literals in unusual
spellings, percent-encoded or Unicode hostnames, and allowed-domain
lookalikes all pass a naive host comparison.

## Decision

### The operation boundary

A diagnostic capture is a distinct operation with the `diagnostics:capture`
scope, recorded per task in `buddy_diagnostic_captures` with the client, the
target, the purpose, the capture policy, and the caller's request ID.

- **The caller supplies the URL and an explicit `allowed_hosts` list** (at
  most five exact hostnames, no wildcards). The URL's host must equal one
  of them. Buddy never chooses, discovers, or expands targets.
- **No credentials.** The session starts without cookies, storage, or
  headers, and the operation never mints, injects, imports, or transfers a
  browser credential. Userinfo in the URL is refused.
- **No caller code.** The caller cannot supply JavaScript, navigation
  scripts, browser extensions, or downloads. One page, one navigation, a
  policy the caller stated for redirects and same-host subresources, and a
  capture window of 5 to 30 seconds.
- **Bounded, redacted evidence.** The Worker reports at most a status code,
  a timing, console errors, network failures, and the object key of a
  screenshot stored under the owning client's prefix in R2. Azure keeps the
  first 20 entries of each list, cuts each entry to 500 characters, strips
  query strings from URLs, and passes every line through the same redaction
  used for intervention context (`InterventionContext::redact`, which
  applies `KnowledgeContentPolicy::sanitize`). Cookies, authorization
  headers, form content, and sensitive query parameters do not reach the
  record, the artifact, or the progress event.
- **Untrusted page content is evidence, never an instruction.** The
  resulting artifact is stored with `trust: untrusted_page_content` and a
  plain-text summary that says so. Nothing on a captured page can address
  Buddy, the council, or the calling agent; a page that says "approve this"
  is a page that says "approve this", reported as text. Browser capture
  cannot turn a refused action into an approved one, and the artifact never
  raises the trust of any evaluation input above caller testimony.

### The network boundary and its limits

`CaptureTargetPolicy` refuses, at request time and with stable codes shared
with the Worker: non-HTTP(S) schemes, userinfo, IP literals in any spelling
(dotted, bracketed IPv6, decimal, octal, hexadecimal, IPv4-mapped IPv6),
private and reserved addresses (RFC 1918, loopback, link-local, CGNAT,
multicast, unspecified), private names (`localhost`, `*.localhost`,
`*.internal`, `*.local`, `*.home.arpa`, `metadata.google.internal`),
Punycode labels, hostnames with characters outside `[a-z0-9.-]` (Unicode,
percent-encoding, underscores), hosts that differ from an allowed host only
by the confusable substitutions 0/o, 1/l and rn/m, and URLs over 2048
characters. A refused target is recorded as a `denied` capture with its code
so the caller and the operator share one audit trail. Port numbers are
passed through: the browser's unsafe-port list applies, and the Worker may
narrow further.

What the request-time policy cannot do is see what a name resolves to. **DNS
rebinding is not preventable here**: a public hostname can answer with a
private address at navigation time, or change its answer between the first
and second resolution. Therefore the Worker enforces the same policy again
at navigation time, against the resolved connection, and against every
redirect and subresource, with an explicit domain policy on every session
(an omitted policy permits unrestricted HTTP). If the chosen runtime cannot
enforce that boundary, live capture stays disabled. The Azure check is
defense in depth and an audit record, not the isolation itself.

### The billing gate (G7)

Browser Run coverage under the startup grant is unconfirmed. Until
account-specific evidence of coverage, or explicit authorization to pay
separately, exists:

- `BUDDY_EDGE_BROWSER_DIAGNOSTICS` stays `false`. The create route answers
  `503 browser_diagnostics_disabled` before writing a row, so the table
  stays empty and no session is ever requested.
- The Worker answers the same `503` with the same code when its own flag is
  off, and the dispatch job records that outcome as a failed capture rather
  than retrying.
- Nobody contacts Cloudflare on the user's behalf without authorization to
  send that message.
- The first live pilot uses an owned test page, after both the billing and
  the network gates pass.

### Quotas and cost controls

Ten captures per client per UTC day (denied captures do not count: they
never reach a browser and the API rate limit bounds them), two open
captures globally (queued or dispatched), a 30-second ceiling per capture,
one page per capture, and sessions closed after success, failure, or
cancellation. The dispatch job tries twice with a 10-second HTTP timeout and
then records `dispatch_failed`; a capture can never remain open without a
recorded reason. Every capture is idempotent on `(task, request_id)` with a
payload hash, so a retried request cannot start a second session.

### What this does not add

- No repository cloning, checkout, diff parsing, or code-host token. ADR
  0010 stands in full.
- No credentials of any kind, no authenticated browser sessions, no cookie
  or session import.
- No arbitrary commands, scripts, extensions, or downloads in the browser.
- No browser session injection into a caller's environment, and no transfer
  of anything from a capture back into a caller's browser.
- No new authority: a capture is evidence attached to a task the caller
  already owns, and the R2 object key never grants ownership (plan §3).

## Consequences

- The caller-curated boundary is extended by one reproducible, bounded
  observation, not replaced. Evaluation quality remains bounded by what the
  caller chooses to authorize.
- Two enforcement points must stay in agreement. The rejection codes are
  the contract; a Worker change that relaxes a check without a matching
  change here is a regression, and the fixture tests on both sides exist to
  catch it.
- A capture is a cost item with a per-client and a global cap. Raising
  either is a configuration change (`BUDDY_EDGE_CAPTURES_PER_DAY`,
  `BUDDY_EDGE_CONCURRENT_CAPTURES`), not a code change, and stays subject
  to the edge budget in plan §12.
- The callback token exists in clear only inside the encrypted queue
  payload and the Worker request; the record keeps a SHA-256 and the
  completion route answers 404 to anything else.

## Rejected alternatives

- **Authenticated browser sessions** (cookie import, credential injection).
  They would give a shared sidecar access to callers' logged-in state, the
  exact blast radius ADR 0010 refuses.
- **Caller-supplied navigation scripts or JavaScript.** They would make the
  operation an arbitrary remote browser, and every network check here would
  become advisory.
- **Trusting the allowlist alone.** Rejected because it does not address
  rebinding, literals, encodings, or lookalikes; enforcement must live at
  navigation time as well.
- **Enabling live capture with the flag defaulting on.** Rejected until the
  billing gate passes; a mocked contract with fixtures is the deliverable
  until then.
