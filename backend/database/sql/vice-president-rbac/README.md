# Scientific and Administrative Vice President RBAC

Manual SQL runbook for Phase 3. These files define **RBAC building blocks
only**. They do not assign users, create accounts, or change access scopes.

Do **not** execute these files from application code, seeders, or migrations.
Cursor / agents must not execute SQL. Run them only in phpMyAdmin / the DBA
workflow after `00_preflight.sql` returns `OVERALL = READY`.

Database: `alrowad_uni_rust`

Every object is written as `` `alrowad_uni_rust`.`table` ``.
`information_schema` checks use `table_schema = 'alrowad_uni_rust'`.
Do not use `DATABASE()`.

## What this runbook does

Creates, if missing:

| Kind | Code | Arabic / meaning |
| --- | --- | --- |
| Role | `vice_president_scientific` | نائب رئيس الجامعة للشؤون العلمية |
| Role | `vice_president_administrative` | نائب رئيس الجامعة للشؤون الإدارية |
| Module | `vice_presidency` | container for dedicated VP permissions |
| Permission | `vice_presidency.scientific.access` | Scientific VP base identity |
| Permission | `vice_presidency.administrative.access` | Administrative VP base identity |

Maps each new role to **only** its own dedicated access permission.

## What this runbook does not do

- create or duplicate organizational units
- delete, rename, or repurpose generic `vice_president`
- create users
- assign `user_roles`
- insert or update `user_access_scopes`
- copy admin / president / dean / generic VP permissions
- implement teaching-assignment approval, exceptional opening, or closure

## Organizational units (reuse, do not invent)

Preflight rediscovers live units by **stable code or unique name**. Application
code must never hard-code numeric IDs.

Expected identities from Phase 0 / schema (live IDs may differ — report them):

| Intent | Stable discovery |
| --- | --- |
| University Presidency | `unit_code = 'PRES'` |
| Scientific VP | `unit_code = 'VP_SCI'` or unique name نائب رئيس الجامعة للشؤون العلمية |
| Administrative VP | `unit_code = '7'` (legacy alias `VP_ADMIN`) or unique name نائب رئيس الجامعة للشؤون الإدارية |

If either VP unit is missing or matches more than one active row, `OVERALL = BLOCKED`.

## Future user assignment (not in this phase)

A role definition is not a user assignment. When a real VP user is created later:

1. Assign the matching role (`vice_president_scientific` or `vice_president_administrative`).
2. Insert `user_access_scopes` with `scope_type = 'university'` and `scope_id` =
   the PRES organizational unit resolved by `unit_code = 'PRES'` (never a
   hard-coded ID). University scope is **where** the user may act, not **what**
   they may do.
3. Associate the employee with the matching VP organizational unit through the
   existing employee / `organizational_unit_id` architecture, again resolved by
   unit code.

Do **not** treat generic `vice_president` as either new identity.

Example assignment SQL is intentionally omitted from `01_apply.sql`. If a
temporary validation account is requested later, write a separate operator
script; do not fold it into this runbook.

## Files

1. `00_preflight.sql` — read-only. Continue only when `OVERALL = READY`.
2. `01_apply.sql` — idempotent inserts. Fail-closed when `@apply_ready = 0`.
3. `02_verify.sql` — read-only. Require `OVERALL = PASS`.
4. `03_rollback.sql` — removes only this phase's unused RBAC rows.
   Assigned roles are `BLOCKED / SKIP`, not deleted.

## Permissions reused

None. This phase does not attach `courses.view`, `students.view`,
`teaching_staff.view`, or other existing read permissions. Future VP dashboards
can add least-privilege reads in the phase that builds that UI.

## Code audit (generic `vice_president`)

Application authorization does not call `hasRole('vice_president')`. Hits are
organizational-unit SQL (`7`, `VP_SCI`) and the schema dump role row. Keep the
legacy role for historical users. It must not satisfy Scientific or
Administrative VP access.
