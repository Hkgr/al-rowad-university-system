# Course Result Symbols - Phase 3 acceptance report

Date: 2026-10-17 (Asia/Damascus). **Release verdict: BLOCKED.** This is an evidence/test/operator-handoff change, not a new academic implementation or permission grant. No deployment, production command, production SQL, real student fixture, migration, dependency upgrade or new lifecycle writer.

## Baseline and scope

Fetched `origin`; PRs #167 and #168 are merged. Actual base: **`65bb4f499f439f4c5a49c9de55579a721e8a7a23`**, including Phase 2 head `a3cd80612fdb86ffa79ef2eb37de6987bb359b82`. New branch: `feature/course-result-symbols-final-acceptance`. The application source, existing SQL installer, permissions, lock boundaries, calculations, frontend and dependencies remain byte-for-byte at that base. The new test helper and one harness invocation extend acceptance coverage only. The PR head identifies the final report/test revision; all application-behavior results below test that unchanged merged application.

No demonstrated application defect was found in the executed focused checks, so no speculative application fix was made. Existing broader-suite failures are recorded, not suppressed or fixed by weakening tests. No earlier verification is silently presented as a new result.

### References reviewed

- `api/contracts/grades.md`, [Phase 2 guide](course-result-symbols-phase2.md), lifecycle/read/deadline services, outcome/schema guards, canonical GradeService, registration/finalization boundaries, manual SQL package, React workflow, symbol/transcript/PDF code and tests.
- All 12 rendered pages of the supplied symbols-definition PDF, read-only. F and Z remain different outcomes at zero points; W and unresolved I are not numeric zero; history/repeats remain visible. Approved decisions, not the reference's open policy choices, control authority, summer, individual F/Z expiry and current-versus-frozen metrics.
- Structure only from the supplied `alrowad_uni_rust16-10-2.sql`: signed INT identities, BIGINT lifecycle events, nullable result marks, recorded calendar and lifecycle columns. **No INSERT records were imported.** An export omitting owned-table/trigger metadata does not disprove the user's live `SCHEMA | READY` check and is not a reason to rebuild production.
- Reference-only `Report2` at `9dfe2a7523c973975b24f40d008c832d92573670`: old GradeService/migration/test ideas were inspected, not merged. Its old result-column lifecycle, global expiry policy, downgrade and deregistration implementations are not adopted. The current dedicated case/event writer, per-case expiry and historical snapshots remain authoritative. No Excel workbook was supplied in the current references or found in the scoped repository/Report2 inventory; no Excel recalculation/import parity is claimed.
- `frontend/AGENTS.md` and the existing references: `ManualGradeEntryPage.jsx`, `StudentManualGradePage.jsx`, `RegistrationGridRow.jsx`, `ManualGradeDialog.jsx`, `ApprovalsPage.jsx`, `ExamStudentAcademicRecordPage.jsx`, `StudentTranscript.jsx`, DashboardLayout, DataTable, FilterBar and shared PDF generator. Source inspection did not establish rendered visual equivalence.

## Actual environment and isolation

| Item | Executed environment |
|---|---|
| Host | Windows / PowerShell; synthetic temporary databases only |
| PHP / Composer | PHP 8.4.14 ZTS x64; Composer 2.8.12 |
| Laravel / React / router | 13.15.0 / 19.2.7 / 7.18.0 (existing installed dependencies) |
| PHPUnit | 12.5.30; existing `backend/vendor` |
| Node / npm / Vite | v24.11.0 / 11.6.1 / 8.0.16; existing `frontend/node_modules` |
| Database | **`SELECT VERSION() = 10.11.18-MariaDB`**, **`@@tx_isolation = REPEATABLE-READ`** |
| DB binary | Official portable 10.11.18 Windows ZIP, not WAMP replacement or a system-service installation |
| Browser | Allowed inventory returned `apps:[]`, `browsers:[]`; in-app tab creation returned `Browser is not available: iab`; inventory rechecked before handoff, still empty |

