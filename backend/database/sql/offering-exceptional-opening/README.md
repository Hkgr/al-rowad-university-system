# Phase 6 — Exceptional Course Offering opening workflow

Manual SQL only. Cursor must **not** execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

Ownership token for Phase 6 tables and RBAC rows:

`[phase6-offering-exceptional-opening]`

Permissions are attached to the existing `courses` system module
(`system_modules.module_code = 'courses'`). This phase does not create a
new module.

## Files

1. `00_preflight.sql` — READ ONLY. Continue only when `OVERALL = READY`.
   `OVERALL` is `BLOCKED` when any target object is `CONFLICT`, a
   prerequisite column is missing, or `rbac_matrix_conflict = 1`.
2. `01_apply.sql` — idempotent create of missing compatible workflow tables
   and RBAC. Independently recomputes the same guards as preflight.
   `apply_ready = 0` when `rbac_matrix_conflict = 1` (no RBAC INSERT).
   RBAC DML `COMMIT`s only after post-write verification succeeds;
   unexpected post-write failure `ROLLBACK`s this transaction's permission /
   role_permission inserts. DDL `CREATE TABLE` still auto-commits in MariaDB.
3. `02_verify.sql` — READ ONLY. Continue only when `OVERALL = PASS`.
4. `03_rollback.sql` — conservative. Drops a workflow table only when its
   `TABLE_COMMENT` contains `[phase6-offering-exceptional-opening]` **and**
   no workflow business rows exist. Same-named empty tables without that
   marker are `SKIPPED_NOT_PROVABLY_PHASE_OWNED` and are never dropped.
   `BLOCKED_IN_USE` if any workflow business rows exist.

## SQL safety guards

Preflight and apply use the **same** structural COMPATIBLE contract:

- expected column counts (20 / 10 / 7)
- InnoDB + primary key
- `types_ok` (signed ints, status length, review_authority enum/varchar)
- unique index exact column lists
- named foreign keys via `key_column_usage` (constraint + column + referenced table/column)
- queue indexes with exact `GROUP_CONCAT` column lists

Prerequisite-column lists are identical in preflight (including the
`B_missing_required_columns` report) and apply. The union includes
`course_offerings.department_id` and `user_access_scopes.user_id`.

### Pre-write forbidden-matrix audit

Before any RBAC INSERT, both files detect existing `role_permissions` that
violate the dual-VP isolation matrix:

- `review_scientific` granted to any role other than `vice_president_scientific`
- `review_administrative` granted to any role other than `vice_president_administrative`

That covers dean review mappings, cross-VP review mappings, generic
`vice_president` receiving any Phase 6 permission, `request` on a
non-dean role, `view` on an unrelated role, and explicit `super_admin`
Phase 6 mappings.

If any exist: `@rbac_matrix_conflict = 1`, preflight `OVERALL = BLOCKED`,
apply `apply_ready = 0`. phpMyAdmin result set `RBAC_MATRIX_CONFLICT`
lists offending `role_code` / `permission_code`. ABSENT Phase 6
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

- `course_offering_exception_requests`
- `course_offering_exception_reviews`
- `course_offering_exception_events`

Permissions:

- `course_offerings.exceptional_open.view`
- `course_offerings.exceptional_open.request`
- `course_offerings.exceptional_open.review_scientific`
- `course_offerings.exceptional_open.review_administrative`

Role mappings — exact allowed set only. `super_admin` is **not** granted
these rows (its runtime permission bypass is separate and must not
impersonate academic authorities):

- `dean` → view + request
- `vice_president_scientific` → view + review_scientific
- `vice_president_administrative` → view + review_administrative

Any other `role_permissions` row for these four codes is a matrix
conflict, including:

- generic `vice_president` → any of the four
- unrelated roles → any of the four
- dean → either review permission
- scientific VP → request or administrative review
- administrative VP → request or scientific review
- `super_admin` → any explicit Phase 6 mapping

Runtime mutating actions additionally require the dedicated actual role
plus the assigned permission (`effectivePermissions()`, not the Super
Admin virtual grant from `User::hasPermission()`).

## What this phase does not do

- No Laravel migrations or seeders
- No users, user_roles, or user_access_scopes
- No organizational-unit changes
- No modification of `course_offerings.status`
- No fake workflow requests or VP approvals
- No retroactive closure of existing OPEN offerings
- No weakening of Phase 5 normal-opening coverage

## Normal opening consumes pending exceptions

No schema change. Existing `status`, `current_slot`, `superseded_at`,
`superseded_reason`, and event `event_type` columns are sufficient.

When `CourseOfferingOpeningService` performs a true Phase 5
`CLOSED → OPEN` (after coverage validation, inside the same locked
transaction):

1. The Offering row is already locked.
2. If Phase 6 tables are present, the current unmaterialized exceptional
   request (`current_slot = 1`, `materialized_at IS NULL`) is locked.
3. It is superseded with:
   - `superseded_reason = offering_opened_normally`
   - event `superseded_offering_opened_normally`
   - `current_slot = NULL`
4. Opening and supersede commit together.

If Phase 6 tables are absent (`Schema::hasTable` false), normal Phase 5
opening continues unchanged. Arbitrary database errors are not swallowed
when the tables exist.

Idempotent updates of an already OPEN Offering do not touch exceptional
history.
