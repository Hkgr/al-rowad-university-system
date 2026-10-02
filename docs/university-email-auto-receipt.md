# University Email: automatic receipt after credential actions

## Scope and base

PR151 was already merged. This is the separately authorized frontend-only follow-up on `codex/university-email-auto-receipt`, based on fetched `origin/develop` `8f0b7ba47f0ebb4cf8809a2c783af4e967e8f413` (the PR151 merge). It does not change the merged handover correction, authorization, provisioning state machine, API, or audit semantics.

Design references are the current `UniversityEmailPage`, `UniversityEmailMailboxDialog`, `UniversityEmailDialog`, `MinistryUi`, `UniversityEmailReceipt`, and `emailReceiptPdf`. Their existing Cairo/RTL styling, modal, receipt, colors and spacing are reused. No separate design draft or redesign is introduced.

## One explicit action, including its receipt attempt

| Explicit user action | Existing account endpoint | Automatic follow-up |
| --- | --- | --- |
| Create | `.../students/{student}/create` | Exact confirmed credential receipt, then PDF |
| Safe retry creation | `.../students/{student}/retry-create` | Same, after canonical safe cancellation/retry |
| Recreate deleted account | `.../students/{student}/recreate` | Same, for the new current cycle only |
| Password reset | `.../students/{student}/reset-password-now` | Same, for the returned `password_reset` operation |
| Activate, suspend, delete, link | Existing endpoints, unchanged | No automatic receipt or PDF |

All endpoints use the existing `/api/v1/technical/university-email` prefix (the frontend API adapter uses `/v1`). There is no additional remote creation/reset or automatic replay. After confirmation, the UI retains the confirmed email and RAM-only password, requests the existing `POST .../provisioning/receipt` with exactly the returned `operation_id` and `generation`, verifies the receipt belongs to that operation, student and address, commits the receipt portal through `flushSync`, and uses the existing font/logo/canvas/A4 PDF helper. It never selects the latest historical operation instead.

The UI displays «جارٍ تجهيز الإيصال…» followed by «تم تنزيل الإيصال». The normal flow no longer requires another download click. Success retains copy email, copy password and finish controls; «إعادة تنزيل الإيصال» is secondary. Reset confirmation uses «تأكيد إعادة التعيين» / «جارٍ إعادة تعيين كلمة المرور…».

The operation stays pending through both the account action and the receipt attempt. The synchronous in-flight ref prevents double execution before React rerenders. Existing close, student-switch, SPA navigation and unload guards remain active through that attempt. After it finishes or fails, the pending lock is released. Authorization/context loss still suppresses stale work and clears secrets immediately through the existing authorized workspace lifecycle; it is not overridden by the pending lock.

## PDF failure does not undo an account

Receipt API/render/font/logo/capture/save failures have a separate notice: the account action remains confirmed and its password stays in this authorized window. Redownload invokes only receipt metadata plus PDF generation, never creation, reset, password generation or mutation recovery. A lost account-write response still follows the existing read-only recovery; it does not print an unconfirmed password or replay the write.

`jsPDF.save(..., { returnPromise: true })` is awaited. This means the library has completed its save invocation; the application cannot certify that the browser/user/OS persisted the file. That distinction also applies to the UI completion label. Receipt generation/download does not mark delivery, alter `handover_status`, upload a PDF, persist secrets, print automatically or substitute the downloader for the credential issuer.

## Executed verification — 2026-10-02

