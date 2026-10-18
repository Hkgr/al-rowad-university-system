# Technical Office portal (المكتب التقني) — manual SQL

Project policy: database changes are applied by **manual SQL only**; no Laravel migrations.
All scripts target `alrowad_uni_rust` with fully qualified names, resolve every id by code
(`module_code`, `role_code`, `permission_code`, `status_code`) and never create users,
passwords or role assignments.

Organizational placement (unit `715` «المكتب التقني» under `71` «مديرية الشؤون الإدارية»)
grants nothing in the system. Access comes only from the `technical_team` role.

## What is added

| Object | Code | Notes |
|---|---|---|
| Role | `technical_team` («الفريق التقني») | `is_system_role = 1` |
| Permission | `technical_portal.access` | opens the portal; identity only |
| Permission | `user_accounts.view` | list/show accounts, roles and role-derived permissions |
| Permission | `user_accounts.manage` | create accounts, assign/revoke **allowlisted** roles, enable/disable |
| Mapping | `technical_team` → the three permissions | never `users_permissions.*` |
| Mapping | `super_admin` → `user_accounts.view`, `user_accounts.manage` | explicit, although super_admin already bypasses |

The allowlist of roles the technical team may assign is enforced in application code
(`App\Support\AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES`), not in SQL.

## Execution order

1. Take a database backup.
2. `00_preflight.sql` — read-only. Check: no `MISSING` columns/keys, module `users_permissions`
   exists, target objects are `ABSENT` or `COMPATIBLE` (not `CONFLICT`),
   `technical_team_restricted_permissions = 0`, `active_super_admin_accounts >= 1`.
3. `01_apply.sql` — expect `apply_status = APPLIED`. `BLOCKED` means a safety condition failed
   and nothing was written; the output columns name the condition.
   Re-running is safe (no duplicates, no rewrites).
4. `02_verify.sql` — every row must be `PASS`.
5. Assign the role to a real employee account through the portal
   (`/technical/accounts`) while signed in as `super_admin`. `technical_team` is not in the
   technical allowlist, so only `super_admin` can grant it. No default admin account is created.

## Rollback

`03_rollback.sql` deletes only rows tagged `[technical-team-portal]`. It reports `BLOCKED`
and deletes nothing if any `user_roles` row (active or inactive) references `technical_team`,
if another role is mapped to the three permissions, or if any of the objects is untagged.
It never deletes `user_roles`, `users` or `user_activity_logs` rows.
