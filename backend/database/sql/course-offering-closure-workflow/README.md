# Phase 7 — Formal Course Offering closure workflow

Manual SQL only. Cursor must **not** execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

Ownership token for Phase 7 tables and RBAC rows:

`[phase7-course-offering-closure]`

Permissions are attached to the existing `courses` system module
(`system_modules.module_code = 'courses'`). This phase does not create a
new module.

## Files

1. `00_preflight.sql` — READ ONLY. Continue only when `OVERALL = READY`.
   `OVERALL` is `BLOCKED` when any target object is `CONFLICT`, a
   prerequisite column is missing, `offering_status_ok = 0`, or
   `rbac_matrix_conflict = 1`.
2. `01_apply.sql` — idempotent create of missing compatible workflow tables
   and RBAC. Independently recomputes the same guards as preflight.
   `apply_ready = 0` when `rbac_matrix_conflict = 1` (no RBAC INSERT).
   RBAC DML `COMMIT`s only after post-write verification succeeds;
   unexpected post-write failure `ROLLBACK`s this transaction's permission /
   role_permission inserts. DDL `CREATE TABLE` still auto-commits in MariaDB.
3. `02_verify.sql` — READ ONLY. Continue only when `OVERALL = PASS`.
4. `03_rollback.sql` — conservative. Drops a workflow table only when its
   `TABLE_COMMENT` contains `[phase7-course-offering-closure]` **and**
   no workflow business rows exist. Same-named empty tables without that
   marker are `SKIPPED_NOT_PROVABLY_PHASE_OWNED` and are never dropped.
   `BLOCKED_IN_USE` if any workflow business rows exist.
   Backup the three workflow tables before attempting rollback.

## SQL safety guards

Preflight and apply use the **same** structural COMPATIBLE contract:

- expected column counts (20 / 10 / 7)
- InnoDB + primary key
- `types_ok` (signed ints, status length, review_authority enum/varchar)
- unique index exact column lists
- named foreign keys via `key_column_usage` (constraint + column + referenced table/column)
- queue indexes with exact `GROUP_CONCAT` column lists
- `course_offerings.status` is a string type that can store `open` / `closed`

Prerequisite-column lists are identical in preflight (including the
`B_missing_required_columns` report) and apply. The union includes
`course_offerings.department_id`, `course_offerings.status`, and
`user_access_scopes.user_id`.

### Pre-write forbidden-matrix audit

Before any RBAC INSERT, both files detect existing `role_permissions` that
violate the dual-VP isolation matrix:

Exact allowed mappings:

- `course_offerings.closure.view` → `dean`, `vice_president_scientific`, `vice_president_administrative`
- `course_offerings.closure.request` → `dean` only
- `course_offerings.closure.review_scientific` → `vice_president_scientific` only
- `course_offerings.closure.review_administrative` → `vice_president_administrative` only

Forbidden (any of these sets `@rbac_matrix_conflict = 1`):

- generic `vice_president` receiving any of the four
- `super_admin` explicit Phase 7 mappings
- dean review permissions
- VP request permission
- cross-VP review mappings
- unrelated roles

If any exist: `@rbac_matrix_conflict = 1`, preflight `OVERALL = BLOCKED`,
apply `apply_ready = 0`. phpMyAdmin result set `RBAC_MATRIX_CONFLICT`
lists offending `role_code` / `permission_code`. ABSENT Phase 7
permissions cannot have mappings, so conflict is 0 and apply may proceed.

### Apply transaction outcome

| `apply_status` | Meaning |
|---|---|
| `BLOCKED` | `apply_ready = 0` (including forbidden-matrix conflict). No RBAC DML. |
| `APPLIED` | Post-write matrix PASS. RBAC transaction `COMMIT`ted. |
| `ROLLED_BACK` | `apply_ready = 1` but post-write verification failed. RBAC INSERT from this transaction did not persist. |

Compatible existing tables/permissions are not modified. Non-owned RBAC
is never deleted. This phase does not create users, `user_roles`, or
`user_access_scopes`.

## What this phase creates

Tables (when ABSENT):

- `course_offering_closure_requests`
- `course_offering_closure_reviews`
- `course_offering_closure_events`

Permissions:

- `course_offerings.closure.view`
- `course_offerings.closure.request`
- `course_offerings.closure.review_scientific`
- `course_offerings.closure.review_administrative`

Role mappings — exact allowed set only. `super_admin` is **not** granted
these rows (its runtime permission bypass is separate and must not
impersonate academic authorities):

- `dean` → view + request
- `vice_president_scientific` → view + review_scientific
- `vice_president_administrative` → view + review_administrative

Runtime mutating actions additionally require the dedicated actual role
plus the assigned permission (`effectivePermissions()`, not the Super
Admin virtual grant from `User::hasPermission()`).

## What this phase does not do

- No Laravel migrations or seeders
- No users, user_roles, or user_access_scopes
- No organizational-unit changes
- No fake workflow requests or VP approvals
- No deletion of registrations, attendance, grades, or instructors
- No cancellation of Teaching Assignment or Phase 6 exceptional-opening history
- No force-close / admin-close / super-admin-close endpoint

## OPEN → CLOSED contract

CREATE of a new Course Offering remains CLOSED without this workflow.

Semantic CLOSED → OPEN remains Phase 5 (normal coverage) or Phase 6
(current dual-VP exceptional proof). Historical Phase 6 proof is
one-time and cannot reopen a later-closed Offering.

Semantic OPEN → CLOSED exists only through this Phase 7 workflow:

1. current closure request (`current_slot = 1`)
2. current-version scientific approval
3. current-version administrative approval
4. two distinct `reviewed_by_user_id` values
5. same Offering identity as the request snapshot

Generic `CourseOfferingController::update()` and Dean registration
`close` must not write OPEN → CLOSED.

## SQL acceptance scenarios

- SQL-CLOSE-01 — wrong role already has scientific review permission → preflight BLOCKED
- SQL-CLOSE-02 — generic VP has any closure review permission → BLOCKED
- SQL-CLOSE-03 — Super Admin has explicit closure request/review permission → BLOCKED
- SQL-CLOSE-04 — clean DB with target tables absent → READY
- SQL-CLOSE-05 — apply clean DB → tables + permissions + mappings → APPLIED
- SQL-CLOSE-06 — unexpected RBAC post-write failure → ROLLBACK, no partial RBAC inserts
- SQL-CLOSE-07 — rerun apply → idempotent
- SQL-CLOSE-08 — verify clean result → OVERALL PASS