| Check | Actual result / evidence boundary |
| --- | --- |
| All dependency-free Node tests | **311 passed**, including **19 new automatic-receipt tests**; pure-logic/injected transport and source wiring, not React interaction acceptance |
| Email Node subset | **64 passed**; affected old label/extraction assertions updated to the shared flow, other lifecycle/authorization assertions retained |
| Live isolated Laravel helper integration | Passed create → automatic receipt metadata; reset → injected PDF failure → receipt-only retry; delete/recreate → current-cycle receipt with the same root. Real HTTP/middleware/services + synthetic SQLite, upstream Mailcow faked with stray requests prohibited. Production orchestration helper executed; renderer/PDF injected. **Not React rendering, a browser download or visual PDF verification** |
| Targeted existing Laravel email suite | **154 tests / 2819 assertions passed** across phase1/2/3, super-admin create, creation retry and lifecycle test files; synthetic isolated data, no live upstream |
| Fresh isolated HTTP fixture export | **1 test / 5 assertions passed**, separate from the above suite |
| Six dependency-free PHP email contracts | Passed phase1/2/3, super-admin create, safe creation retry and lifecycle contracts; static verification only |
| Receipt React SSR | Passed initial/reset **24/64-character** passwords and long synthetic Arabic/name/address variants; SSR output, not browser layout/PDF inspection |
| Production frontend build | Passed Vite build, local Cairo assets emitted; existing >500kB chunk warning remains |
| Changed production frontend file ESLint | Passed dialog, orchestration helper and PDF helper; no claim of whole-repository lint |
| Composer | `validate --no-check-publish` and `check-platform-reqs --lock` passed |
| Whitespace/diff review | `git diff --check` passed; frontend-only production changes, tests and this guide |

The new 19 tests execute creation/recreation/safe retry/reset, exact receipt identity, API/PDF failure isolation, retry without a second account request, delayed write/PDF and double-click guard, lost write response, unconfirmed credentials, stale/unauthorized suppression, and no PDF for noncredential actions. Close/navigation component behavior is checked by wiring contracts; actual browser interaction remains unexecuted.

The live helper check is reproducible with a **fresh** fixture exported by the existing opt-in `UniversityEmailPhase2Test::test_export_isolated_phase2_browser_fixture_when_requested`, with lifecycle fixture enabled. Run the existing test-only router at `127.0.0.1:8109`, `APP_ENV=testing`, private TEMP SQLite, array cache/session, null logging and a 64-character test password. Set `UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR` to that fixture and run `node tests/browser/university-email-auto-receipt-http.mjs` from frontend. The router refuses non-test/non-TEMP/non-isolated databases, uses only synthetic actors, fakes Mailcow, prohibits stray requests and asserts remote calls are outside transactions. Stop the owned test server afterward. Private synthetic tokens/database are never committed or logged.

## Unexecuted verification and manual acceptance

- The required in-app browser bootstrap failed before any execution: `codex/sandbox-state-meta: missing field sandboxPolicy`. No standalone-browser/CDP bypass was used. Real React connected to Laravel, desktop/mobile interaction, PDF file download/opening, Arabic/logo/24–64-character layout and visual inspection are **unexecuted**. This is not full visual/end-to-end acceptance.
- No MariaDB concurrency suite was rerun for this frontend-only increment. SQLite/injected tests do not prove production database locks. Backend implementation is unchanged.
- Whole PHPUnit suite discovery has an unrelated existing failure: `AcademicCalendarPhase5OccurrenceResponseTest.php:94` overrides final `TestCase::result()`. The six explicitly targeted email files were executed successfully; whole-suite success is not claimed.
- No new dependencies or test framework were installed. Browser/component runtime verification must be completed when the permitted browser environment works.

Remaining manual checks with **isolated Laravel + fake Mailcow + synthetic students only**, at 1440px and 390px:

1. Create, safe retry, recreate and reset: one explicit credential-action confirmation must initiate one receipt download without another click; inspect both 24- and 64-character PDF files for A4, complete LTR email/password, Arabic/logo/instructions/issuer and secrecy notice.
2. Fail receipt API, font/logo/capture and browser save separately. Confirm credentials remain visible and redownload only requests metadata/PDF, without another Mailcow POST or password change.
3. Delay account and PDF responses; double-click, close, sidebar/back navigation and student-switch must not interrupt pending work. After failure/finish the UI must unlock. After authorization loss/end/student change no old password/receipt may remain.
4. Unconfirmed/lost account response must not download a password. Noncredential actions must not automatically download. Receipt download must not change handover/delivery state.

## Delivery boundaries

**NO NEW MIGRATION. NO SQL. NO PRODUCTION CHANGES. NO LIVE MAILCOW WRITES.** No backend, permission, schema, dependency, PDF document design, or historical receipt/audit change. Merge/deployment are not performed by this task. After review, normal frontend build/deployment is sufficient; no database step or permission command is introduced. The follow-up PR remains OPEN and unmerged.
