# P0-1 manual SQL-first production runbook

Production deployment is manual and does not run Laravel migrations or seeders:

`backup → preflight_manual → apply_manual → verify_manual → deploy code`

1. Back up production, prove the restore, and run all three scripts against that restored copy first.
2. Run `00_preflight_manual.sql`. Continue only when `can_apply=1`; the required operational identities are exactly `registrar` and `exam.board`, and employee links may still be null; apply creates deterministic staff rows when needed.
3. Run `01_apply_manual.sql`. It idempotently seeds `PRES` when absent, upserts all 58 units in the official chart, creates or moves the two employees to units `732` and `735`, and grants both users an active university scope rooted at `PRES`.
4. Run `02_verify_manual.sql`. Do not deploy unless `organizational_units_exact_58`, every authorization/identity/scope check, and `OVERALL` all return `PASS`, and the mismatch detail query returns no rows.
5. The mirrored `00_preflight.sql`, `01_apply.sql`, and `02_verify.sql` are byte-for-byte production-script counterparts and must remain synchronized with the manual files.

DDL can commit implicitly in MySQL/MariaDB. The apply DML is transactional and safe to run repeatedly; its parent-ordered upserts do not use temporary-table self-reopen patterns.

PR #21 is safe to merge only after `00_preflight_manual.sql`, `01_apply_manual.sql`, and `02_verify_manual.sql` all pass on a copy of the original production database. Do not run the development seeder in production and do not merge merely because static tests pass.
