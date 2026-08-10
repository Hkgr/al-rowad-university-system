# P0-1 manual SQL-first production runbook

Production deployment is manual and does not run Laravel migrations or seeders:

`backup → preflight_manual → apply_manual → verify_manual → deploy code`

1. Back up and test restore; enter maintenance mode.
2. Run `00_preflight_manual.sql` in phpMyAdmin. Continue only when `can_apply=1`, duplicate identity reports are empty, `PRES` is absent or unique, and the test users `registrar` and `exam.board` are reported as ready to link. Empty `organizational_units` and `employees` tables are supported. A missing `user_access_scopes` table is expected before the first apply and is not a blocker; existing invalid scopes remain blockers.
3. Run `01_apply_manual.sql`. DDL is deliberately outside the DML transaction because MySQL/MariaDB implicitly commits DDL. The DML portion idempotently seeds the complete approved chart `PRES → 7 → 71, 72, 73 → 731–736`, including the root; it does not assume `PRES` exists. It also creates deterministic `P01-REGISTRAR` and `P01-EXAM-OFFICER` employee identities, links the existing test users, activates their matching roles, and grants reviewed university scopes. No password is inserted or reset. Required P0-1 grants are added without deleting any existing/custom role grant.
4. Run `02_verify_manual.sql`. `official_chart_exact` verifies every expected unit's code, Arabic name, type, active state, and parent; every summarized status must be `PASS`, while `OFFICIAL_CHART_MISMATCH` and `NEEDS_MANUAL_SCOPE` detail reports must be empty.
5. Run apply and verify only once per planned operation; the apply statements are safe to rerun after an interruption and do not append duplicate merge descriptions.

University scope references the chart root with `unit_code='PRES'`; the schema has no universities table and no `university` organizational-unit type. The two explicit P0-1 test staff accounts receive that scope automatically; other operational accounts remain a manual review item in verification.

Rollback cannot reconstruct merged organizational references or prior role grants without the backup. Never edit Laravel's `migrations` table and never use `DatabaseSeeder`/`DemoAcademicSeeder` for production deployment.
