# Phase 4 — Teaching assignment dual VP approval workflow

Manual SQL only. Cursor must not execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

Ownership token for Phase 4 RBAC rows:

`[phase4-teaching-assignment-workflow]`

## Files

1. `00_preflight.sql` — READ ONLY. Continue only when `OVERALL = READY`.
2. `01_apply.sql` — idempotent create of missing compatible workflow tables and RBAC.
3. `02_verify.sql` — READ ONLY. Continue only when `OVERALL = PASS`.
4. `03_rollback.sql` — conservative. Drops a workflow table only when its
   `TABLE_COMMENT` contains `[phase4-teaching-assignment-workflow]` **and**
   no workflow business rows exist. Same-named empty tables without that
   marker are `SKIPPED_NOT_PROVABLY_PHASE_OWNED` and are never dropped.
   `BLOCKED_IN_USE` if any workflow business rows exist.

## What this phase creates

Tables (when ABSENT):

- `teaching_assignment_requests`
- `teaching_assignment_reviews`
- `teaching_assignment_events`

Permissions:

- `teaching_assignments.view`
- `teaching_assignments.manage`
- `teaching_assignments.review_scientific`
- `teaching_assignments.review_administrative`

Role mappings:

- `dean` → view + manage
- `vice_president_scientific` → view + review_scientific
- `vice_president_administrative` → view + review_administrative

Generic `vice_president` does not receive review permissions.

Verify (`02_verify.sql`) requires all eight foreign keys by exact
table / column / referenced table / referenced column, the six required
role-permission mappings, and the negative VP-review isolation checks.
`OVERALL = PASS` only when every named check passes.

## What this phase does not do

- No migrations or seeders
- No users, user_roles, or user_access_scopes
- No organizational-unit changes
- No modification of `course_offering_instructors` rows
- No fake workflow requests or VP approvals
- No backfill of legacy effective assignments
