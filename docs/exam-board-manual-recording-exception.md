# Exam Board: historical manual recording exception

## Base and verified cause

- Branch: `codex/exam-board-manual-recording-exception`.
- Fresh `origin/develop`: `21cfa0da0dd3ac9cf650fabc49986ccdb41dfe23`.
- PR #129 is merged (2026-09-07); its head was `3cfa9111324a82ba34dc1bd647349548bdcd27bf`. No subsequent develop changes were present at branch creation.
- The actual preview called `assertAcademicRegistrationCandidate()`, and save called the same enrollment gates through registration persistence. Current active curriculum, prerequisites, credit ceilings, prior passes and requirement quotas therefore incorrectly blocked recording old marks. Offering selection also considered other programs before prioritizing the student's attempt.
- This document supersedes the narrower gate matrix in `exam-board-integrated-manual-recording.md`. No academic-plan history or approval mechanism is introduced.

## Resulting journey

Search student → dedicated course table → explicitly select **سنة العلامات / فصل العلامات** → enter local draft marks → review reason, old/new values and acknowledgment → atomic save.

The initial page presents period selection rather than an empty mark grid. Period options do not depend on an offering. **استعراض السجل — قراءة فقط** has independent all-year filters; it cannot mutate marks or replace the chosen recording period. Existing green/RTL components and dialogs are retained, not redesigned.

The server first resolves an owned same-course/same-period attempt, even when its program differs from the student's current program. It never creates another attempt to escape its status/locks. Multiple owned attempts require explicit selection. Without an attempt, only current-program offerings within actual scope are candidates. Other-program offerings without this student's registration are excluded from the catalog and resolver. Genuine multiple compatible sections still require selection.

## Explicit boundary and evidence

`EXAM_MANUAL_RECORDING` remains a server-only domain context, selected only after the existing active exam-officer, assigned `exams.manage` + `grades.manage`, student-read policy and independent actual scope checks. No request flag can choose the exception. Checks repeat in preview/save and after mutation locks.

For a **new** registration, persisted relationship evidence is mandatory:

1. A `ProgramCourse` linking this course to the student's program, active **or inactive**; or
2. An owned prior registration whose offering links the same course and program.

Program/department/college, course and selected year/semester must resolve. A section-scoped actor cannot use that section to create a program-wide context elsewhere. With no evidence, both GET and POST return `manual_recording_relationship_missing` without writes. An arbitrary college course is not enough. Existing attempts are reused under ownership/scope/grade locks, not retroactively enrolled. Multiple membership rows are evidence of the same relationship only; no dates, classifications, weights or historical versions are selected/invented from them.

| Requirement | Exceptional recording | Ordinary registration |
| --- | --- | --- |
| Student request / advisor proof | Exempt | Unchanged |
| Current year / student window | Exempt | Unchanged |
| Offering OPEN / weekly timetable | Exempt | Unchanged |
| Prerequisites at recording time | Exempt | Still enforced |
| New-registration 18/21-hour ceiling | Exempt | Still enforced |
| Prior pass in another period | Does not block a distinct historical attempt | Passed-course guard retained |
| Current curriculum selection / requirement quotas | Not enrollment prerequisites for recording | Canonical rules retained |
| Saved academic relationship, identities, actual scope | Required | Unchanged |
| Duplicate/owned attempt, withdrawn/cancelled/deprived/deferred states | No invented retry attempt or implicit reactivation | Unchanged |
| Submitted/approved parts, official approval, supplementary fixed/materialized target | Locked | Unchanged |
| Component policy, decimal precision, zero/null, correction reason | Canonical validation | Unchanged |

No additional capacity or student-status policy is added or removed. These exemptions affect **acceptance of recording**, never graduation/requirement calculations. A missing or invalid current graduation configuration can still make the existing requirements subsection unavailable; this PR does not fabricate a curriculum to make it available.

## Persistence, audit and concurrency

The existing coordinator and canonical mark save are reused. Missing context is created through an explicit authorized `CourseOfferingContextService` recording entry that shares closed-offering persistence with the ordinary creator. The normal creator's active-curriculum resolver and opening behavior are unchanged.

