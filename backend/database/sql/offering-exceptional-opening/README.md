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
2. `01_apply.sql` — idempotent create of missing compatible workflow tables and RBAC.
3. `02_verify.sql` — READ ONLY. Continue only when `OVERALL = PASS`.
4. `03_rollback.sql` — conservative. Drops a workflow table only when its
   `TABLE_COMMENT` contains `[phase6-offering-exceptional-opening]` **and**
   no workflow business rows exist. Same-named empty tables without that
   marker are `SKIPPED_NOT_PROVABLY_PHASE_OWNED` and are never dropped.
   `BLOCKED_IN_USE` if any workflow business rows exist.

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

Role mappings:

- `dean` → view + request
- `vice_president_scientific` → view + review_scientific
- `vice_president_administrative` → view + review_administrative

Generic `vice_president` does not receive review permissions.
Dean does not receive either review permission.
Scientific VP does not receive administrative review.
Administrative VP does not receive scientific review.

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
