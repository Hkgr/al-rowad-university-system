# Ministry of Education portal (بوابة وزارة التربية والتعليم) — manual SQL

Project policy: manual SQL only (no Laravel migrations on the university database). The scripts use fully qualified
names on `alrowad_uni_rust`, resolve ids by code, start with `SET NAMES utf8mb4`, and run in phpMyAdmin without a
selected database. **Nothing here has been applied to the production database.**

## What is added

| Object | Code | Notes |
|---|---|---|
| Module | `ministry_portal` | tagged `[ministry-portal]` |
| Role | `ministry_observer` — «متابعة وزارة التربية والتعليم» | `is_system_role = 1`. Read-only follow-up role, inherits no other role. |
| Permissions | `ministry_portal.access`, `.dashboard.view`, `.deans.view`, `.students.view`, `.colleges.view`, `.courses.view`, `.faculty.view`, `.leadership.view` | Mapped to `ministry_observer` **only** (not to super_admin, not to any other role). |

**Not added:**
- users or passwords;
- role assignment (`user_roles`) or access scopes (`user_access_scopes`);
- indexes or columns.

The portal's data scope is fixed to the whole university by the application itself (read-only endpoints under
`/api/v1/ministry`). It does not need a `user_access_scopes` row.

## Execution order (phpMyAdmin → SQL tab, one file at a time)

1. **Backup:** take a full backup of the database.
2. **`00_preflight.sql`:** read-only. Expected results:
   - no `MISSING` columns;
   - module and role `ABSENT`, or `COMPATIBLE` on a re-run;
   - the 8 permissions `ABSENT`, or `COMPATIBLE` on a re-run;
   - `foreign_mappings` = 0.

   `community_vp_role` is informational only. `NOT_IN_SYSTEM` means there is no community-affairs vice-president role, which is the current state.
3. **`01_apply.sql`:** expect `APPLIED`.
   - `BLOCKED` writes nothing, and the result columns name the condition that failed.
   - Re-running is safe: the second run also reports `APPLIED`, and the start state reads `COMPATIBLE`.
4. **`02_verify.sql`:** every row must read `PASS`.
5. **Deploy the application code:** backend and frontend build.
6. **Create the ministry account** through the approved path: see *Account* below.

## Account (after deployment, through the application, not SQL)

The role is not on the Technical Office's assignable list (`AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES`), so only
**super_admin** can assign it:

1. Sign in as super_admin → «المكتب التقني» → «الحسابات والصلاحيات».
2. Create the account: username, the ministry e-mail, and a temporary password hashed by the server. Deliver the password through a secure channel.
3. Assign the role «متابعة وزارة التربية والتعليم» (`ministry_observer`), and **no other role**. `02_verify.sql` check 8 flags a ministry account that holds another role.
4. The account signs in and lands on `/ministry`.

A ministry account is confined server-side (`ConfineMinistryAccounts`). Every `/api/v1` route other than
`/api/v1/ministry/*` returns 403, even if the account also holds another role.

## Rollback

`03_rollback.sql` deletes only the tagged mappings, permissions, role and module.

**BLOCKED** (nothing deleted) when any of these holds:
- any `user_roles` row (active or not) holds `ministry_observer`: revoke the role from the account and review first;
- another role holds a ministry permission;
- the ministry role holds another permission;
- an object is untagged.

It never deletes users, `user_roles`, logs, or any academic data.

Re-running reports `NOTHING_TO_DO`.

## Tested sequence (local MariaDB 10.11, copy of the schema — not production)

1. preflight: all ABSENT, 0 MISSING
2. apply: APPLIED
3. verify: 8 × PASS
4. apply again: APPLIED (start state COMPATIBLE)
5. verify: 8 × PASS
6. map a ministry permission to `dean`: apply BLOCKED, rollback BLOCKED
7. assign the role to an account (inactive row): rollback BLOCKED
8. clean state: rollback ROLLED_BACK
9. rollback again: NOTHING_TO_DO
10. preflight: ABSENT
11. apply: APPLIED
12. verify: 8 × PASS
