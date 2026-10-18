# Super administrator access and university email creation

This change makes an active actual `super_admin` the administrative authority across the existing portals and reduces individual mailbox creation to one dialog. It does not assign additional roles, manufacture scopes or personal identities, change academic workflow rules, or provision anything automatically. Ordinary operators still need their existing roles, assigned permissions and actual scopes.

## Base and cause

The branch starts at `origin/develop` `f590501255a9de095e37addee29dd32ed6dbe1ec`, after merged PRs 144, 146, 147 and 148. The worktree was clean before implementation.

The email guard required an actual `technical_team` role and assigned permissions. Student search reused `scopeManualGradeStudents()`, which deliberately excluded the administrative scope bypass. React independently required the technical role and actual assigned permissions. Consequently a real administrator without extra role mappings or a fabricated university scope could not use the feature.

## Administrative authority

`User::isSuperAdmin()` requires an active account and an active actual super administrator role mapping. `hasPermission()` delegates to this authority before reading effective assigned permissions. Administrative role predicates delegate to `canActAs()`; factual `hasRoleCode()`, `effectiveRoles()`, `effectivePermissions()` and actual scopes remain unchanged. `UserIdentityService` exports an `is_super_admin` capability without adding roles or permissions to those factual arrays.

`DataScopeService` uses the same authority for administrative reads and mutations. `hasActualUniversityScope()` remains factual; `canAdministerUniversity()` is the administrative capability. The new `scopeUniversityEmailStudents()` selects all actual students for an administrator, explicit academic scopes for an ordinary technical operator, and no students for another identity. It does not inherit student-self or instructor membership access.

React `canAccess()` recognizes the same administrative authority before administrative role/assigned-permission/scope checks. Explicit invalid-context denials and personal identity checks precede that bypass. Administrators land on `/technical` and receive a shared section of links to existing administrative portals, including professor reports. Ordinary landing precedence remains unchanged. Server authorization is authoritative even if a cached browser identity is stale.

## Authorization audit

Search covered `super_admin`, `effectiveRoles`, `hasRoleCode`, `effectivePermissions`, `assignedPermissions`, `allRoles`, actual university/academic scopes, mutation scopes, `abort_unless` and 403 checks in backend and frontend application sources. Each remaining explicit denial was classified by purpose, not replaced blindly.

