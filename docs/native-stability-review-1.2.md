# Bhranti 1.2 native stability review

Recorded 24 October 2026. Internal test record; not a certification of security, accessibility or Plugin Directory acceptance.

## Purpose and comparison

A chat request on the first existing local site returned HTTP 409 despite no visible active task or option lease. An instrumented request reported that `flock()` returned false with `would_block = 0`. That establishes a failed guard, not its cause. The earlier attribution to Studio was premature.

Two independent WordPress installations were created outside Studio and Playground, with fresh databases, configuration, salts and disposable users. Only immutable cached core files were reused. No existing site data or connector credentials were copied.

The comparisons used actual source commit `85286b4` for 1.1 and `ead5ff8` for committed 1.2, followed by physical snapshots of the candidate patch. An ignored older ZIP was not used as the historical baseline because local development builds can overwrite it.

## Native environments and results

| Environment | Coverage and result |
| --- | --- |
| WordPress 7.0, native PHP 8.5.3, MySQL 9.0.1, six HTTP workers | Native SDK chat/action suite passed 187 checks. The final supported-registration HTTP run passed 124 checks across 134 requests, including 30 chats, authentication/ownership, concurrent send/plan/execute, exactly one saved write, edit and Undo, provider failure/recovery, separate-process Cron, identity and logs. Four additional checks confirmed that a pending background write completed after a saved browser editor closed normally. True 1.1 also passed 30 chats and 100 lock cycles on a separate clean database. |
| WordPress 7.0, native PHP 8.5.3, SQLite Integration 1.8.0 | Exact old/new lock helpers passed 12,000 cycles, 30 process races, 10 expiry/crash scenarios and 200 HTTP requests. Patched runtime passed 637 workspace/memory/lifecycle checks, 300 concurrent memory operations, 12 error-status checks and 43 native locking checks. |
| Independent private-guard differential | Each exact old/new helper passed 20,000 native cycles with no stream-handle leak. This isolates guard routines; it is not a complete WordPress test. |
| Browser | A draft FAQ was created, renamed and restored through the sidebar. Chrome rendered the actual saved blocks. Disabling the test connector showed setup guidance and disabled chat; checking after enabling it restored chat without a page refresh. |

MySQL uses native database advisory guards. Separate native `flock()` probes also succeeded. In both database environments, a live process retained its guard after the option lease expired; after terminating only the test holder, a successor reclaimed and released the expired lease safely. No `flock()` error was reproduced in these fresh installations.

Native binaries labelled PHP 8.2–8.4 on this machine resolve to the same 8.5.3 installation, so they do not establish additional native PHP version coverage. Earlier disposable Playground checks covered PHP 8.0 separately.

## Native AI test connector

The disposable provider uses the real WordPress AI SDK provider/model interfaces and native prompt/result DTOs. It registers through the public provider registry before core automatically creates its connector. It returns deterministic local results and makes no external AI requests. Bhranti uses its normal client, REST, permission, approval, audit and recovery paths. This verifies execution and concurrency; it does not evaluate real-model reasoning or remote-provider reliability.

The fixture can disable the provider for the actual disconnected state. Core automatically registers this no-authentication provider, but its Connectors screen has no default no-authentication renderer; no artificial connector card was injected. No test connector, response queue or fixture control ships in the plugin ZIP. Early fixture setup errors and deliberately induced process failures are retained in local diagnostics, separate from passing-run results.

## Confirmed corrections

- Technical guard failures were incorrectly labelled as another running request. They now return `bhranti_lock_guard` with HTTP 503; actual contention retains HTTP 409. Every acquisition caller propagates the error before performing a mutation. Lock protection is not bypassed.
- The sidebar now shows native connection setup and disables sending and attachments when no provider is configured. History and drafts are preserved, and returning from setup refreshes availability.

`php tests/lock-errors.php` covers guard errors, real contention, callback suppression, adapter propagation, context restoration and handle cleanup. The baseline fails the new error-reporting assertion; the patch passes. `node tests/product-ux.cjs` covers disconnected, reconnect and draft behavior, including older successful or failed configuration reads arriving after a newer result. Superseded reads cannot re-enable Send or overwrite current connection feedback.

## Remaining uncertainty

These results do not reproduce or explain the original site's failed syscall, and do not prove that every 1.2 interaction is regression-free. The guard algorithm predates the identity/memory changes, but 1.2 adds memory-lock usage. Do not state that Studio caused the original failure or weaken exclusive-write protection to mask it. A final read-only recheck found the original sidecar free and no option lease. The original site was then returning an Express 404 rather than serving WordPress, so the failed request could not be retested in its original HTTP runtime. Neither original site was restarted or remounted, and no content, settings or connector data was changed. Further work on that environment requires a controlled reproduction with the actual runtime error.

## Final package verification

- 381 lock-error checks, 2,367 product UX checks, 2,076 editor-sync checks, 179 real-React Settings-sync checks and the ambiguous-request recovery suite passed.
- PHP/JavaScript syntax, WordPress Coding Standards and all 23 packaging tests passed.
- Official Plugin Check reported “Checks complete. No errors found.” for the exact distribution; 34 checks were registered, including five runtime checks with the early object-cache drop-in active. The isolated SQLite integration emitted its upstream PHP 8.5 deprecation notices; these are retained separately from plugin findings.
- Astro reported zero errors or warnings; its build and verification passed for all 18 HTML pages, Markdown companions and local links.
- All 58 packaged runtime files match the retained native MySQL fixture. The last browser reload loaded the final sidebar and retained the completed chat with an enabled composer.

Installable package: `build/bhranti-1.2.zip`.

SHA-256: `2c36b73ffa1fa78482af5131438e03248e8e20710eb1b06ec1b13691e1226229`.
