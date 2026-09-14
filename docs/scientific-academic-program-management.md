# Scientific academic programs and versioned plans

## Scope and operator workflow

Base: `7751fcf540990314f48fb832f9238acb2a8eb3b1`, the fetched `origin/develop` containing PR132 and PR133. This change neither deploys nor runs a production SQL file. `alrowad_uni_rust14-9-2.sql` was used only as a schema/reference-data reference.

The Scientific VP portal adds `/vp/scientific/programs` and `/vp/scientific/programs/:programId`. Program identity/activity/archive state is separate from plan state. Program code, degree and duration are explicit, not inferred from the name. Used academic identity is locked with a reason next to the controls; name/description corrections remain available.

Existing program setup is explicit:

1. Start initialization: `legacy → preparing`; new admissions/students stop, existing academic operations continue.
2. Preview the existing curriculum, group IDs, budgets, students and operation references with the database revision.
3. Fix the transitional reference and all student assignments atomically. Existing membership/group IDs and calculation semantics remain intact. This is **not historical approval**, not an approved plan, and not a default.
4. “إنشاء نسخة للتعديل”: copy into a separate draft. Six categories always appear. Blank means unspecified; zero is explicit. GET creates nothing.
5. Save budgets and course membership independently; review canonical capacity/count information. Approval requires the official requirement validation, six unique categories and compatible totals.
6. “اعتماد الخطة” fixes a draft but does not resume admissions. “تعيين للطلاب الجدد” explicitly selects an approved default after every existing student has an assignment.

A newly created program starts preparing with an empty draft. It does not fabricate a transitional history. Course creation remains independent: saving the catalog origin does not link it or change graduation budgets. Linking requires a second explicit action against the selected draft. Configuring a missing group retains the course draft and returns to the same plan after a fresh read.

Archiving records `archived_at`, not `is_active=false`: admissions/new students are blocked, while existing students retain curriculum, offering, supplementary, progress and graduation context. Deletion is allowed only after a current scoped impact preview; an otherwise unused empty setup draft may be removed with its program. Fixed plans, events, assignments or other academic references prevent deletion.

## History, calculations and concurrent writers

`AcademicPlanContext` resolves an existing student's actual current assignment, never a program default. A program-level catalog projection may use the explicit default/transitional context; a draft editor names its version. Multiple versions can contain the same course without any `first()` selection across versions.

`student_course_registrations` pins both `academic_plan_version_id` and `plan_program_course_id`. Initial/modification/replacement requests and progression/graduation decisions pin their plan. Transitional fixation attaches these references without changing old statuses, approval decisions, marks or timestamps. Supplementary/appeal history remains attached through its original official registration. Used/fixed course hours and prerequisite edges cannot be changed through old catalog CRUD or raw SQL; a different academic course requires a new code and no automatic equivalence.

Transfers are a separate, permissioned, same-program operation over an explicit list (maximum 50 students), with a reason and a fresh preview. The preview uses `AcademicRequirementService`, `GradeService`'s existing official-result/repeated-attempt semantics, and `GraduationEligibilityService`. It shows counted/outside courses and counted/remaining hours under both classifications. There is no new GPA or equivalence formula. Current registrations, unresolved initial/modification/replacement requests, withdrawal requests, progression/graduation work, unmaterialized supplementary work and unclosed grade appeals block transfer; a final graduation decision blocks it too. Unknown appeal status is not treated as closed. Old official records and previous assignments are retained after transfer.

Concurrency uses the existing **monotonic database catalog revision**, not a value fingerprint or timestamp. A change followed by restoration still invalidates an old preview. Triggers serialize and advance the epoch for catalog, first-use, student, registration, results/approvals, request/item, decision, supplementary and appeal writers. New workflow lock order is catalog control → program/version → student/assignment. Existing student-locking registration, withdrawal, progression/graduation, manual-grade preparation and Ministry enrollment transaction entries use `AcademicPlanContext::transaction()` to acquire the common control before their existing internal locks. Without this package they retain `DB::transaction()` behavior. Deadlocks/timeouts through the catalog boundary are controlled conflicts, not automatic mutation retries.

The database also protects raw student creation, default/assignment coherence, fixed plans, cross-version mappings and append-only events. Request materialization rechecks the actual student's assignment. An operation that loses a lock race must reload/review; no last-write-wins repair occurs.

### Caller audit

| Caller | Integration / retained boundary |
| --- | --- |
| Generic Course/ProgramCourse/Program/Group CRUD | Existing `CatalogCrudController` serialization plus strengthened database triggers; fixed academic edits denied, allowed text edits retained |
| Generic Student/AdmissionApplication creation | Transactional admission guard; raw SQL insert also guards preparing/archived programs and atomically assigns an explicit approved default |
| Ministry student enrollment | Existing semantics retained, outer plan-aware transaction and Student guard apply |
| Dean preparation / normal opening | Explicit fixed-plan source, unchanged effective instructor coverage and governance proof; no academic identity weakening |
| Initial / modification / replacement registration | Actual assignment for curriculum/requirements, pinned request and membership on official materialization, existing academic/time/schedule limits unchanged |
| Manual-grade exceptional preparation | Existing exception boundary retained; actual student's plan and canonical registration pinning apply |
| Academic progression / graduation / transcript | Official calculations retained; requirements resolve the student's plan; decisions retain original plan context |
| Supplementary / grade appeal | No workflow redesign; original registration provenance retained; ongoing work blocks plan transfer and its changes invalidate previews |