Official binary/checksum source: [MariaDB 10.11.18 Windows packages](https://dlm.mariadb.com/browse/mariadb_server/10.11.18/winx64-packages/). ZIP SHA256, checked before executing: `1e36bb0834718e56fd53e150978b55429e5da921a97c07c16b41086c0c61099c`. Portable binary unpacked outside the repository. No application dependency was installed or upgraded.

The existing PowerShell harness creates a new marked temporary datadir, loopback port, random test credentials and named synthetic database. PHP verifies actual port/datadir/database/marker before DDL, mutations or shutdown, ignores repository `.env` and configuration/route caches, and uses array sessions/queue/mail. The added acceptance helper repeats this isolation check. It uses the real HTTP kernel, Sanctum test authentication and real middleware/controllers/services against that server; no endpoint responses or academic services are mocked. This is **in-process HTTP integration, not a running browser/Laravel web-server integration test**. Guarded isolated shutdown succeeded.

The installed-catalog fixture exercises real current repository revision routines/triggers, controls, assignments and memberships. It is a populated synthetic projection, **not** a restoration of the entire university database. SQLite is used only for the separately labelled existing Laravel tests.

## Requirement-to-evidence matrix

`CRS` = `backend/tests/Feature/CourseResultSymbolsBehaviorTest.php`; `READ` = `IncompleteResultReadBehaviorTest.php`; `MDB` = existing MariaDB harness plus new `tests/Support/IncompleteMariaDbAcceptance.php`; `REACT` = actual ReactDOM/router tests with synthetic fetch/no layout; `PURE` = dependency-free Node tests. Passing backend evidence does not imply the visible screen or actual PDF was inspected.

| Requirement | Executed evidence | Acceptance boundary |
|---|---|---|
| B + equal-credit Z/F gives GPA 1.50 | MDB `calculations()`, CRS B-plus-Z/F; approved 80-mark course = 3 points plus zero-point 3-credit F/Z | Backend PASS; visible/export agreement pending |
| F/Z with absent numeric marks; Z with retained 100 | MDB calculations and canonical finalization/race checks; CRS absent-mark F/high-mark terminal tests | PASS on 10.11.18; not an approval exemption |
| W/I excluded; later W/I cannot erase earlier F/Z; passing retake selected | MDB calculations; CRS repeat-selection/status cases; executive SQL/GPA tests | PASS; no new formula/bands |
| Genuine zero versus NULL; completed components locked | CRS component variants and zero; READ projections; REACT settlement/semantic builder | Backend/component PASS; canvas/PDF appearance pending |
| W without result; nonempty full withdrawal visible and counted | MDB calculations; CRS full-withdrawal test | Backend PASS; no study-duration enforcement added |
| Pending/withdrawn mixed term not full withdrawal | MDB calculations; CRS pending-registration test | PASS |
| Attempt/history/GPA-denominator/earned/workload hours distinct | CRS frozen/current tests; GradeService term/transcript projections; PURE and semantic PDF labels | Backend/semantic PASS; visual agreement pending |
| Active I survives finalization while unrelated students finish | CRS active-I finalization, all-state ordinary-write guards | SQLite behavioral PASS; full browser path pending |
| Expired F/Z incomplete work does not block others, invent marks or reset announcements | MDB finalization/expiry, Z settlement, restrictive source FK; CRS explicit F/Z and unrelated-student tests | PASS; 10.11.18 service/concurrency evidence |
| Resolved/expired outcome immutable under ordinary/legacy writes and later finalization | CRS all-state cases; MDB preserved snapshots and finalization races | PASS; no silent evidence repair |
| Frozen decisions/terms remain historical, current metrics separate | CRS `test_frozen_term_and_previous_decisions_stay_historical_while_new_progression_uses_settled_gpa` | SQLite real HTTP PASS; no historical decision rewrite |
| Registration drop/withdraw/cancel cannot orphan any lifecycle state | CRS canonical transition matrix + actual self-drop route; MDB grant versus drop/withdraw/cancel and ordered races | PASS in fallback and installed paths |
| Supplementary evidence conflicts, no rewriting | CRS explicit deferral and existing target guards; selected supplementary tests | Focused boundary PASS; broader historical supplementary failures remain below |
| GET creates no academic records or grant | READ no-write/query-count/privacy tests; MDB real middleware registration GET query log | PASS |
| Officer, delegated VP, super-admin, inactive/ordinary/missing permission/scope | CRS real routes; MDB `httpLifecycle()` including independent offering scope | PASS; no new grant |
| Explicit reason/requirements and F/Z; terminal locks; completed-registration resolution | CRS + READ; MDB actual HTTP grant/deadline/resolve/terminal rejection | PASS |
| Damascus day 1 / inclusive final second / summer / year rollover | MDB `calendar()` and CRS boundary/calendar tests | PASS on 10.11.18, not client-clock arithmetic |
| Published non-enforcement calendar; cancellation/replacement/manual fallback; frozen source | MDB real HTTP suggestions/grant/cancel/supersede/replace and deadline expiration; CRS missing/ambiguous source tests | PASS; no rewrite of accepted deadline |
| UUID replay before current revision, including after terminal state | MDB original grant acknowledgement replayed exactly after deadline revision and completed resolution | PASS; no automatic mutation replay |
| List filter options, pagination, URL/deep links/return context | READ HTTP + MDB filtered list; REACT list/router events | Backend/synthetic component PASS; native/live reload pending |
| Invalid/missing/mismatched acknowledgements retain proposal and original request | REACT malformed JSON/null/negative/incomplete/identity/revision cases, PURE acknowledgement contract | 32 workflow regressions PASS; live transport fault injection pending |
| Lost response -> GET -> exact replay -> 409/422 -> deliberate review/discard | REACT actual component sequences; failed reads/repeated uncertainty/terminal/auth-loss cases | Synthetic component PASS; original method/target/UUID/body preserved; live pending |
| Dirty back/navigation, double click, identity loss, late responses | REACT actual router/events with synthetic no-layout host | PASS as component evidence only; native focus/keyboard/visual pending |
| Schema upgrade/resume/reapply/rejection/append-only/FKs | MDB existing installer and 11 incompatible-definition cases | PASS on actual 10.11.18; no new SQL |
| Bounded expiry makes progress past blocked oldest with visible errors | MDB three independent CLI processes sharing durable file cache, `batch=1`; CRS regression | PASS; blocked case/history unchanged |
| Real Exam/VP/student browser screens at 390/768/1440 | Allowed browser unavailable | **NOT EXECUTED - release gate** |
| Real export, actual multipage PDF opened, Arabic/font/logo/A4/page boundaries | No permitted browser/export action available | **NOT EXECUTED - release gate** |

## Executed commands and results

Run from repository root unless a working directory is shown. Complete selections are recorded below so passing counts are reproducible, not an invented `npm test` command.

### MariaDB

```powershell
powershell -NoProfile -File backend/tests/mariadb/course-result-symbols.ps1 -BinaryDirectory '<isolated-portable-10.11.18>/bin'
```

Expanded run and final-source repeat in a fresh datadir: **OVERALL PASS / guarded shutdown** on 10.11.18 REPEATABLE-READ. The [retained final console output](evidence/course-result-symbols-phase3-mariadb.txt) contains no database credentials or production records:

- Populated legacy upgrade, unchanged result/announcement/revision-trigger records, compatible partial resume and complete reapplication.
- Added real HTTP lifecycle and authorization coverage, original and terminal replay, pending/official/completed-registration reads, filtered list and GET no writes.
- Added calendar cancellation/replacement/frozen deadline, inclusive second, summer/year rollover and canonical GPA/withdrawal/repeat calculations.
- **22** independent-process concurrency scenarios: 18 two-waiter InnoDB observations (nine fallback, nine installed catalog), plus four ordered held-lock/in-flight-FOR-UPDATE observations. The latter are not claimed as InnoDB wait-table samples.
- **11** retained-name incompatible definitions rejected by preflight BLOCKED / verify FAIL / runtime 503 / apply controlled refusal, preserving data and the rejected definitions: deadline type, text capacity, nullability, UUID unique sequence, revision index order, FK target/rule, CHECK, no-op body, trigger timing/target.
- Three separate CLI expiry invocations sharing file-cache progress; oldest blocked unchanged, later valid case expires, blocked error revisited visibly. Append-only history, restrictive FKs and destructive rollback refusal.

An initial **unmodified-harness** run reached 10.11.18 upgrade, all races and ten negative-schema cases, then failed with native MariaDB error 6 deleting a shared-temp `#sql-temptable-*.MAI` file (`Errcode: 2`). It was shut down through the guard. This was not reported as PASS or repaired by weakening validation. The expanded fresh-datadir run completed all checks; the initial transient cause was not established. If it recurs, retain the server log and investigate the isolated host/temp filesystem before accepting a run. No runtime code change is justified by this single environment error.

### Laravel

From `backend`, `php vendor/phpunit/phpunit/phpunit <selection>` with JUnit logs outside the repository:

| Selection | Actual result |
|---|---|
| CRS behavior + contract; READ behavior; ExecutiveReportsPhase1 behavior + contract; ExamManualGradeEntry/Grid/Preparation behavior; ExamStudentAcademicRecordAuthorization; StudentSelfAcademicRecordAuthorization | **179 tests / 2,220 assertions PASS** |
| Supplementary partial-theory, registration, period/offer/eligibility readiness/absence, Phase7 lifecycle + authorization; AcademicCalendarPhase2Service; SemesterRegistrationEligibilityPhase3GradeBoundary; academic-record Service + Policy; transcript export contract | **211 tests: 148 passed / 11 failures / 52 errors, 452 assertions** |
| SemesterRegistrationMinimumCancellationReplacementPhase6Behavior | **15 tests: 11 passed / 1 failure / 3 errors, 77 assertions**; two risky tests remain |
| SemesterRegistrationModificationsPhase5Behavior | **Discovery blocked** by private `seed()` versus Laravel public `seed()`; no executed/pass claim |

The expanded selection is intentionally broader than the previous-phase selection and includes inherited readiness cases in both classes. Its counts are not compared to a different historical selection. A clean detached worktree at exact **65bb4f4** executed the same selections using the same installed dependencies (autoload paths remapped to the clean tree). All **63** expanded failing/error identities **and causes** matched; only worktree paths, generated Mockery serials and two fixture `now()` timestamps were normalized. All four Phase6 failure/error identities/causes matched after additionally normalizing Carbon object identities (not their values), and the Phase5 discovery fatal reproduced as well. See the [sanitized baseline evidence](evidence/course-result-symbols-phase3-baseline.json).

Remaining causes: supplementary fixtures missing component timestamps (2 errors), SQLite named-FK-drop support (14), supplementary schema-readiness assumptions (9), DataScope Mockery expectations (12), calendar fixtures missing `users.username` (15); readiness/source-count/string expectations (10 failures) and the unchanged mark-90 band expected as 4.0 instead of canonical 3.5 (1 failure). These are visible baseline issues, **not** proof that all affected supplementary/registration workflows passed. No old test was edited to hide them.

### Frontend and static checks

From `frontend`:

```text
node --test tests/*.test.mjs
node --test --test-concurrency=1 tests/components/incomplete-results.mjs tests/components/incomplete-transcript-presentation.mjs
node --test tests/incompleteResults.test.mjs tests/manualGradeEntry.test.mjs tests/manualGradeDraft.test.mjs tests/manualGradeGrid.test.mjs tests/manualGradePreparation.test.mjs tests/manualGradePresentation.test.mjs tests/examBoardAcademicRecord.test.mjs tests/examBoardTranscriptPdf.test.mjs tests/studentTranscriptExportUnification.test.mjs
node --test tests/authLandingRoute.test.mjs tests/vpAdministrativePortal.test.mjs tests/pdfPagination.test.mjs
npm run build
```

| Check | Actual result |
|---|---|
| Focused pure/static selection above | **59/59 PASS**, plus **18/18 PASS** auth/VP/pagination |
| Real ReactDOM/router, synthetic API, no-layout host | **32/32 PASS**; not live backend/browser |
| Real shared transcript HTML builder, fake measurement | **1/1 semantic PASS**; not actual PDF geometry/download |
| Full Node | **422 tests: 408 passed / 14 failed**; exact same 14 identities and normalized full assertion/import causes on clean 65bb4f4, not counts alone |
| Focused workflow/symbol/PDF source ESLint (four files) | PASS |
| Broader affected eight-source ESLint selection | **2 errors / 1 warning**, exactly reproduced on clean base: ApprovalsPage:125 and ExamStudentAcademicRecordPage:169 `set-state-in-effect`; StudentTranscript:202 `exhaustive-deps` |
| Build | **PASS**, Vite 8.0.16, 985 modules; existing >500kB chunk warning, not hidden |
| Output assets | `dist/index.html` references generated `/assets/` files; CSS references all three existing hashed Cairo WOFF2 subsets; logo present in dist. File/link inspection only, not rendered-font evidence |

The 14 Node failures include old administrative-home/scientific-home/nav source assertions, extensionless supplementary-overview import under native Node, owner-payroll source contracts, and guide nav/link/diagram assertions. Every identity/cause is retained in the baseline evidence; no full-suite-green claim.

Other executed checks: PHP syntax for both added/changed PHP test files PASS; `php backend/tools/render-incomplete-schema.php --check` PASS (no rendering/applying); Phase1 and Phase2 static contracts PASS (Phase2 13 source checks), manual entry/grid and transcript-unification static contracts PASS; from `backend`, `composer validate --no-check-publish` and `composer check-platform-reqs` PASS; `git diff --check` PASS. No project dependencies installed; no package/lock changes.

Additional twelve dependency-free PHP contracts: **11 PASS / 1 FAIL**, with the same results on the exact clean base. Calendar Phase3/4/5/6, registration deadlines/eligibility/timetable/modifications/minimum-cancellation, academic record and supplementary end-to-end hardening PASS. `academic_calendar_schema_compatibility_repair_contract.php` FAILS its historical PR-scope assertion that `AcademicCalendarPolicyService.php` must not exist; the service is already in develop. This phase's no-SQL diff lets the supplementary hardening scope check pass; older Phase1 results for that check are not reused. These are static contracts, not behavior evidence.

The expanded Laravel command's exact file arguments, from `backend`, were:

```text
php vendor/phpunit/phpunit/phpunit tests/Feature/SupplementaryExamEligibilityPartialTheoryBehaviorTest.php tests/Feature/SupplementaryExamRegistrationBehaviorTest.php tests/Feature/SupplementaryExamPeriodSchemaReadyRuntimeTest.php tests/Feature/SupplementaryExamOfferingSchemaReadyRuntimeTest.php tests/Feature/SupplementaryExamEligibilitySchemaReadyRuntimeTest.php tests/Feature/SupplementaryExamEligibilitySchemaAbsenceRuntimeTest.php tests/Feature/SupplementaryExamPhase7LifecycleHardeningTest.php tests/Feature/SupplementaryExamPhase7AuthorizationMatrixTest.php tests/Feature/AcademicCalendarPhase2ServiceTest.php tests/Feature/SemesterRegistrationEligibilityPhase3GradeBoundaryTest.php tests/Feature/ExamStudentAcademicRecordServiceTest.php tests/Feature/ExamStudentAcademicRecordPolicyTest.php tests/Feature/StudentTranscriptExportUnificationContractTest.php
```

## Unexecuted acceptance and exact completion path

**No current application screenshots, no live browser integration, no exported transcript PDF samples generated/downloaded/opened.** The read-only reference PDF is not a generated acceptance sample. No raw CDP, external browser automation or sandbox-policy bypass was attempted. Browser rechecks are evidence of a blocker, not a passing test.

When an allowed browser becomes available, use only a marked isolated Laravel/MariaDB test environment, verify actual database/port/datadir and a localhost `VITE_API_BASE_URL` **before** authentication or any write (the API client otherwise defaults to production). Start real React and Laravel there with synthetic identities and records. Do not use response interceptors for the live integration claim. Reproduce:

1. Exam Affairs list/detail/registration routes and Administrative VP local office 735 equivalents, including direct reload, URL filters/page/back and scope denial; student `/student/transcript`, manual entry, academic record and approvals links. Verify database state after reload.
2. Grant/explicit F/Z/manual and calendar source, deadline edit/overdue extension, single/mixed components and completed zero; completed-registration settlement before/after offering approval. Confirm ordinary locks, unaffected students/approval rows/timestamps, all terminal states.
3. Dirty/sidebar/back/native dialogs, focus/keyboard, repeated clicks, late reads and identity loss; isolated transport fault injection for all acknowledgement/lost-response/failed-GET/409/422/exact-replay sequences. Compare immutable request bytes/UUID and persisted events, with no automatic mutation replay.
4. At **390, 768 and 1440px**, capture each route and actual reference components, Arabic errors, empty/loading/denied states, controls, focus and overflow. Record exact commit, fixture and viewport for each screenshot. Current design acceptance remains pending until these images are actually compared.
5. Use the **existing export button** to download a long multipage transcript with I, terminal F/Z, W with no result, NULL and zero plus a withdrawal-only term. Open the downloaded PDF, retain the synthetic sample, compare symbols/hours with the same backend record and screen, inspect Cairo/Arabic/logo/A4/margins/repeated headers/numbers/page boundaries/electronic disclaimer and absence of internal reasons/requirements/actors/events. Fake measurements or HTML strings cannot close this gate.

Broader baseline failures also require explicit release-owner triage or separately scoped repair; this report does not waive them. No production-data or exhaustive-stress acceptance is claimed. See the [operator handoff](course-result-symbols-operator-handoff.md) for starting-state-specific, non-destructive release instructions. **Keep the PR Draft/open and do not release on this report's passing subsets alone.**