| Class | Location | Treatment |
| --- | --- | --- |
| A administrative access | `User`, permission middleware and existing policies | Central active administrator authority; factual roles unchanged |
| A resource scope | `DataScopeService` administrative students, programs, colleges, faculty, staff, courses, offerings and mutation scopes | Administrator accesses actual targets; ordinary actual scope rules retained |
| A email | `UniversityEmailAccess`, `UniversityEmailService`, `UniversityEmailProvisioningService` | Bypass administrative role/permission/scope, not durable operation safety |
| A portals | `PresidentPortal`, `MinistryPortal`, `ExecutiveReportAccess`, `AdministrativeGovernance`, `ScientificCourseAccess`, `ScientificProgramAccess`, `ExamManualGradeEntryAccess`, `MinistryPlacementAccess` | Administrator allowed without office roles or actual university scope |
| A ministry confinement | `ConfineMinistryAccounts` | Ordinary observer remains read-only/confined; administrator is not confined merely because it also holds an observer role |
| A reports | `PortalReportRegistry`, `PortalReportService` | Administrative reports have university access; professor administrative reports need no fictitious teaching assignment; student reports remain self-only |
| A academic administration | `AcademicCalendarService`, `AcademicProgressionService`, `AcademicTermSnapshotService`, `GraduationDecisionService` | Existing administrative checks use central permissions and role capabilities |
| A dean preparation and review | `DeanRegistrationOfferingService`, `SemesterOfferingGovernanceService`, `CourseOfferingScheduleService`, `MinimumEnrollmentReviewService` | Administrative authority allowed; identity, locks, coverage, approval consumption, timetable and state rules unchanged |
| A dual-office workflows | `TeachingAssignmentWorkflowService`, `CourseOfferingClosureWorkflowService`, `CourseOfferingExceptionWorkflowService`, `RegistrationWithdrawalService` | Authority allowed; distinct-reviewer, state, timing and transaction rules retained |
| A supplementary administration | `SupplementaryExamOfferingService`, `SupplementaryExamPeriodGovernanceService`, `SupplementaryExamRegistrationWindowService`, staff boundary in `SupplementaryExamRegistrationService`, `SupplementaryExamMaterializationService`, `SupplementaryExamReconciliationService`, `SupplementaryExamOverviewService`, `SupplementaryExamGradingService` | Administrative checks/capabilities updated; eligibility, marks and supplementary workflow semantics unchanged |
| A request and controller guards | Dean supplementary requests, VP announce request, supplementary overview and registration-office controllers | Same central administrative capabilities |
| A routes and presentation | `auth.js`, `DashboardLayout`, `adminPortalNav`, executive/report access adapters, dean/VP quick actions, semester review, Ministry placement actions and supplementary capability adapters | Shared authority, existing shell/design, no fake roles |
| B personal student identity | `User::isStudent()`, student-self controllers, `UniversityEmailProvisioningService::selfEmail()`, student supplementary eligibility/registration/deferral boundaries, student report registry | Actual linked student and existing self permission/ownership required; no targetless impersonation |
| B personal instructor identity | `User::isProfessor()`, `ProfessorGradeAssignmentService`, instructor boundaries in `AcademicAuthorizationService` | Actual employee and canonical assignment required; administrator uses targeted Exam Board entry or administrative/report APIs instead |
| C protected accounts | `AccountAdministrationService`, `RoleController`, `AccountAdministration` | Last active administrator, protected role, escalation and self-change restrictions retained |
| C narrow target safeguards | `AdministrativeDeanService::assertManageableAccount()`, governance account lookup, `FacultyRosterImportService` | Target account suitability/protected administrator checks are not actor-access guards; retained |
| C workflow integrity | Generic offering opening restrictions, immutable identity/plans, official grade locks, deadlines, distinct approvers and canonical transitions | Administrator authorization never substitutes for valid workflow proof |

Frontend guides retain factual ordinary access definitions and pass them through the shared capability interpreter. No new permissions, role mappings, scopes or schema objects are introduced. Existing mail permission-enablement commands remain relevant to ordinary technical operators, not a prerequisite for administrator access.

## Individual mailbox creation

`POST /api/v1/technical/university-email/students/{student}/create` accepts only `english_first_name` and accepted `confirmed`. It requires view, manage, provision and receipt capabilities, plus access to the actual target. The server builds the canonical address from the locked student number and configured domain; callers cannot provide an address, password, operation identity or scope bypass.

Preparation locks the student, rechecks authorization, locks the email row, saves the internal draft and prepares the existing durable operation/password within one local transaction. Any previous non-cancelled operation requires explicit review/cancellation rather than regeneration or implicit execution. Preparation commits before calling the existing single-write execution machine. No remote transport runs while local database locks are held.

The operation UUID, generation, write-start claim, ownership tags, preflight, verification, uncertainty, reconciliation and audit remain canonical. Passwords are not stored or logged. Credentials are returned only with confirmed creation and `no-store, private`. A lost response never triggers automatic POST retry or mailbox deletion compensation. Read-only reconciliation does not recover a lost password; a separately authorized reset is required.

MariaDB testing reproduced a deadlock when two different student locks reserved the same canonical address. The outer preparation transaction now maps its deadlock to `university_email_conflict`, after rollback and before remote execution, without retry. Unique address conflicts remain controlled by the existing draft service.

## Existing design and dialog

