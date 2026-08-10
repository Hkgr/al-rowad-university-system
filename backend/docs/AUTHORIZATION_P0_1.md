# P0-1 authorization and identity contract

Authorization is always the intersection of an effective permission and data scope. `super_admin` is the only centralized scope bypass. A student's own `student_id` and an instructor's active offering assignment are ownership rules, not browser-supplied scope.

## Stable identity response

`POST /api/login` (`data.user`) and `GET /api/user` (`data`) expose the same fields: `user_id`, `username`, `email`, `student_id`, `employee_id`, `board_member_id`, `roles`, `permissions`, `organizational_unit`, and `access_scopes`. Scope entries have `{type, id}`, where type is `university`, `college`, `department`, `program`, or `section` (a course offering). The client never submits these values as authorization evidence.

Only valid scope references are effective. The schema has no universities table or `university` unit type, so a university grant references the existing institutional organizational root whose stable code is `PRES`. College, department, program, and section grants reference their corresponding live records. Orphan or unsupported grants confer no access.

Run `php artisan identity:reconcile` for a dry-run identity report. It labels already linked, unlinked, deterministic, and ambiguous accounts. `--apply` links only a single exact, case-sensitive database email match; it never guesses by name. Review ambiguous and unlinked rows manually.

## Access matrix

| Actor | Required permissions | Effective data boundary | Explicit exclusions |
|---|---|---|---|
| `exam_officer` | student, structure, course, registration, exam, grade and settings views; exam/grade manage | granted scopes | registration manage and permission administration |
| `registration_officer` | student/admission/registration manage and required views | granted scopes | exam/grade approval and permission administration |
| `doctor_instructor` | course/student/attendance/grade operations | active assigned offerings only | other instructors' offerings |
| `student` | student/registration/grade/attendance views | linked student identity only | registration manage and other students |
| `super_admin` | centralized permission and scope bypass | university-wide | no scattered controller bypasses |

The development-only `AuthorizationP01Seeder` synchronizes the four system roles to its allow-list. Production uses the SQL-first runbook instead: it adds missing required grants but deliberately preserves existing/custom grants, including grants attached to the four operational roles. Verification checks the required P0-1 subset and does not misreport an additional custom grant as a deployment failure. The hierarchy operation still upserts the official `7 → 73 → 731–736` units by stable unit code.

The production SQL establishes `PRES → 7 → 71, 72, 73 → 731–736`. Code `7` is نائب رئيس الجامعة للشؤون الإدارية, codes `71`–`73` are directorates, `731`–`734` and `736` are offices, and `735` is an administration. `VP_ADMIN`, `REG_OFFICE`, and `EXAM_OFFICE` are reconciled to `7`, `732`, and `735`; legacy rows are disabled only after known references are moved. The rollback cannot reconstruct merged references without the mandatory backup.

## React page/API parity inventory

| React area | Principal APIs | Client/server requirement |
|---|---|---|
| Student affairs dashboard/list/profile | `/v1/student-affairs/dashboard-stats`, `/v1/students*` | `students.view`; mutations `students.manage`; student policy scope |
| Registration | `/v1/registrations*`, student registration summary, course/structure lookups | registration/student/course/structure/settings views; mutations `registration.manage`; student scope |
| Exam board offerings/grades/approvals | `/v1/course-offerings*`, `/v1/registrations/*/grades`, grade approvals | course/exam/grade views; operation-specific manage permission; offering scope/policy |
| Professor dashboard | offering roster, attendance and grades APIs | view/manage permission plus active instructor assignment |
| Student dashboard | linked student's profile, registrations, transcript, GPA and attendance | view permissions plus `student_id` ownership; no self-registration buttons |
| Academic structure | college/department/program/course APIs | `academic_structure.view` and `courses.view`; manage for mutations; granted scope |
| HR | employee/faculty/position APIs | `hr.view`; `hr.manage` for mutations |

Academic-year, semester, and academic-level reference reads accept the view permissions used by academic structure, registration, and grade workflows. Their mutations use the centralized `super_admin` path instead of inheriting a workflow's read access. User/role/permission administration, identity linking, system modules, and security logs use that same centralized administrator path; operational permissions are not interchangeable with it.

Laravel policies are authoritative. React's central `can`, `canAny`, `canAll`, and `canAccess` helpers only mirror the returned identity contract to avoid dead links and unintended 403 responses.

## Migration and backfill

The migration adds indexed nullable identity lookups and one normalized `user_access_scopes` table with a unique `(user, type, id)` grant. `scope_id` is a signed `INT`, matching the signed identifiers in the checked-in domain schema. Unsupported resources and invalid scope references fail closed. Both student and employee identity may coexist, but every operation must complete one coherent authorization path (student ownership for reads, instructor assignment, or employee identity plus scope). Historical data is not modified by the migration. Because the repository's legacy domain schema exists only in the SQL snapshot rather than Laravel migrations, `migrate:fresh` remains a P0-2 prerequisite and must not be represented as a P0-1 success.
