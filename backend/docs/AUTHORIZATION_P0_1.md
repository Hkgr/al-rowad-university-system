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

The development-only `AuthorizationP01Seeder` and production SQL use the same 58-record official chart contract. Both add missing required grants without deleting custom grants. The chart includes `PRES`, direct children `1`–`9`, and all image-derived descendants through `925`; only those official codes are corrected, so unrelated organizational rows remain untouched.

See [the complete official chart table](P0_1_OFFICIAL_ORGANIZATIONAL_CHART.md) for every exact code, Arabic name, schema-valid type, and parent.

The exact corrected records include `22 = الجودة والاعتماد الأكاديمي` (`unit`, parent `2`), `23 = مشاريع إنتاجية` (`unit`, parent `2`), and `736 = مكتب التوثيق والتصديق` (`office`, parent `73`). Username `exam.board` is distinct from role `exam_officer`; no examination grant is added to `board_member`. Deterministic registrar/exam employees are linked to `732`/`735` and receive `PRES` university scopes. Unique user identity indexes prevent future duplicate student/employee links.

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

Academic-year, semester, and academic-level reference reads are global and accept `academic_structure.view`, `registration.view`, or `grades.view`; organizational scope never empties them. Their mutations require `academic_structure.manage` and do not inherit read access. Identity linking and security administration remain on the centralized `super_admin` path.

Laravel policies are authoritative. React's central `can`, `canAny`, `canAll`, and `canAccess` helpers only mirror the returned identity contract to avoid dead links and unintended 403 responses.

## Migration and backfill

The migration adds indexed nullable identity lookups and one normalized `user_access_scopes` table with a unique `(user, type, id)` grant. `scope_id` is a signed `INT`, matching the signed identifiers in the checked-in domain schema. Unsupported resources and invalid scope references fail closed. Both student and employee identity may coexist, but every operation must complete one coherent authorization path (student ownership for reads, instructor assignment, or employee identity plus scope). Historical data is not modified by the migration. Because the repository's legacy domain schema exists only in the SQL snapshot rather than Laravel migrations, `migrate:fresh` remains a P0-2 prerequisite and must not be represented as a P0-1 success.
