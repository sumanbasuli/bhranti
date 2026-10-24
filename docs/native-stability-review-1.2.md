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

## Real ChatGPT connector follow-up and closeout

Recorded 24 October 2026. These browser journeys use the configured native ChatGPT connector on `bhranti-2.wp.local`, so the confirmed results below exercise the actual remote model connection. They are separate from the earlier deterministic native SDK fixtures. The scope is uniquely identified temporary conversations, drafts and media; existing clinic content and shared site design are excluded. The actual-model rendered inspection, background completion, owned test-resource cleanup and current package identity are recorded below. The regular-profile frame failure and live technical guard failure remain explained only to the limits of the captured evidence; unrelated administrative aggregate differences remain unattributed.

### Confirmed real-model results

- An explicitly requested preference was saved through the normal memory GET/POST tools. A fresh conversation recalled the saved preference. An ordinary site-information question correctly reported three installed themes.
- The illustrated-content journey created draft page `3100` and generated image attachment `3092`, identified by native saved-action receipts.
- A controlled failure at a planning network request recovered the existing task. The draft and image IDs were unchanged, and the already completed action-receipt prefix was preserved. No repeated completed action or regenerated image was observed in that case. This tests definite failed planning-request recovery, not permission to replay an uncertain mutation or a guarantee for every provider outage.
- The ordinary request to forget a saved preference initially exposed an overly literal evidence match. After the scoped correction below, the real ChatGPT journey completed native memory GET followed by DELETE for the intended preference. This result is distinct from resetting all memory through Settings.
- Real chat `3301` added the requested winter-care and harvest sections with one saved content change. The existing generated image was reused unchanged. Its final response honestly reported that the rendered preview check had failed rather than claiming visual verification.
- Real background chat `3333` renamed the test draft after the browser closed. Initial due native callbacks waited for the clean active-editor lease to expire; the native scheduled callback then completed at step 3 on 24 October 2026 at 13:53:51 UTC. The saved title became **Balcony Herb Notes**, the post remained a draft, its existing content hash was unchanged and image attachment `3092` was reused. This is a completed real-connector background case.
- Actual real ChatGPT Guest conversation `3353` completed its read-only rendered inspection in three steps, using only the native WordPress GET tool and `bhranti_inspect` with `resource=rendered`. The native receipt and report were retained in the local real-rendered-check QA evidence. The inspected post fingerprint was unchanged and image attachment `3092` remained the same. Detailed findings and limits are recorded below.

### Corrections supported by these journeys

**Natural preference deletion.** In `includes/class-bhranti-memory.php`, deletion evidence may omit only a fixed initial “I prefer” or “I like” when the complete remaining literal phrase identifies one saved preference. It does not introduce semantic or fuzzy matching. The latest explicit human request, owned preference ID, exact current revision and existing negation/credential checks remain required. A partial, changed or ambiguously shared phrase does not authorize deletion. `tests/memory.php` covers the accepted ordinary phrasing and refusal boundaries. The memory suite passed 143 checks; disabled-crypto checks passed three, credential checks passed 285 and WordPress Coding Standards passed.

**Resolved transient notices.** The real memory chat completed but retained a dismissible editor-safety notice and an obsolete background-status reconnect message. In `assets/admin.js`, `resumeRun()` scopes its editor-safety notice; a successful `reportEditor()` heartbeat or proven `observeBackground()` handover clears only that notice through `clearEditorError()`. Clearing a previous safety error also triggers a sidebar rerender when the returned editor flags are unchanged. A successful heartbeat alone does not resume a dirty-editor pause. `runRequest()` clears only the tracked background-status warning after a successful response bound to the still-current session and run. Unrelated notices, chat-list failures, uncertain action errors and stored messages/events are preserved.

**In-chat rollback choice.** A live rollback invocation exposed the native browser confirmation popup. The requested replacement reuses the existing accessible chat confirmation card, with the original target, concrete Trash/restore/conflict explanation and **Approve rollback / Cancel**. It sends no request when opened or cancelled, defaults focus to Cancel and allows drafting while blocking Send, New chat and conversation changes until the choice is resolved. Approval is bound to the original owned conversation, current run and exact rollback-enabled receipt, then consumed before the authenticated rollback request. Stale or repeated clicks cannot submit that choice again. The existing server capability, editor, snapshot-conflict and replay checks are unchanged. Successful history comes from the native rollback receipt, not an invented local approval event. A lost response is reconciled by GET; an unproven result remains an explicit reconnect error and is never automatically reposted.

The live in-chat Cancel check produced zero rollback POSTs and left the test page in Trash. The subsequent explicit Approve rollback restored the page to draft and recorded the native successful rollback event (identifier prefix `002006ba`). The approval card disappeared and the consumed rollback control was removed. This confirms the requested ordinary-browser Cancel and Approve flow against the real WordPress endpoint; it does not add a universal rollback guarantee.

### Focused client regressions

