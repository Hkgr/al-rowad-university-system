# Administrative Vice-Presidency governance (teachers + college deans) — manual SQL

Project policy: database changes are applied by **manual SQL only**; no Laravel migrations.
All scripts target `alrowad_uni_rust` with fully qualified names, resolve every id by code
(`module_code`, `role_code`, `permission_code`) and are safe to run in phpMyAdmin without a
selected database. Every script starts with `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`
so Arabic text and comparisons match the tables' collation.

**No schema change.** The feature reuses existing tables: `faculty_members`, `employees`,
`employee_unit_assignments` (college affiliation + history), `user_roles`,
`user_access_scopes` (dean college scope), `employee_positions` (DEAN position history),
`user_activity_logs` (audit). The scripts create **no accounts, no deans, no teachers and no scopes**;
those are created later through the portal by an authorized administrative VP.

## What is added

| Object | Code | Grants |
|---|---|---|
| Permission | `vice_presidency.administrative.faculty.view` | list/show teacher profiles and college affiliation |
| Permission | `vice_presidency.administrative.faculty.manage` | create/link teacher profiles, edit allowed fields, assign/transfer/end college affiliation |
| Permission | `vice_presidency.administrative.deans.view` | list colleges and their deans |
| Permission | `vice_presidency.administrative.deans.manage` | appoint/transfer/end college deans (dean role + one college scope only) |
| Mapping | `vice_president_administrative` → the four permissions | tagged `[vp-admin-governance]` |

The server additionally requires the account to be active, to hold the
`vice_president_administrative` role and an **active university scope**. `super_admin` is accepted
through the general bypass, still only with an active university scope.
No mapping to `super_admin` is added.

## Prerequisites

- Module `vice_presidency` (from `vice-president-rbac`) and role `vice_president_administrative`, both active.
- Role `dean` exists and carries no `users_permissions.*`, `user_accounts.*`, `vice_presidency.*`
  or `system_settings.manage` permission (the dean flow refuses such a role — fail closed).
- Position `DEAN` (used for dean position history), employee type `academic`, employee status `active`.
- Every college that should receive a dean or teachers has `colleges.organizational_unit_id`.
- The technical-team package is **not** required.

## Execution order (phpMyAdmin → SQL tab, one file at a time)

1. Take a database backup.
2. `00_preflight.sql` — read-only. Expect no `MISSING` rows, reference rows with `row_count = 1`,
   target permissions `ABSENT` (first run) or `COMPATIBLE` (re-run), no restricted permission on
   the dean role. Review the colleges without an organizational unit and the current deans list.
3. `01_apply.sql` — expect `apply_status = APPLIED`. `BLOCKED` means a safety condition failed and
   nothing was written; the output columns name the condition. Re-running is safe.
4. `02_verify.sql` — every row must be `PASS`. The last result lists current deans per college.
5. Users must sign out and back in (or reload) for the new permissions to appear in the portal.

### View-only administrative VP

To let a VP **view** but not change teachers/deans, remove only the manage mappings:

```sql
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE role_id = (SELECT role_id FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative')
  AND permission_id IN (SELECT permission_id FROM `alrowad_uni_rust`.`permissions`
                        WHERE permission_code IN ('vice_presidency.administrative.faculty.manage',
                                                  'vice_presidency.administrative.deans.manage'));
```

`02_verify.sql` will then report `FAIL` for "holds the four permissions" — expected in that setup.

## Rollback

`03_rollback.sql` deletes only the four permissions tagged `[vp-admin-governance]` and their mappings
to `vice_president_administrative` / `super_admin`. It reports `BLOCKED` and deletes nothing if any of
the permissions is untagged (created by someone else) or mapped to another role.

It **never** deletes accounts, employees, teacher profiles, affiliations, dean roles/scopes,
positions or audit rows: deans and teachers created through the portal are real university data and stay
valid (a dean keeps working in `/dean`). After rollback only the portal pages close for the VP.
To end a dean, use the portal's «إنهاء التكليف» before rolling back.

## Production conflicts to check

- If a permission with one of these codes already exists in another module or inactive, preflight
  reports `CONFLICT` and apply is `BLOCKED`. Resolve it manually; do not force.
- Colleges with several active deans or dean accounts that also hold a university scope are shown by
  preflight. The portal refuses to modify accounts with a university scope, `super_admin`, roles outside
  `dean` / `doctor_instructor` / `academic_advisor`, or the actor's own account.
- `user_access_scopes` has no dates; dean history lives in `employee_positions` (DEAN rows with
  `start_date` / `end_date`) and in `user_activity_logs` (`module_code = vice_presidency`,
  `dean.appointed` / `dean.transferred` / `dean.ended`, with before/after snapshots, never passwords).
