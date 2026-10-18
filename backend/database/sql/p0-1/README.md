# P0-1 manual SQL-first production runbook

Production deployment is manual and does not run Laravel migrations or seeders:

`backup → preflight_manual → apply_manual → verify_manual → deploy code`

1. Back up and test restore; enter maintenance mode.
2. Run `00_preflight_manual.sql` in phpMyAdmin. Continue only when `can_apply=1`, duplicate identity reports are empty, `PRES` resolves exactly once, and any reported orphan scopes/operational users have been reviewed.
3. Run `01_apply_manual.sql`. DDL is deliberately outside the DML transaction because MySQL/MariaDB implicitly commits DDL. The DML portion is idempotent and establishes `PRES → 7 → 71, 72, 73 → 731–736`. If both `7` and `VP_ADMIN` exist, apply stops for a human decision.
4. Run `02_verify_manual.sql`. Every summarized status must be `PASS`; detail report `NEEDS_MANUAL_SCOPE` must be reviewed and resolved before deploying code.
5. Run apply and verify only once per planned operation; the apply statements are safe to rerun after an interruption and do not append duplicate merge descriptions.

University scope references the existing institutional organizational root with `unit_code='PRES'`; the schema has no universities table and no `university` organizational-unit type. No scope is granted automatically. The commented template in apply is intentionally a human-reviewed example.

Rollback cannot reconstruct merged organizational references or prior role grants without the backup. Never edit Laravel's `migrations` table and never use `DatabaseSeeder`/`DemoAcademicSeeder` for production deployment.