References are the existing `UniversityEmailPage.jsx`, `UniversityEmailProvisioning.jsx`, `ManualGradeDialog.jsx`, `MinistryUi.jsx`, shared `DataTable.jsx`, and the existing technical account panels. The implementation reuses their controls, notices, information grid and dialog. Global styling, Cairo, RTL and PDF generators are unchanged. Source-level consistency and build were checked; rendered desktop/mobile comparison remains unverified because the authorized browser could not start.

The page checks Mailcow connection/domain counts once per page context, not per student, with explicit refresh and unavailable state on failure. Search remains paginated. The table separates student number, college, program, address and local confirmation state. Unavailable schema is not described as a confirmed absence of a mailbox.

The dialog shows the actual student, canonical address preview and one create action. Confirmed success exposes copy email, copy password, PDF and finish. Password/receipt state exists only in the mounted student's dialog. Closing, changing identity or authorization cleanup removes it; refreshing cannot recover it. In-flight writes and sensitive/dirty navigation remain guarded. A failure requires official-state review, not another automatic creation attempt.

Existing mailbox management, independent reset, suspension/activation and linking remain explicit advanced operations. Technical operation detail is behind the advanced view. A prior prepared/failed operation can be explicitly cancelled through the existing pre-write-only cancellation boundary, after which the operator may correct the name and create a new operation. Downloading PDF does not acknowledge delivery or change `handover_status`.

## Initial implementation verification

All test identities and data were synthetic. No production database or live Mailcow was used.

| Check | Actual result |
| --- | --- |
| Focused Laravel HTTP/middleware and behavior tests | 161 passed: new administrator/one-step regressions, existing email phases, technical account administration, administrative governance, Ministry, President and executive reporting |
| Additional Laravel suites | 127 passed: manual entry/grid/preparation, semester offering governance, portal reports and teaching-assignment reviews |
| Supplementary Laravel suites | 145 passed: Phase 7 authorization matrix, canonical materialization behavior and overview; includes source-contract cases, mocked service cases and actual SQLite persistence, not 145 browser scenarios |
| Focused bulk preparation authorization contracts | 7 passed after alignment with central authority and the already-current semester governance permission |
| Updated faculty mutation assertion | Executed through the actual administrator portal regression; passed without an assigned scope |
| Node tests | 271 passed; static and pure-logic checks, not rendered React acceptance |
| MariaDB concurrency | 12 scenarios passed on isolated MariaDB 11.4.9 using independent PHP workers and connections; fake upstream recorded 8 writes, expected for those scenarios |
| PHP syntax | All 79 changed/new PHP files passed |
| Dependency-free PHP contracts | 35 passed; one pre-existing obsolete calendar compatibility contract failed |
| Composer validation and locked platform requirements | Passed with existing dependencies |
| Frontend production build | Passed; existing large-chunk warning remains |
| Modified-file ESLint | New auth/email files and other modified files passed except five pre-existing errors and one warning in three unrelated page sections, listed below |
| Diff whitespace check | Passed after removing an extra EOF blank line |

MariaDB scenarios include existing execute/cancel/receipt races, remote-success/local-confirmation failure, concurrent one-step creates for one student, cancellation against an old worker, and concurrent reservation of one address across two students. Only one competing creation can reserve/execute that address. This is evidence for these isolated scenarios, not proof for every production race or Mailcow deployment configuration.

### Existing failures and unavailable checks

The general PHPUnit discovery path is blocked by the existing `AcademicCalendarPhase5OccurrenceResponseTest::result()` override of PHPUnit's final `result()` method. Explicit focused suite paths were executed instead.

`ExamBoardCourseCatalogDataScopeTest` still has an existing ordinary department-scope SQL failure: ambiguous `department_id` through a joined relation in the unchanged `scopeCourses()` query. Its administrator fixture was updated to supply a real active account status, and its ordinary denial assertions remain. That broader class is not reported wholly passing.

