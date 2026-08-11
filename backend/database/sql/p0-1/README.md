# P0-1 manual SQL-first production runbook

Production deployment is manual and does not run Laravel migrations or seeders. Always begin with a recent production restore in an isolated test environment:

`backup → preflight_manual → apply_manual → verify_manual → deploy code`

1. Restore a recent production backup in a safe test environment and prove the restore works.
2. Run `00_preflight_manual.sql`. Continue only when every prerequisite report is clear and the final `can_apply=1`. Empty `organizational_units` and `employees` tables and null employee links for `registrar`/`exam.board` are supported; duplicates, incompatible indexes/schema, invalid or missing operational scopes, unexpected employee links, unknown organizational foreign keys, or ambiguous deterministic identities are blockers.
3. Run `01_apply_manual.sql`. It idempotently corrects only the 58 official chart codes (including `PRES`) without deleting unrelated units, reconciles the confirmed aliases listed below, creates/links `P01-REGISTRAR` and `P01-EXAM-OFFICER`, maps username `exam.board` to role `exam_officer`, adds required scopes/grants without removing custom grants, and creates missing identity uniqueness indexes.
4. Run `02_verify_manual.sql`. Every category and `OVERALL` must be `PASS`, and the `OFFICIAL_CHART_MISMATCH` detail report must be empty.
5. Merge/deploy only after steps 2–4 pass in order on the restored database. The apply script is safe to rerun for an explicit idempotency test.

The authoritative set is exactly 58 expected records; unrelated production rows may coexist. It includes direct children `1`–`9` under `PRES`, the complete descendant branches through `911` and `925`, and the exact corrections for `22`, `23`, and `736`. University scope references `PRES`; the schema has no universities table or `university` organizational-unit type.

The only automatic legacy mappings are `VP_ADMIN→7`, `VP_SCI→8`, `VP_COMM→9`, `HR_OFFICE→711`, `LIBRARY→13`, `REG_OFFICE→732`, and `EXAM_OFFICE→735`. Apply creates the official target first, moves every known organizational foreign key and child, converts unambiguous legacy vice-presidency university scopes to `PRES`, and only then deactivates an unreferenced alias. Any unknown organizational foreign key or ambiguous scope stops deployment for manual review.

The complete human-readable code/name/type/parent contract is in [`docs/P0_1_OFFICIAL_ORGANIZATIONAL_CHART.md`](../../../docs/P0_1_OFFICIAL_ORGANIZATIONAL_CHART.md); the executable Seeder source is `database/seeders/data/p01_official_chart.php`.

Rollback cannot reconstruct merged organizational references or prior role grants without the backup. Never edit Laravel's `migrations` table and never use `DatabaseSeeder`/`DemoAcademicSeeder` for production deployment.