`tests/product-ux.cjs::testResolvedTransientStatus()` covers resolved heartbeat/checkpoint notices and preserved unrelated or uncertain errors. `testRollbackChatApproval()` exercises opening, cancelling, one approved request, repeated old controls, exact session/action payload, lost and timed-out responses, saved-receipt recovery, stale conversation/run/action/target/receipt refusal, active-run refusal and draft preservation. The native-popup regression fails against the preceding runtime.

After the combined sidebar changes:

- Product UX: 2,520 isolated checks passed.
- Native editor synchronization: 2,076 isolated checks passed.
- Admin recovery passed, including ambiguous POST reconciliation, late-response refusal and no automatic action replay.
- JavaScript syntax and `git diff --check` passed.

These deterministic browser-code checks are reported separately from the completed real ChatGPT requests above.

### Runtime observations and limits

**Rendered preview and browser profile.** In the regular browser profile, dynamic rendered-check frames were observed remaining at `about:blank`. Diagnostic `3350`, using native Chrome Guest with no extensions and the same unmodified harness, completed all three configured viewports. The subsequent actual real ChatGPT Guest journey `3353` also completed its bound rendered inspection. This establishes a successful native path in Guest and a difference between the observed profile environments; it does not identify the responsible extension, setting, storage state or other specific cause, or prove that every browser profile works. The temporary load-time diagnostic was restored to the original source. Diagnostic `3350` has now been removed through its native cleanup, with `session_absent=true` and `source_unchanged=true`.

**Actual rendered findings.** At the 1,280-pixel desktop viewport, the real chat reported zero automated accessibility violations. At 390 and 320 pixels, it reported serious target-size findings on existing shared footer links measured at approximately 19 CSS pixels. No horizontal overflow, broken images, missing fragment targets or duplicate IDs were reported across the three viewports. The task honestly disclosed the mobile findings. It did not modify the shared clinic footer, which is outside the test-draft authorization. A completed automated inspection does not mean that all findings were fixed, and these results do not establish keyboard, screen-reader, form, transaction or full WCAG 2.2 AA conformance.

**Technical guard failure.** In the actual locally hosted site's runtime, the captured native `flock()` call returned `false` with `would_block=0` while the file handle and sidecar file were valid. Bhranti correctly reported a technical lock-guard failure with HTTP 503 rather than claiming ordinary HTTP 409 contention. A native-PHP comparative execution of the real connector flow succeeded. The observed difference does not explain the failed call, identify an underlying WebAssembly/JavaScript or host exception, establish that Studio caused it or rule out a call-path regression. The lock source remains unchanged, and no exclusive-write protection was bypassed. This remains a bounded runtime limitation, not a proven root cause or a failure that may be ignored because another runtime passed.

**Preservation comparison.** The final post-cleanup output recorded zero new posts and matched all 3,024 original posts, their metadata, taxonomy/comment records, active-theme files and the selected core-file hashes against the baseline. The original administrator record (ID `1`) and a Jetpack option aggregate differ from baseline; their causes remain unattributed. Other operational cache, Cron and sign-in changes were observed, but their individual causes were not fully provable. Do not infer from the content/file comparison that every database record or background option is unchanged, or attribute these differences to Bhranti without evidence.

**Assessment boundary.** The real connector journeys and owned cleanup are complete to the recorded scope. The regular-profile frame behavior, failed technical guard call and unattributed administrative aggregate differences remain explicit limits. A passing package scan and completed automated rendered inspection with disclosed findings do not establish universal production readiness, full accessibility conformance or Plugin Directory acceptance.

### Candidate and package identity

The candidate runtime changes are confined to `assets/admin.js` and `includes/class-bhranti-memory.php`, with regression additions in `tests/product-ux.cjs` and `tests/memory.php`. The user-facing Undo documentation now describes in-chat Approve rollback / Cancel. Its Astro build/output verification passed for all 18 HTML pages and their Markdown companions and links. No renderer or lock source modification is part of the candidate.

| Source | SHA-256 |
| --- | --- |
| `assets/admin.js` | `87025c0ae319476f0367a45a84452f40bdb046e233715999351bcb8c2716d0d4` |
| `includes/class-bhranti-memory.php` | `803df651cd434e71e16f5c90a3c73abd7e25caafe29307311749ac8ab5932d16` |
| Unchanged `assets/render-check.js` | `ea3d9a962c65c6e76bd7922c2f8328d4ad17acf0f6e4a8ac2fba633d18100fa1` |
| Unchanged `includes/class-bhranti-lock.php` | `db7d68ac1bad1c7bca8f8224f11a15e4316904d62b7b03e766f3bcc100900ce4` |

The current 1.2 package includes the in-chat rollback card and natural preference deletion changes. Packaging regressions passed all 23 checks; official Plugin Check passed with 34 registered checks including five runtime checks; full WordPress Coding Standards passed. The current packaged sidebar and memory source compare byte-for-byte with the corresponding source files listed above. This local validation does not resolve the open live-runtime or browser-profile observations.

