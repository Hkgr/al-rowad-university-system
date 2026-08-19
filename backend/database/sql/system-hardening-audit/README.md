# Phase 11 — system hardening audit (READ ONLY)

Manual DBA package. Do **not** execute these files from application code,
seeders, or Laravel migrations.

Production database changes remain **MANUAL SQL only**.

Phase 11 does **not** apply schema. It only audits load-bearing
cross-phase invariants after Phases 8–10 (and earlier academic-core SQL)
have been applied.

## Files

| File | Purpose |
|---|---|
| `00_audit.sql` | READ ONLY. Expected result: `OVERALL = PASS` |

There is **no** `01_apply.sql`. Phase 11 discovered no schema defect that
requires a repair script.

## How to run

1. Confirm earlier phase verifiers already returned `OVERALL = PASS`:
   - `backend/database/sql/teaching-assignment-lifecycle/02_verify.sql`
   - `backend/database/sql/student-registration-lifecycle/02_verify.sql`
   - `backend/database/sql/student-academic-progression/02_verify.sql`
2. If an earlier phase was never applied, run that phase's own
   `00_preflight` → `01_apply` → `02_verify`. Never use Phase 11 as a
   substitute for missing schema.
3. Take a database backup immediately before any earlier-phase apply.
4. Run `00_audit.sql` in phpMyAdmin / the DBA workflow against MariaDB.
5. Continue only when the final row is `OVERALL = PASS`.

## Rules encoded in `00_audit.sql`

- Fully qualified schema: `alrowad_uni_rust`
- Does not use `DATABASE()`
- No `INSERT` / `UPDATE` / `DELETE` / `ALTER` / `CREATE` / `DROP` / `TRUNCATE`
- Missing infrastructure yields `FAIL`, not SQL error `#1146` / `#1054`
- Optional cross-phase objects are queried only after `information_schema` guards

## Result meaning

`OVERALL = PASS` means the **database contract** for academic-core
hardening is intact.

It does **not** by itself mean the university system is production-ready.
See `backend/docs/production-academic-core-checklist.md`.
