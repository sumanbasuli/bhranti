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

## Earlier package verification

- 381 lock-error checks, 2,367 product UX checks, 2,076 editor-sync checks, 179 real-React Settings-sync checks and the ambiguous-request recovery suite passed.
- PHP/JavaScript syntax, WordPress Coding Standards and all 23 packaging tests passed.
- Official Plugin Check reported “Checks complete. No errors found.” for the exact distribution; 34 checks were registered, including five runtime checks with the early object-cache drop-in active. The isolated SQLite integration emitted its upstream PHP 8.5 deprecation notices; these are retained separately from plugin findings.
- Astro reported zero errors or warnings; its build and verification passed for all 18 HTML pages, Markdown companions and local links.
- All 58 packaged runtime files match the retained native MySQL fixture. The last browser reload loaded the final sidebar and retained the completed chat with an enabled composer.

The earlier ZIP was superseded by the additional review and final package below.


## Additional user-flow and memory review

The follow-up review used the same isolated native MySQL fixture, an independent native SQLite installation and a newly configured disposable multisite network. Existing Studio site data, conversations, settings and connector credentials were not copied or changed.

- Real-browser checks covered a new chat, switching conversations without losing either unsent draft, Enter to send, a fake credential warning before submission, a normal successful reply, explicit preference saving, navigation to Settings with the sidebar preserved, cancelling memory reset and completing reset. A storage postcheck found zero instances of the fake credential, no saved preference after reset and an empty test-provider queue.
- Additional cookie-and-nonce HTTP journeys passed 72 assertions across 50 requests. They covered question/answer intake, draft creation, generated media, edits, a real five-second injected provider failure, recovery without a second image/write, Trash Cancel/Approve/replay rejection/Undo, permission revocation before foreground and Cron writes, changed action switches, log reading/export and chat deletion without deleting authored content. Only uniquely owned temporary resources were cleaned.
- Memory passed 3,818 bounded serial checks plus 424 parallel, stale-worker, cryptographic and privacy checks. Four independent workers acknowledged 16 distinct preferences; all 16 persisted exactly once after bounded busy/conflict retries. Tests covered preference replacement/caps, Unicode limits, malformed input, credentials, capability expiry, reset and erasure races, wrong owner/site/salts, malformed ciphertext, disabled crypto, and native paginated privacy export/erasure. Export covered 58 items over 26 pages; unrelated content and other owners' bytes remained unchanged.

These chat tests use a deterministic provider registered through the native WordPress AI SDK, including actual text/image DTOs and normal Bhranti execution paths. They do not bypass the connector or evaluate a remote model's reasoning, image quality or reliability.

## Privacy cleanup correction

The review reproduced a pre-existing defect: native account deletion during a held memory guard could leave encrypted preferences after the account disappeared. Since personal-data erasure normally resolves an email to an account, those orphaned records were not reliably reachable through that path.

Deletion now saves a bounded, nonautoloaded cleanup intent before attempting erasure. A native WordPress scheduled callback retries busy or technically failed cleanup. Its random deletion-generation binding and conditional finalization prevent an old callback from completing or erasing a newer deletion cycle. Current membership, permissions and saved-record identity are checked under the memory guard. Newly acknowledged memory for a retained/rejoined account remains protected. Scheduling failures keep the intent for a bounded administrator-triggered requeue; technical read failures never count as successful cleanup. Deactivation cancels callbacks while preserving pending intent; reactivation can requeue it. Uninstall removes the added scheduling hook and private intent.

The committed regression entry point is `tests/memory-erasure-native.php`. It refuses unmarked or non-temporary installations, requires the exact staged source and uses native account, membership, plugin lifecycle and separate-process WP-CLI Cron APIs. The final run passed 52 single-site and 127 multisite checks with no unexpected diagnostics. It covers held guards, scheduler rejection, callback replay, failed fingerprint reads, unknown initial snapshots, membership rejoin, retained super-administrator access, interrupted local/network uninstall, reactivation and late loaded callbacks.

Native scheduling requires site traffic or a configured scheduler. Account-deletion hooks run while the plugin is active. If storage fails before a reliable snapshot for an otherwise still-authorized retained account can be captured, cleanup remains pending rather than guessing whether newer preferences may be erased; normal native erasure/reset/uninstall can establish the actual completed state. A database that cannot persist the cleanup intent itself still requires the administrator to resolve that storage failure. Neither exception is reported as completed erasure.

## Security and public-guideline comparison

A sealed security diff review of immutable commits `85286b4..8aa4139` recorded zero newly introduced reportable vulnerabilities (scan `6e93df27-524c-45d6-90f5-ccc4532eac2e`). All 25 changed runtime/source files and the remaining test/document/static/metadata files were accounted for. The pre-existing cleanup defect was outside that diff's introduced-vulnerability scope. The subsequent fix received a separate focused source review; the sealed report was not rewritten to include later edits.

The comparison used the official [Plugin Directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [security guidance](https://developer.wordpress.org/plugins/security/) and [privacy guidance](https://developer.wordpress.org/plugins/privacy/). No new conflict was identified in the reviewed implementation: GPL-compatible distribution and bundled source notices, public readable source/build instructions, native authentication/capability checks and connector configuration, explicit plugin-operation approvals, local UI assets, documented provider data sharing, private data export/erasure/uninstall, and no service-account creation, arbitrary generated code execution or silent external requests. These checks do not establish Directory acceptance or universal production safety.

## Final package verification after the cleanup fix

- A final native MySQL smoke passed 32 assertions over 12 authenticated HTTP requests on the rebuilt package: ordinary chat, explicit memory save/readback, timed provider recovery, account deletion while a separate process held the real memory guard, wrong-generation rejection, actual native scheduled cleanup after its 60-second deadline and idempotent late retry. No generated media or ordinary content was created. The due callback ran as anonymous Cron, erased the private payload and removed its intent and schedule. Five native SDK model calls included one induced transport failure and its recovered reply. Temporary users, chats, queues and pending intents were removed; retained demo page and conversation hashes were unchanged.
- The exact rebuilt package passed disposable PHP 8.0.30 / WordPress 7.0.7 activation (13), human-only workspace (15), requester identity (21), native SDK chat/actions (128) and real HTTP authentication/ownership (11) checks. Native deactivation and uninstall preserved authored content and human accounts. External HTTP requests: zero.
- The final source passed 381 lock-error checks, 111 isolated memory checks, three disabled-crypto checks, WordPress Coding Standards, PHP/JavaScript syntax and all 23 packaging tests. Earlier editor/UI suites above remain applicable because the follow-up runtime change is confined to memory cleanup and its lifecycle hooks.
- Official Plugin Check reported “Checks complete. No errors found.” for the exact distribution, with 34 registered checks including five runtime checks and the early runtime drop-in. Upstream SQLite/PHP 8.5 deprecations were retained separately from plugin findings.
- All 58 runtime files were byte-for-byte identical in the package and both the native MySQL and Plugin Check fixtures; there were no extra plugin files. The deterministic test connector is excluded from the distribution.
- Astro reported zero errors, warnings or hints; its build and output verification passed for all 18 HTML pages and their Markdown companions, links and discovery files.

Installable package: `build/bhranti-1.2.zip`.

SHA-256: `560279d93e36b3c5c2aa96fc9c25e9f6ec8921b74f84d3a6545a9d2ccc7b3f49`.
