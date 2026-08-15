# Dean student read access

This manual runbook grants the active `dean` role only:

- `students.view` for the scoped student list and student profile;
- `academic_structure.view` for the college, department, academic-program, and academic-level lookups used by the existing students page.

College isolation remains enforced by the dean user's existing `user_access_scopes` row and `DataScopeService`. This runbook does not add or modify access scopes.
Apply is blocked unless every active dean user has exactly one valid active college scope.

## Execution order

1. Run `00_preflight.sql`.
2. Continue only when its `OVERALL` row is `READY`.
3. Run `01_apply.sql`.
4. Run `02_verify.sql` and require every check, including `OVERALL`, to be `PASS`.
5. Use `03_rollback.sql` only if necessary.

The rollback is intentionally operator-reviewed because `role_permissions` has no provenance column. Capture the apply output and provide the exact ID and timestamp only for rows marked `INSERTED_BY_THIS_RUN`; safe defaults remove nothing.

All files are manual SQL. Do not execute them through Laravel migrations or seeders.