The full historical `BulkDeanOfferingPrepareContractTest` ran 24 cases: 20 passed and four obsolete non-authorization source expectations failed (`test_bulk_06`, `test_bulk_08`, `test_bulk_13`, `test_ui_bulk_checkboxes`). The seven updated authorization cases also passed independently. No unrelated production workflow was changed to satisfy those historical strings.

`SemesterRegistrationEligibilityPhase3GradeBoundaryTest` ran three cases: two passed and its pre-existing repeated-course CGPA expectation failed (expected 4.0, actual 3.5). `GradeService` and that historical test were left unchanged; this is not reported as a passing GPA parity check.

`academic_calendar_schema_compatibility_repair_contract.php` requires a Phase 3 policy file to remain absent, although it already exists at the base. It remains unchanged and failed. Other 35 standalone contracts passed.

The same ESLint failures were reproduced from base source: two `set-state-in-effect` errors in `DeanRegistrationOfferings.jsx`; a refs error, `set-state-in-effect` error and missing-dependency warning in `MinistryPlacementsPage.jsx`; and one `set-state-in-effect` error in `SemesterOfferingDetail.jsx`. No unrelated sections were edited to hide them.

A full frontend ESLint scan was also executed and reported 90 errors and 16 warnings across the repository. It did not pass. The changed-file scan isolates the five errors and one warning above; no unrelated lint cleanup was attempted.

The authorized in-app browser failed before connecting with `codex/sandbox-state-meta: missing field sandboxPolicy`. A separate isolated HTTP server launch was rejected by environment policy. Neither was bypassed. The opt-in `frontend/tests/browser/super-admin-email-create-http.mjs` was added but remains unexecuted here. React connected to a running Laravel server, desktop/mobile screenshots, clipboard behavior, actual downloaded PDF and visual acceptance remain unexecuted. PHPUnit HTTP middleware tests are real Laravel execution but are not a browser-to-server end-to-end substitute. No CI success is assumed.

## Final acceptance review

The 2026-10-01 follow-up starts from reviewed commit `ff87c62b9f397f6399f64117d65b7365e8b247e4`. Fetch confirmed that the same branch had no subsequent commits and PR 149 remained open. This round changes three frontend files, the existing administrator regression, its Node contract, and this document only. Backend application code, authorization policy, schema, dependencies, academic workflows and provisioning gates are unchanged.

The basic creation path no longer calls the entered name a draft. Navigation uses «بيانات إنشاء غير مكتملة» and explains that leaving discards the entered name. After confirmed creation, the separate credentials warning explains loss of the temporary password rather than claiming an unexecuted creation. Schema-unavailable text does not imply that students have no mailbox. Previous attempts are described as attempts needing review; technical diagnostics remain in the advanced panel. The disabled-state notice explicitly says «إنشاء البريد غير مفعّل على الخادم بعد» and does not blame user permissions.

The Node source regression failed before the text correction and passed afterward. It verifies the absence of the Arabic draft label in the two basic UI sources and checks the exact disabled-provisioning error presentation. These are static/pure-logic tests, not rendered browser acceptance.

The strengthened real Laravel HTTP regression proves an active account with only `super_admin`, no effective assigned permissions and zero access-scope rows returns the actual synthetic student total. It checks omitted/empty/whitespace search, Arabic name, student number, distinct paginated rows, exact student detail, one-step fake-Mailcow creation, a single external write and nonpersisted credentials. No live Mailcow traffic is permitted by the fixture. Another HTTP regression independently disables each of provisioning-enabled, contract-verified and write-key configuration: each returns `enabled=false` and controlled 503 with zero email, operation or audit writes and no outgoing request.

The identity audit reviewed all uses of `isAcademicAdvisor`, `isDean`, `isRegistrationOfficer`, `isExamOfficer`, `isScientificVicePresident` and `isAdministrativeVicePresident`. Their administrative capability does not alter factual `isStudent`/`isProfessor`, personal controllers or `ProfessorGradeAssignmentService`. The expanded HTTP regression rejects student email, student reports, self registration, transcript and academic record for the administrator without a student identity. Professor offerings remain empty with no faculty identity, and the canonical assignment service returns no assigned grade parts. Existing reviewer separation, locked reviews, official grade locks, submission state/version, consumed opening approval and deadline checks remain in their domain services; they were not weakened to satisfy authorization tests.

