# P0-1 SQL-first production runbook

Production does not run Laravel migrations or seeders. The mandatory order is:

`backup → 00_preflight.sql → 01_apply.sql → 02_verify.sql → deploy code`

1. Take and test a full database backup. Run all scripts with the application in maintenance mode.
2. Run `00_preflight.sql`; stop if key types are not signed `INT`, required tables/roles/permissions are missing, or findings have not been reviewed. It reports missing/excess role grants and linked/unlinked/conflicting identities using `BINARY` email comparison. It never links an identity.
3. Run `01_apply.sql`. It creates the single scope table, replaces the four system-role permission sets with the approved exact matrix, and upserts units 73/731–736. Unit 735 uses `administration`, never `directorate`. No user identity or scope is inferred.
4. Run `02_verify.sql`. Any operational registration/exam user returned without a scope requires an explicit, manually approved `user_access_scopes` insert before code deployment. Duplicate output must be empty. Re-run apply and verify once on the test database to demonstrate idempotency.
5. Deploy code only after verification is clean. Do not insert into Laravel's `migrations` table and do not run `DatabaseSeeder`/`DemoAcademicSeeder` in production.

`03_rollback.sql` removes the new scope table. Because permission and organizational data may predate P0-1, restore those tables from the mandatory backup rather than applying destructive guessed reversals.