Ordinary tables only: a CLOSED offering, a `registered` student registration, required policy-compatible components, draft component marks, `GradeAuditLog` and `UserActivityLog`. Existing valid components (including multi-component definitions) are reused, not replaced or reweighted. No instructors, approvals, advisor/student requests, attendance, timetable slots or past execution timestamps are fabricated.

One outer transaction rolls back preparation, marks and audits together. Student → course serialization → offering → existing shared grading/registration/component locks remain in place. Preview fingerprints are compared after locks. Existing uniqueness constraints plus course serialization protect context creation; stale repeated confirmations are rejected. No new constraint/table is introduced. Audit records include operator, reason, actual period/record IDs, relationship evidence and exemptions; reused registrations report no enrollment gates waived for that operation.

Save is draft-only. Existing submission, return, approval and finalization produce ordinary results consumed by transcript/GPA/requirements/export/report services, which are not changed. Lost responses preserve proposals and require read reconciliation and explicit rebase/reconfirmation; there is no automatic POST retry. Other row drafts are retained.

## Executed verification

- Locked development dependencies installed with `composer install --prefer-dist --no-interaction --no-scripts` and `npm ci --ignore-scripts --no-audit --no-fund`. Manifest/lock files unchanged; no install scripts, SQL package, migration or seeder executed.
- Real Laravel HTTP/SQLite classes run individually: `ExamManualGradeEntryBehaviorTest` **19 passed**, `ExamManualGradeGridBehaviorTest` **30 passed**, `ExamManualGradePreparationBehaviorTest` **47 passed**. These include inherited cases, not 96 unique scenarios. The expanded class covers current/historical no-context saves, inactive membership, absent prerequisite, overload, prior official pass, prior-attempt-only evidence, missing relationship, other-program isolation, ambiguity, actual scope, locks, zero/null/correction, atomic rollback, stale/repeated confirmation and canonical finalization/transcript/GPA. Negative ordinary-registration tests exercise timetable, prerequisite, credit and prior-pass checks.
- Fixed a pre-existing test helper collision (`seed()` vs Laravel's public method); retained actual SQLite fixtures and unique registration constraint. Corrected the ordinary timetable fixture's mandatory pool hours. Warmed one framework/auth request before the unchanged exact bounded-query comparison.
- **172 dependency-free Node tests passed** (pure logic/source contracts, not browser claims).
- Actual Chrome headless local React/router fixture completed: explicit marks period, independent read-only history, no-context draft/save, uncertain reply recovery, unrelated drafts, 409 rebase, pending writes, sidebar/back/forward/discard/cancel and authorization-loss cleanup. All fetches intercepted with synthetic data. This verifies rendering/component/router behavior, **not live Laravel browser integration**. Fixture timing waits were corrected to await rendered destination/editor state, not URL changes or substring matches alone. A desktop RTL review-dialog screenshot was inspected using actual CSS; no production screenshot or full responsive acceptance is claimed.
- Frontend production build passed; existing large-chunk warning remains. Composer validation/platform checks and changed PHP syntax/diff checks passed.
- **27/28** independent PHP contracts passed. The unchanged old calendar schema-repair contract still requires the now-existing Phase 3 policy service to be absent.
- Global lint ran: **90 errors / 16 warnings**, pre-existing. Base/current ESLint diagnostics for the changed production components match exactly; no config suppression or unrelated cleanup was applied.
- Student registration lifecycle contract: **7 passed**. Broader Phase 4 tests ran 8 successful cases before a pre-existing PHPUnit 12 docblock-data-provider incompatibility; Phase 2 deadline tests stop at the same class of fixture incompatibility. Whole-suite discovery also fails on an unrelated `AcademicCalendarPhase5OccurrenceResponseTest::result()` overriding a final PHPUnit method. These are not reported as passed suites.

## Remaining verification limits / boundaries

No real multi-connection MariaDB concurrency execution, live browser-to-Laravel end-to-end run, production data test, deployment or merge. SQLite/replayed requests do not prove MariaDB lock scheduling. Canonical services and normal-registration negative tests passed locally, but the unrelated suite/lint failures above remain visible for follow-up. Keep the corrective PR OPEN and unmerged.