| Follow-up check | Executed result |
| --- | --- |
| Targeted Laravel suites | 210 passed, 6757 assertions: `SuperAdminEmailCreateTest`, email Phases 1–3, technical account administration, President, Ministry, administrative governance, portal reports, teaching-assignment review, manual grade entry and semester offering governance |
| Additional deadline checks | Two no-argument behavior tests passed: advisor decision windows and trusted materialization deadline revalidation. A third data-provider case failed before its body ran because the installed test runner supplied no arguments; it is not counted as successful boundary verification |
| Frontend tests | 76 passed: administrator/email, technical portal, President/Ministry/report access and executive-report checks; static/pure logic only |
| Dependency-free contracts | Four passed: administrator one-step contract and university email Phases 1–3 |
| Build and changed-file lint | Production build passed with the existing large-chunk warning; ESLint passed for both modified JSX files, the message helper and the Node test |
| PHP and Composer | Changed PHP test syntax passed; Composer validation and locked platform requirements passed |
| Diff check | Passed |

The additional deadline command selected `test_deadline_phases_use_inclusive_student_and_advisor_boundaries`, `test_advisor_decision_accepts_on_time_submission_during_both_open_phases_and_rejects_late_or_closed`, and `test_advisor_materialization_boundary_rechecks_submission_and_advisor_deadlines`. The first uses a historical docblock data provider and failed with “Too few arguments”; its test and production policy were left unchanged. Full repository lint and general PHPUnit discovery were not rerun as passing checks; the initial-round failures above remain disclosed.

The authorized browser was retried and again failed before opening a tab with `codex/sandbox-state-meta: missing field sandboxPolicy`. No alternate browser-control mechanism or security workaround was used. Desktop 1440, mobile 390, rendered RTL/Cairo, overflow, clipboard, actual PDF download and visual verification therefore remain unexecuted. The real HTTP tests above use Laravel middleware and an isolated SQLite database, but do not establish React-to-Laravel end-to-end or visual acceptance. MariaDB concurrency evidence remains the initial round's 12 scenarios; it was not rerun because this round changes no backend production code.

NO NEW MIGRATION. NO NEW SQL. NO PRODUCTION CHANGES. NO LIVE MAILCOW WRITES. Provisioning still requires `MAILCOW_PROVISIONING_ENABLED=true`, `MAILCOW_CONTRACT_VERIFIED=true`, a configured `MAILCOW_WRITE_API_KEY` and the existing canonical Mailcow URL. No real environment configuration was edited.

## Schema and deployment

The four already-merged university-email migrations were inspected; their existing draft, operation, cancellation, management, credential-generation and slot fields suffice. The supplied `alrowad_uni_rust10-1.sql` was inspected only for relevant DDL as a reference. Its student/user key types and email structures do not require an additional migration or SQL package for this change. No dump data or SQL was executed.

After review and merge, deploy compatible backend code and run the normal frontend build so the revised application bundle is included. Apply only the already-existing email schema prerequisites if an environment has not installed the prior phases; this PR introduces none. Do not enable provisioning or change real Mailcow credentials as part of this code update. Existing write-enable/contract-verification configuration remains mandatory.

Before operational acceptance, use an isolated Laravel/database and fake Mailcow to check administrator-only access with no artificial scope, ordinary scoped technical access, Arabic/number/empty search, create/copy/PDF/finish, confirmed mailbox management, pending-write navigation, uncertain-state review and permission-loss cleanup. Compare the dialog/page on desktop and mobile with the named references. Verify no horizontal overflow, full Arabic/long password PDF rendering and no handover mutation. Those rendered/live integration checks remain required rather than assumed.
