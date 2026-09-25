# Technical Office: account editing + activity log — manual SQL

Project policy: manual SQL only (no Laravel migrations). Scripts are fully qualified on `alrowad_uni_rust`,
resolve ids by code, start with `SET NAMES utf8mb4`, and run in phpMyAdmin without a selected database.
**Nothing here was applied to the production database.**

## What is added

| Object | Code | Notes |
|---|---|---|
| Permission | `user_accounts.holder_name.manage` | Correct the holder's name (first/last/father/mother) on the employee or student row the account is already linked to. No other field, no new person, never login fields. |
| Permission | `system_activity.view` | Read the filtered, sanitized activity feed (`/api/v1/technical/activity`). Read-only. |
| Mapping | `technical_team` → both; `super_admin` → both | tagged `[technical-accounts-activity]` |
| Index | `user_activity_logs(created_at, activity_log_id)`, `user_activity_logs(module_code, created_at)`, `login_audit_logs(attempted_at)` | Feed sorting/filtering by period and module. |

Username/email editing and password reset reuse the existing `user_accounts.manage` permission; no new permission.
No `users.name` column is added: holder names already live on `employees` / `students`.

## Prerequisites

- Package `technical-team-portal` applied (role `technical_team`, module `users_permissions`, `user_accounts.*`).
- `technical_team` holds no `users_permissions.*` / `system_settings.manage` (apply is BLOCKED otherwise).

## Execution order (phpMyAdmin → SQL tab, one file at a time)

1. Full database backup.
2. `00_preflight.sql` — read-only: no `MISSING` columns, prerequisites present, permissions `ABSENT` (or `COMPATIBLE` on re-run), no restricted permission on `technical_team`. Indexes show `NOT_PRESENT` before the first apply.
3. `01_apply.sql` — expect `APPLIED`. `BLOCKED` writes nothing (indexes included); the columns name the failed condition. Re-running is safe.
   Index creation is DDL (not transactional); on large log tables run it off-peak.
4. `02_verify.sql` — every row `PASS`.
5. Deploy the application code (backend + frontend build). Users sign in again (or reload) to pick up the new permissions.

To keep the holder-name correction for `super_admin` only, delete the `technical_team` mapping of
`user_accounts.holder_name.manage` after applying (verify will then report `FAIL` for "technical_team holds both" — expected in that setup).

## Rollback

`03_rollback.sql` deletes the two tagged permissions and their mappings, then drops the three indexes.
It reports `BLOCKED` (and changes nothing) if a permission is untagged or mapped to another role.
It never deletes accounts, log rows, employees or students: name corrections and audit rows written while
the feature was active remain as real history.