Current installable package: `build/bhranti-1.2.zip`. SHA-256: `413825231a27b068060c56a0cc90f0a43ff003742c28e1894e8c28a96f28316f`. The unversioned `build/bhranti.zip` has the same checksum. The separate listing-assets archive has SHA-256 `48b68e15bdb8802fcf67394c595e7c49d6d97596fd628deb6cd6ecc734a56dfa`.

The earlier intermediate ZIP hash `5711808db4cd39183ceedc2935d3c4b754f654687698a4d8382968d07cacc604` identifies the build before the rollback-card change. The still earlier checksum recorded above under the preceding cleanup review identifies its own historical build. Do not relabel either as the latest candidate or imply their exact-package checks covered later source edits.


### Final cleanup and local environment closeout

The recorded native cleanup application (`native-cleanup-result.json`, recorded apply time 14:08:31) removed only the correlated test-resource set:

- Nine real test conversations and their 309 child audit records.
- Owned draft page `3100` and its five revisions.
- Generated attachment `3092` and its five generated files.
- Eleven temporary MU-plugin fixture files, three QA-only options and two native-host pattern-cache entries.

The exact diagnostic login token was revoked; the cleanup result confirmed that other login sessions were unchanged. Diagnostic `3350` had already been removed through its own native cleanup, separately from the real-chat resource set above.

Four remaining parentless memory audit records were correlated using the exact test capability-observation IDs, timestamps and generated-PNG evidence. `memory-semantic-cleanup-result.json` records a native revision-checked reset restoring the initial state of zero saved preferences and zero capability observations. Only those four correlated audit records and the two audit records created by the cleanup reset itself (IDs `3369` and `3370`) were removed. This did not restore raw encrypted option bytes or reset unrelated users' memory.

The retained final preservation output recorded zero new posts and the unchanged original content/file set described above. Administrative/Jetpack aggregate differences remain disclosed rather than being overwritten to force a matching hash.

The temporary native PHP server on port `9432` was stopped and the port was confirmed closed. Only the `bhranti-2` Studio site was restarted. Its original HTTPS administration URL and native Bhranti Settings loaded successfully in Codex’s in-app browser; the empty memory state and unchanged saved settings were visible. A final read-only preservation comparison after restoration still matched the original 3,024 posts and metadata and showed no new posts. Other Studio sites were not restarted or changed by the cleanup.

Sensitive temporary authentication, request-body, configuration-backup and pre-test memory files were removed; the retained local QA directory has mode `0700`. The local WordPress configuration retains one guarded `WP_DEBUG` definition with its original value unchanged. There was no whole-file configuration restore or connector-credential copy.

The current renderer and lock files still have the unchanged hashes above. The final source/package identity and checks remain distinct from the unresolved runtime observations; the cleanup does not convert those observations into an all-clear claim.


## Plugin path and mobile Logs compatibility

Plugin path comparisons now normalize resolved paths through native WordPress helpers. Registered directory and single-file AI providers remain protected when the native plugin root is a filesystem alias. Individual linked plugin files or directories and paths outside the physical root remain blocked; failed root resolution keeps dependency changes blocked. The offline fixture reproduced both previous provider-protection failures and now passes 28 checks for each physical and aliased root, including Windows-style path strings and containment boundaries. This fixture makes no database, network, activation or AI requests.

The mobile Logs layout clips its table header visually while preserving native column-header semantics. Actual browser checks at 390 and 320 CSS pixels retained all four column headings and showed no horizontal overflow. Setup documentation no longer requests add-user permission, and listing screenshot credits identify the Twenty Twenty-Five CC0 photograph.

The full first-party source review found no supported exploitable vulnerability. Upstream library internals and transitive dependencies were not exhaustively audited. This source result does not resolve the previously recorded runtime locking compatibility failure or certify the hosting environment.

Final verification passed: 56 offline plugin-path checks, 2,520 isolated sidebar checks, 65 backend product UX checks, 143 native SQLite/options/crypto memory checks and three unavailable-crypto checks. Official WordPress Coding Standards finished with zero errors or warnings. Packaging regressions passed all 23 checks. The Astro site check, build and output validation passed for all 18 pages.

The refreshed installable package passed official Plugin Check 2.1.0 on isolated native PHP 8.5.3 / WordPress 7.0, with all 34 checks including five runtime checks and the early runtime drop-in active. All 58 runtime files match source, build, ZIP and fixture byte for byte, with no runtime symlinks. The 3,072 other fixture dependency/bootstrap files were unchanged. Upstream WP-CLI and SQLite/PHP 8.5 deprecations were recorded separately; no Bhranti diagnostic was reported. The ZIP SHA-256 is `6cdaa0c3bab1d9aaa10d534bd2a7332f5ad797c9c03772c0c395ac8b6d2f6773`.