## Authorization and API

Every program-management endpoint requires active account, actual active Scientific VP role, assigned effective `vice_presidency.scientific.access`, assigned `academic_structure.view`, and actual university/college/department/program scope. Virtual super-admin permissions and Administrative VP alone do not pass. Entity queries use the existing actual mutation DataScope implementation. UI route, navigation and home card use the same base predicate; mutation capabilities come from the server.

Existing `academic_structure.manage` handles program identity. Five missing permissions are defined, **without role/user grants**:

- `vice_presidency.scientific.programs.plans.manage`
- `vice_presidency.scientific.programs.plans.approve`
- `vice_presidency.scientific.programs.plans.assign`
- `vice_presidency.scientific.programs.archive`
- `vice_presidency.scientific.programs.delete`

Prefix: `/api/v1/vice-presidency/scientific/program-management`. Routes cover paginated list/options/detail, create/update, deletion preview/delete, archive/restore, initialization/transition preview/fix, version read/copy, requirements and membership edits, approval/default, and transfer preview/confirm. Unknown fields are rejected. Searches/sorts are allowlisted. Revision is an opaque decimal string. No write takes place in GET.

Whole-scope Course Management distribution still covers **all** programs in the selected university/college/department. Once versioning is active it requires one explicit editable draft per versioned target, with the plan-management permission. Missing/locked/unconfigured targets block the entire operation; no silent subset or fixed-plan mutation occurs.

## Manual deployment — maintenance is mandatory

Do not execute these instructions against production without the operator's normal approval/change process.

1. Back up and enter maintenance. Stop affected HTTP writes, imports, queue workers, scheduled academic jobs and any external SQL writers. Retain a maintenance response while schema and code are mixed.
2. Check the already-installed Scientific Course Management guard. Its three verifier files now correctly expect `user_activity_logs.activity_log_id` as signed `BIGINT`; do **not** change production to INT. If that prerequisite was never installed, install/verify it first.
3. Review `academic-program-management/00_preflight.sql` against `alrowad_uni_rust`, including partial-object compatibility and reference metadata. Resolve BLOCKED results; never force execution past errors.
4. Apply `01_apply.sql`. It creates four tables only (`academic_plan_control`, `academic_plan_versions`, `student_academic_plan_assignments`, `academic_plan_events`), adds provenance/version/archive columns, restrictive FKs, version-aware unique keys/generated scope keys, CHECKs, triggers and narrow permission definitions. No plan fixation or student backfill runs in SQL. Incompatible existing objects are rejected; compatible interruptions are resumable. Readiness stays false until verification succeeds.
5. Run `02_verify.sql`; require `OVERALL | PASS`. It checks structures, exact trigger bodies, CHECKs, keys, defaults, permission definitions and readiness. Extra compatible columns are allowed. Corrective DDL must be reviewed, not guessed or applied through application GETs.
6. Run the compatible application code, clear/rebuild caches as required by the existing release process, and perform isolated smoke checks while writes/workers remain stopped. **Do not run the old application against version-aware membership uniqueness**; compatibility has not been claimed.
7. Explicitly provision only the needed new permissions through the existing RBAC process. The SQL does not grant them. Verify actual scope and a non-authorized account.
8. Resume traffic/workers only after schema/code checks pass. Each program's admissions remain independently stopped during initialization until its approved default is explicitly selected.

Do not rerun the old Course Management **apply** after this package: its legacy trigger definitions would replace version-aware triggers. There is no destructive automatic rollback; do not drop used versions/assignments or restore an old binary against these constraints. Recover under maintenance with a reviewed compatible package or the full coordinated backup.

## Visual implementation

The user approved direct implementation using the current design. References inspected and rendered: `ScientificCoursesPage.jsx`, `CourseEditor.jsx`, `CatalogControls.jsx`, `ProgramEditors.jsx`, `DataTable.jsx`, `FilterBar.jsx`, existing Scientific VP shell/navigation. The feature reuses their Cairo/RTL, green theme, headers, fields, buttons, native dialogs, notices and paginated table. No global style redesign.

Draft baseline, refreshed server state and proposed values stay distinct. Sidebar/history navigation is blocked while dirty or writing; cancellation preserves the draft. Conflicts/lost responses require explicit inspection/discard, never automatic retry or rebasing. Identity/authorization loss immediately unmounts sensitive state. Basic form-budget arithmetic is labeled as a draft preview, not an academic approval decision.

## Verification and known limits

See [verification record](scientific-academic-program-verification.md). SQLite tests are not lock evidence. MariaDB tests use independent real connections and a guarded disposable 10.11.18 instance, not a production clone. Browser tests use a production React build, a loopback Laravel bridge with a synthetic test identity, and real disposable MariaDB reads/writes. They are not deployed-production acceptance or a full authentication/worker/load test.
