# Dependency audit and update, 2026-09-15

## Applied

| Package | From | To | Note |
| --- | --- | --- | --- |
| league/commonmark (transitive) | 2.8.3 | 2.10.1 | Closes 10 advisories (`composer audit` clean afterwards) |
| laravel/framework | 13.21.1 | 13.32.0 | semver-safe |
| laravel/octane | 2.18.0 | 2.19.1 | semver-safe |
| guzzlehttp/guzzle (transitive) | 7.15.5 | 8.2.0 | pulled by the framework update; suite green |
| laravel/pint, mockery, collision, pail, algolia | patch | patch | tooling |
| wrangler / @cloudflare/vitest-plugin (Worker) | 4.131.2 / 1.1.9 | 4.132.0 / 1.1.10 | Worker typecheck + vitest green |

## Deferred on purpose

- **phpunit 12 → 13** and **vitest 4 → 5**: major versions with no security
  driver; the current majors are supported. Revisit with the next framework
  minor.
- **laravel/ai 0.3 → 0.11**: see the release notes in the handoff; gated
  separately by the full suite because fakes now run through the real
  generation loop and connection failures are rethrown as
  `ProviderConnectionException`.

## Accepted advisory (Worker package)

`npm audit` in `cloudflare/buddy-edge` reports three high findings that are
one advisory pair on `extract-zip <= 2.0.1` (GHSA-jmr9-qjv8-65gv,
GHSA-7pqw-9j4j-h8q3: symlink path traversal during zip extraction), reached
through `@cloudflare/puppeteer 1.4.0 → @puppeteer/browsers 2.2.4 → extract-zip`.

- No patched `extract-zip` exists (2.0.1 is the latest release) and npm's
  only "fix" is a downgrade to `@cloudflare/puppeteer 0.0.11`.
- Reachability: the vulnerable code runs when a local browser archive is
  extracted. Neither `@cloudflare/puppeteer` nor `@puppeteer/browsers` has an
  install or postinstall script, the repository never invokes
  `@puppeteer/browsers install`, CI (`.github/workflows/edge.yml`) runs only
  `npm ci`, `npm run typecheck`, `npm test` and a `wrangler deploy --dry-run`,
  and the Workers runtime has no filesystem to extract into. Browser Run
  itself is disabled (`BROWSER_DIAGNOSTICS=false`).
- Disposition: accepted, owner iclaborda, review by 2026-10-15 or when
  `extract-zip` publishes a fix, whichever comes first. Do not downgrade
  `@cloudflare/puppeteer` and do not add an unverified override.
