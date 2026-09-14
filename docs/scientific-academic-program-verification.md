# Academic program management — executed verification

Date: 2026-09-14. Base: `7751fcf540990314f48fb832f9238acb2a8eb3b1` (re-fetched unchanged before delivery). No production connection, production SQL execution, deployment, dependency installation or automatic permission assignment was performed.

## Executed successfully

| Check | Actual result / boundary |
| --- | --- |
| Targeted Laravel/PHPUnit | **100 tests, 785 assertions passed**: AcademicPlanWorkflowTest, ScientificProgramManagementTest, ScientificCourseManagementTest, ScientificCourseDistributionTest, AcademicProgramManagementContractTest, SupplementaryExamMaterializationBehaviorTest |
| PHP syntax | All **66 changed/new PHP files** passed `php -l` |
| Dependency-free Node | **193 passed**, zero failed; pure logic and source contracts, not rendering proof |
| PHP source contracts | **28 passed**; two historical PR-specific guards failed as explained below |
| Frontend lint | New scientific-programs feature and changed course-management/shared route/navigation/home files passed; Dean file has two reproduced baseline errors below |
| Production build | `npm run build` passed; existing large-chunk warning remains (main bundle exceeds 500 kB) |
| Composer | `composer validate --no-check-publish` and `composer check-platform-reqs --lock` passed with installed PHP 8.4.14 |
| Diff | `git diff --check` passed; existing Git LF/CRLF normalization warnings are not suppressed |

The Laravel tests exercise actual services and SQLite SQL, not mocked academic formulas. They cover transition ID preservation, no implicit approval/default, monotonic ABA conflicts, atomic audit rollback, null/zero requirements, approved/default separation, actual assignment versus default, two plans sharing an offering, classification-changing transfer impact and retained historical registration context, admission/archival guards, pending supplementary/appeal blockers, explicit-draft distribution, API permissions/scope and the existing supplementary materializer.

Before/after comparisons execute official progress, transcript and graduation services for ordinary and repeated approved attempts. A separate invalid graduation-configuration test confirms that fixation retains the same exception and original budget rather than repairing it. This does not assert that every possible production equivalence/profile/history combination was exercised.

Example focused command (from `backend`):

```powershell
.\vendor\bin\phpunit tests/Feature/AcademicPlanWorkflowTest.php tests/Feature/ScientificProgramManagementTest.php tests/Feature/ScientificCourseManagementTest.php tests/Feature/ScientificCourseDistributionTest.php tests/Feature/AcademicProgramManagementContractTest.php tests/Feature/SupplementaryExamMaterializationBehaviorTest.php
```

## Real MariaDB verification — not SQLite lock evidence

Used isolated **MariaDB 10.11.18** instances with independent connections, random credentials and task-created temporary data directories. The checked-in helpers require a loopback connection, a test marker, the expected server version and a disposable data directory; they do not load a production dump. Credentials and snapshots are not checked in.

Executed `academic-plans.php`, `verify-academic-plan-package.php`, `verify-academic-plans.php` and its `--transfer-only` scenario:

- Manual package first apply, interruption after two new tables, compatible resume, verifier PASS, repeated apply, compatible extra column, deliberately missing CHECK detected, restored CHECK verified. SQL itself created no plans or assignments and preserved academic business values.
- Unready control rejected application context access and raw student creation. Existing catalog prerequisite package was also exercised on the disposable instance.
- Student creation before setup invalidated the old revision; fixation first blocked new admission and assigned every previewed existing student.
- A raw curriculum change followed by restoration invalidated the fixation preview.
- Draft editing invalidated approval; approval first prevented a raw academic edit of the fixed membership.
- A held default-selection transaction made a concurrent raw student insert wait and then assign the exact approved default.
- A concurrent new official registration invalidated transfer; transfer first caused the subsequent raw registration to pin the exact new plan/membership. Historical registration context remained unchanged.

These tests prove the exercised trigger/control-lock interleavings. They are not an exhaustive deadlock/load test, a production-data rehearsal, or complete multi-connection execution of every advisor/student workflow. The raw-registration race deliberately tests the database writer boundary rather than claiming it executes the full advisor HTTP workflow. Existing student-locking service entries additionally acquire the common outer lock before their existing internal lock order, verified by source contracts and targeted application tests. Deadlocks/timeouts are controlled conflicts; no automatic mutation retry is introduced for the installed versioned-plan path.

## Browser and visual verification

