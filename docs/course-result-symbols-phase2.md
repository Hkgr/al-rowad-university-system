# Course Result Symbols — Phase 2

Scope: Incomplete workspace, additive authorized reads, and shared academic-record/PDF presentation. Phase 1 remains the sole lifecycle writer and calculator. No new SQL, migration, permission, dependency, scheduled task, production connection or Phase 3 work.

## Design and routes

User approved version 2 of [the synthetic review draft](https://p.superdesign.dev/draft/966e7895-2207-4c87-8113-8520c59fc3bc). This approved the direction, **not visual/runtime acceptance**. References: `ManualGradeEntryPage.jsx`, `StudentManualGradePage.jsx`, `RegistrationGridRow.jsx`, `ManualGradeDialog.jsx`, `ApprovalsPage.jsx`, `ExamStudentAcademicRecordPage.jsx`, and existing DashboardLayout/Header/Footer, DataTable and FilterBar. Existing Cairo/RTL, card/field/dialog tokens, shell, table and pagination are reused; no global design/component changes. The prototype contains synthetic persons only and is not application data.

| Route | Purpose |
|---|---|
| `/exam-board/incomplete-results` | All states by default; server pagination and URL state/student/year/semester filters |
| `/exam-board/incomplete-results/:caseId` | Current state, actual result/components, history and allowed actions |
| `/exam-board/incomplete-results/registrations/:registrationId` | Contextual read/grant/settlement entry, including completed registrations |
| `/vp/administrative/exams/incomplete-results` and the same two suffixes | Identical workspace inside office **735**, with local office navigation |

All six routes/navigation reuse `ACCESS.manualGradeEntry`. Standalone routes are in the existing manual-entry group, **not** the broader group that adds `exams.view`. Administrative routes retain the existing VP shell/access predicate and server-declared office authority. No new role or privilege is inferred. Registration-row and authorized Exam Board record links address the exact registration, not a course guessed from a period. Student views have no internal actions.

## API and data boundaries

Existing six endpoints/envelopes remain:

| Method | `/api/v1/exams/incomplete-results` suffix |
|---|---|
| GET | `/` |
| GET / POST | `/registrations/{registration}` — read / grant |
| GET | `/{case}` |
| PATCH | `/{case}/deadline` |
| POST | `/{case}/resolve` |

`IncompleteResultReadProjection` adds whitelisted student ID/number/name, course ID/code/name, actual year/semester/offering and registration status. Page relations are eager-loaded; no per-row request or full student/account serialization. The list additionally checks actual registration/student identity. Original case attributes and paginator keys remain compatible.

`filter_options.terms` is a distinct query from that same authorized case projection, independent of selected state/period; student narrowing applies. It returns at most 100 term pairs plus `complete` and `limit`; overflow is disclosed and the UI suggests narrowing by student. Case results are not truncated. No generic academic CRUD permission or all-case browser download is needed. Empty scoped cases legitimately yield no period choices; grant remains contextual to an actual registration.

Registration/case reads add actual required theoretical/practical `components`: IDs, names, types, maxima, marks, editability and blockers. Non-NULL completed marks, **including zero**, are locked. Only missing required marks are sent; with all marks complete the optional `components` field is omitted. `resolution.available` additionally checks real component/policy compatibility through GradeService; missing setup is not invented. `current_result` uses persisted result identity and canonical GradeService formatting, with offering approval state. Reason, requirements, actor identity and events remain restricted to authorized Exam Affairs endpoints. Event actor labels use recorded actor IDs, not the viewer.

No grant/write occurs during GET. No writer method, calculation band, eligibility rule, lock order, schema readiness contract, supplementary ownership, canonical approval/finalization or announcement timestamp was changed.

## Mutations and conflict handling

- Grant requires nonblank reason/requirements, an explicit F or Z (nothing preselected), and either the server's published-calendar suggestion or a manual date. Dates are submitted unchanged for server interpretation as end-of-day Damascus. Accepted dates are displayed with `Asia/Damascus`, independently of the device timezone; no deadline is calculated in React.
- Deadline editing has no invented justification. Terminal cases have no reopen/ordinary-edit action. Resolution fills only actual missing required marks via the dedicated canonical operation.
- Confirmation identifies student/course/actual period and proposed changes. The attempt freezes method, route/target, UUID, revision and serialized content. A synchronous write guard prevents double clicks. There is no automatic mutation retry.
- A success HTTP status alone is not an acknowledgement. The envelope must have `success:true`, `data.changed:true`, a valid case/registration identity, the operation's successor revision and matching resulting lifecycle state. A replay is checked against the **frozen original attempt**, not the newest GET state. Invalid/missing JSON, a negative/incomplete envelope, or a mismatched identity enters uncertain recovery without clearing the proposal or reporting success.
- After lost/5xx response or invalid acknowledgement, fields and original attempt remain locked. A **successful current-state GET after each unknown response** is required before explicit exact replay of the same method/UUID/body. Failed reads do not supply this proof, nor does an earlier successful GET authorize another replay after a subsequent unknown response. It never creates a replacement UUID automatically. Rebase/discard is disabled while uncertain; navigation warns that leaving does not cancel the server operation.
- Canonical HTTP 409/422 explicitly rejects that invocation; it does **not** prove an earlier lost invocation never committed. The proposal/baseline and immutable original request remain locked in a rejected/review-required state, separate from the current server read. An automatic GET is read-only. Deliberate review/re-preparation requires a new successful read and still-eligible action/component IDs; otherwise the user may explicitly discard. Re-preparation, not rejection or refresh, releases the old attempt for a separately confirmed new UUID. Terminal cases cannot reopen. A failed read also disables beginning a replacement draft, even after discard.
- Native unload and data-router blockers protect drafts and pending writes, including sidebar/back navigation. Cancel retains the proposal; pending writes cannot be discarded mid-flight. Reads use AbortController plus active request guards, even if transport ignores abort. Identity/authorization invalidation unmounts sensitive state and bypasses draft confirmation; storage/focus events plus a polling fallback detect changes. Lookup/read/write errors and informational notices are separate.

## Academic records and shared PDF

`resultSymbols.js` is presentation only. Active documented I, legacy undocumented incomplete marks, ordinary missing result, pending approval, terminal resolved/expired provenance, W, NULL and zero remain distinct. Terminal F/Z is not relabelled active I; retained marks do not determine its outcome. No client GPA/repeat/withdrawal/deadline formula was added. Approval rows and manual rows show lifecycle locks/provenance without changing offering-wide submission semantics. Registration/supplementary conflict messages explain the existing I boundary.

Both record screens and the **existing single shared PDF generator** use the helper. Internal reason/requirements/actor evidence is not rendered into the student document. Server-provided full-withdrawal/study-duration flags remain visible, including W with no result. Attempt hours, GPA denominator hours, earned hours and historical hours have separate labels; missing metrics are not invented. Existing A4 branding, disclaimer/signatures, measured pagination and repeated headings remain. Frozen historical snapshots/decisions are untouched; this UI consumes current official record data.

## Initial implementation verification (reviewed head `60d48af`)

Existing `backend/vendor` and `frontend/node_modules` were used; nothing installed. Baseline: `9fa642d0e34a473e809945f1c985602d058d5110` (latest fetched develop).

| Check | Actual evidence |
|---|---|
| New HTTP/SQLite read class | **10 tests, 316 assertions PASS**: real middleware/routes, IDs/options, no GET writes, required components, zero, pending/official lifecycle display, completed-registration resolution, privacy, authorization/scope, pagination and constant query count |
| Final combined Phase 1 lifecycle/contract, new reads, executive SQL/GPA, manual entry and academic-record selection | **106 tests / 1,283 assertions PASS**, including all ten new HTTP cases |
| Additional academic-record authorization/policy/service/self/export selection | **12 tests / 67 assertions PASS** |
| Focused Node auth/manual/search/record/PDF/pagination/VP selection | **77/77 PASS**, including seven new pure contract/state/payload/symbol cases |
| Actual ReactDOM/router events, synthetic API and no-layout host | **10/10 PASS**: grant/deadline/resolve; cancelled navigation then save; zero locked; 409 explicit review; lost response exact replay; pending/double click; identity cleanup; delayed obsolete response; terminal read-only; list return/filter context; local VP navigation |
| Real PDF HTML builder with deterministic measurement adapter | **1/1 semantic test PASS**: symbols/zero/NULL, labels, escaping, withdrawal, disclaimer and internal-evidence exclusion. **Not** real geometry/font/canvas/PDF visual verification |
| Full dependency-free Node selection | **419 tests: 405 passed, 14 failed**. Clean baseline: **412 tests: 398 passed, the same 14 failing identities**. Existing failures cover administrative home source coupling, missing supplementary overview module, historical owner-payroll source checks, scientific home links, supplementary route source coupling and user-guide expectations. Historical tests were not changed to hide them |
| Isolated MariaDB Phase 1 harness | **OVERALL PASS**, exact `SELECT VERSION() = 11.4.9-MariaDB`, `REPEATABLE-READ`: populated upgrade/resume/reapply, fallback and installed catalog paths, existing revision triggers, 22 independent-process concurrency scenarios, 11 negative schema cases, expiration fairness and history constraints; guarded isolated shutdown |
| Dependency-free PHP | Phase 1, new Phase 2 (13 static boundary checks), manual entry/grid, academic record and transcript unification contracts PASS. These are not behavior/visual evidence |
| PHP syntax / Composer | All four changed/new PHP files passed `php -l`; `composer validate --no-check-publish` and `composer check-platform-reqs` PASS |
| Changed-file lint | New files clean. Combined selection still has **2 existing errors** in ApprovalsPage and ExamStudentAcademicRecordPage (`set-state-in-effect`), and **1 existing warning** in StudentTranscript (`exhaustive-deps`). The exact same findings were executed on clean baseline; no new lint error/warning |
| Production build / whitespace | Final Vite 8.0.16 build PASS, 985 modules (existing large-chunk warning); `git diff --check` PASS |

Commands: `node --test tests/*.test.mjs` (no invented npm test script); focused filenames listed in the PR; `node --test --test-concurrency=1 tests/components/incomplete-results.mjs tests/components/incomplete-transcript-presentation.mjs`; PHPUnit selected files; `powershell -File backend/tests/mariadb/course-result-symbols.ps1`; PHP contracts/syntax; Composer validate/platform; changed-file ESLint; `npm run build`; diff check.

### Unexecuted acceptance / operational requirements

Allowed browser recheck returned **no apps and no browsers**; previous in-app-browser creation returned “Browser is not available: iab”. No policy bypass was attempted. Consequently **React + live Laravel/MariaDB end-to-end, 390/768/1440px screenshots/reference comparison, keyboard/accessibility inspection, actual PDF download/opening, Arabic font/logo/page-boundary visual inspection are NOT EXECUTED**. No screenshots, visual acceptance, merge readiness, production-data or MariaDB 10.11 compatibility is claimed. The no-layout React tests and fake measurement test are explicitly not substitutes.

When an allowed browser exists, start isolated Laravel/MariaDB with synthetic users/registrations only; verify list filters/back/deep links and both portals, all three operations with pending/official/terminal cases, conflict/lost-response/identity cleanup at each width; compare the current reference pages; download/open long multi-page transcripts containing I/F/Z/W/NULL/zero and inspect Arabic, page breaks, logo and disclaimer. Keep this acceptance pending in the Draft PR.

Deployment is not performed. Phase 1's manual schema must already be verified READY; this phase has no additional SQL. Preserve its durable writable cache, atomic shared command lock and every-minute expiration scheduler. Deploy the compatible backend read code and rebuilt frontend assets only through the normal reviewed release procedure; do not run tests against live university data.

## Changed-file inventory

- Backend read integration: `backend/app/Services/IncompleteResultService.php` (read methods only), new `IncompleteResultReadProjection.php`.
- HTTP/static coverage: `backend/tests/Feature/IncompleteResultReadBehaviorTest.php`, `backend/tests/Contracts/course_result_symbols_phase2_contract.php`.
- Workspace/state: `frontend/src/features/exam-board/pages/IncompleteResultsPage.jsx`, `frontend/src/features/exam-board/lib/incompleteResults.js`.
- Route/navigation: `frontend/src/app/App.jsx`, `frontend/src/features/exam-board/nav.js`, `frontend/src/features/vice-presidency/utils/administrativeOrganization.js`.
- Context/lock/error display: `frontend/src/features/exam-board/components/RegistrationGridRow.jsx`, `frontend/src/features/exam-board/lib/manualGradeEntry.js`, `frontend/src/features/exam-board/pages/ApprovalsPage.jsx`, `frontend/src/features/exam-board/pages/CourseRegistrationPage.jsx`, `frontend/src/features/supplementary-exams/supplementaryStatus.js`.
- Shared records/PDF: `frontend/src/features/academic-record/lib/resultSymbols.js`, `frontend/src/features/academic-record/lib/transcriptPdf.js`, `frontend/src/features/exam-board/pages/ExamStudentAcademicRecordPage.jsx`, `frontend/src/features/student-dashboard/pages/StudentTranscript.jsx`.
- Frontend tests: `frontend/tests/incompleteResults.test.mjs`, `frontend/tests/components/incomplete-results.mjs`, `frontend/tests/components/incomplete-transcript-presentation.mjs`.
- Documentation: this file and `api/contracts/grades.md`.

All 23 files are implementation/tests/documentation in this phase; no dependency, SQL, migration, permission, production configuration, historic test or Phase 1 writer method changed.

## PR #168 review follow-up — acknowledgements and recovery

Started from verified remote/local head `60d48af8ccd93969644c8f4f96547b7116b6e961`, clean worktree, same `feature/course-result-symbols-phase2-ui` branch. The follow-up changes only two frontend source files, their two existing test files and the two documentation files. No shared API client, backend, writer, rule, SQL, permission, dependency or PDF implementation change.

Before editing application code, 17 new actual ReactDOM/router regressions failed on the reviewed source (27 tests: 10 passed / 17 failed). They reproduced 13 malformed/missing/negative/incomplete/mismatched acknowledgement cases, both lost-response → successful GET → exact replay → 409/422 sequences, stale successful-read proof after another unknown response, and missing accepted calendar-source presentation. Successful test responses now mirror the real `changed + case` mutation envelope instead of the former incomplete `{changed:true}` fixture.

Fixes:

1. `sendAttempt()` validates positive case/registration IDs (and the frozen case ID for deadline/resolve), `changed:true`, the exact successor revision and action-appropriate `active/resolved` state before success. Grant accepts the newly allocated positive case ID. Missing/malformed acknowledgement is an unknown outcome, not inferred rollback/success. Canonical original snapshots replay successfully even after later revisions or a terminal current-state GET; the UI refreshes after acknowledgement rather than using that old snapshot as current state.
2. Recovery has explicit unknown versus rejected states. Neither discards the original request or proposal. The successful-read key is tracked separately from render/failure keys and is invalidated as replay proof on every unknown response. Reconciliation GET failures cannot unlock replay/review/replacement. After 409 or 422, fields remain locked until explicit eligible review/re-preparation or discard; no UUID or mutation is generated by those decisions. Auth loss still removes all sensitive state. Tests also cover terminal-state rejection/discard, confirmed-rejection read failure and authorization denial during recovery.
3. The authorized case details show the accepted manual/calendar source and recorded event/version IDs, version number, period reference and recorded study start when present. Missing reference stays “غير مسجل”; manual sources do not borrow a calendar. Snapshot DB timestamps are interpreted as recorded UTC before Damascus formatting, never the device's implicit timezone. Explicit expiry choices and confirmation label **F — رسوب** and **Z — حرمان**. No internal evidence was added to student/PDF projections.

Follow-up checks actually executed:

| Check | Result |
|---|---|
| Actual ReactDOM/router, synthetic API, no-layout host | **32/32 PASS** (22 added cases plus the original ten); every invalid acknowledgement preserves the draft and prevents a saved notice, exact replay keeps the same serialized request, rejected attempts require explicit decisions, terminal/auth protections remain |
| Existing real PDF HTML builder, deterministic fake measurement | **1/1 semantic test PASS**, not a PDF download/visual check |
| Focused Node pure/static auth/manual/search/record/PDF/VP selection | **80/80 PASS**, including ten Incomplete pure contract/state/presentation tests |
| Full dependency-free Node selection | **422 tests: 408 passed / 14 failed**. Fresh isolated clean worktree of reviewed `60d48af`: **419 tests: 405 passed / the exact same 14 failing identities**; no historical failure hidden or test weakened |
| Targeted Laravel selection re-run | **106 tests / 1,283 assertions PASS**; no backend source changed |
| PHP Phase 1 and Phase 2 contracts | Both PASS; Phase 2 remains 13 static checks, not runtime evidence |
| Changed-source ESLint / production build / diff | Both source files lint PASS without warnings; Vite build PASS, 985 modules, existing large-chunk warning; `git diff --check` PASS |

The full MariaDB harness, Composer/platform checks and PHP syntax checks in the original implementation section are **prior results**, not re-executed evidence for this frontend-only follow-up. No PHP source changed. No dependency installation or live system connection occurred.

**Still pending, not closed by synthetic tests:** live browser React + isolated Laravel/MariaDB integration; 390/768/1440px responsive/reference/keyboard/accessibility inspection; actual PDF download/opening, Arabic/logo/page-boundary visual inspection. No visual/end-to-end acceptance, production verification, MariaDB 10.11 verification or Phase 3 work is claimed. Keep PR #168 Draft, open, unmerged and undeployed.
