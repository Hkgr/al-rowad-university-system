# P0-1 SQL-first production runbook

Production does not run Laravel migrations or seeders. The mandatory order is:

`backup → preflight → apply → verify → deploy code`

1. Take and test a full database backup and put the application in maintenance mode.
2. Run `00_preflight.sql`. Stop for missing/mismatched signed key types, duplicate student/employee identity links, missing/ambiguous unit code `7`, unexpected permission findings, orphan scopes, or ambiguous legacy-unit references. Email reconciliation reports use `BINARY`; no identity or scope is inferred.
3. Run `01_apply.sql`. It aborts before data changes if identity uniqueness or parent unit `7` is unsafe, creates the scope table, adds nullable unique identity indexes, exactly synchronizes only the four P0-1 system roles, establishes `7 → 73 → 731–736`, migrates all known organizational-unit foreign keys from `REG_OFFICE`/`EXAM_OFFICE` to `732`/`735`, then disables the legacy rows.
4. Run `02_verify.sql`. Every `failures` value must be zero. Any operational registration/exam user without a valid, non-orphan scope requires a manually approved scope before deployment. Re-run **apply then verify a second time** on the test copy; results must remain clean and no row counts may increase.
5. Deploy code only after verification is clean. Never insert into Laravel's `migrations` table and never run `DatabaseSeeder` or `DemoAcademicSeeder` in production.

`03_rollback.sql` removes only P0-1 structural additions. It cannot reconstruct merged foreign-key values, role grants, organizational names, or active flags; restore those data from the mandatory backup. The Laravel migration remains development-only and is guarded when SQL created the table first.