Executed `frontend/tests/browser/scientific-programs.mjs` against the **production Vite build**, real loopback Laravel and the disposable MariaDB database. **37 actual API requests** passed. Only the authentication/transport bridge supplies a synthetic test identity; report payloads and mutations are real Laravel/MariaDB operations.

Verified:

- Program creation starts preparing without a default or historical approval.
- Missing requirement-group setup preserves the course draft and selected plan, then returns to a separate explicit membership confirmation.
- Course-origin creation and membership linking are separate writes; neither changes graduation budgets implicitly.
- Six blank budgets remain unspecified until explicit save; zero, approval and default are separate actions.
- Cancelled dirty navigation preserves the draft; a second real writer causes a retained-draft 409 conflict with explicit inspection/rebase.
- A dropped response **after a committed write** causes no automatic retry and requires inspection.
- Identity changes immediately remove sensitive editor state; the Administrative VP identity cannot use the Scientific feature.

Captured and inspected desktop/mobile screenshots of the existing Course Management reference, program list, program creation, requirement dialog, approval/default state and conflict state at 1440×1000 and 390×844. The test checks viewport overflow. Reused Cairo/RTL, shell, green theme, table, fields and native dialogs; no independent redesign. Screenshots remain disposable local evidence, not production screenshots or fixture data in the repository.

The in-app browser bootstrap was attempted but returned a runtime `sandboxPolicy missing` error. The existing local Chrome CDP interface was used for the executed fallback. No browser dependency was installed.

**Not executed:** a deployed authentication flow, exhaustive assistive-technology audit, full browser student-transfer/advisor/archived-supplementary journeys, real queue workers, production migration rehearsal, and load testing. Do not infer production or merge acceptance from this report.

## Pre-existing / non-applicable failures retained

A detached worktree at the exact base was tested with the already-installed dependencies to distinguish regressions from baseline failures. No baseline source was changed.

| Existing check | Observed limitation / baseline proof |
| --- | --- |
| DeanRegistrationOfferings lint | Two `react-hooks/set-state-in-effect` errors (`setMinimumReviews`, `setDraftIds`) reproduce on the base at lines 673/693; current equivalent lines 681/701. Other changed frontend files pass. |
| Full PHPUnit discovery | Stops on existing AcademicCalendarPhase5OccurrenceResponseTest overriding final PHPUnit `result()`; separately, SemesterRegistrationModificationsPhase5BehaviorTest declares private `seed()` incompatible with Laravel. Both reproduce on base. |
| AcademicCalendarPhase4RegistrationEnforcement | Base: 23 tests, 22 errors/1 failure; outdated service constructor fixtures. |
| AcademicCalendarPhase4RegistrationRequest | Base: 7 tests, 4 errors/4 risky tests. |
| SemesterRegistrationDeadlinesPhase2 | Base: 31 tests, 4 errors; legacy data-provider annotations with PHPUnit 12. |
| SemesterRegistrationEligibilityPhase3GradeBoundary | Base: 3 tests, 1 failure (expected 4, actual canonical 3.5). |
| SemesterRegistrationTimetablePhase4 | Base: 19 tests, 1 missing-provider-argument error. |
| SemesterRegistrationMinimumCancellationReplacementPhase6 | Base: 15 tests, 2 errors/1 failure/2 risky; existing fixture/provenance and Carbon snapshot issues. |
| SupplementaryExamRegistrationBehavior | Base: 40 tests, 18 errors/5 failures from old fixture/schema assumptions; current run also fails. Existing materialization behavior tests are included in the passing targeted suite. |
| academic_calendar_schema_compatibility_repair_contract | Historical one-PR guard requires the Phase 3 policy service to be absent, although it is already in develop. |
| supplementary_exam_end_to_end_hardening_contract | Historical branch-wide guard forbids any SQL changes; this task explicitly requires the new manual SQL package. Not weakened to make this branch green. |

Transaction-name assertions in three operational contracts were updated to the common plan-aware outer transaction while retaining their original materialization, locator-before-transaction and committed-supersession assertions. The new program contract also requires the legacy DB transaction fallback and every affected writer's outer-lock entry. No runtime failures were represented as passed source contracts.

## Deployment requirement

See [deployment and operator workflow](scientific-academic-program-management.md). Maintenance must stop affected writes/workers while SQL is applied/verified and compatible code is installed and smoke-tested. Do not run the old code against version-aware membership uniqueness. SQL creates no transitional reference or default; operators explicitly initialize each program and select an approved plan before its new admissions resume.
