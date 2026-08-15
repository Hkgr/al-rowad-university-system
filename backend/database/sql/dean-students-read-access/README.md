# Dean student read access

This manual SQL runbook grants the existing active `dean` role only:

- `students.view`
- `academic_structure.view`

It does not create roles, permissions, users, or access scopes. It does not grant `students.manage` or `academic_structure.manage`.

## Execution order

1. Run `00_preflight.sql`.
2. Continue only when every prerequisite and `OVERALL` return `READY`.
3. Run `01_apply.sql`.
4. Run `02_verify.sql` and require every permission check and `OVERALL` to return `PASS`.

The dean user's existing college scope continues to be enforced by `DataScopeService`.
