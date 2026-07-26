# Users, Roles, and Permissions API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

Every endpoint requires an active Sanctum session and the
`users_permissions.view` or `users_permissions.manage` permission.
Student-only accounts cannot use these staff endpoints.

## Resources

| Resource | Read | Create/update/delete |
|---|---|---|
| `/users` | `users_permissions.view` | `users_permissions.manage` |
| `/roles` | `users_permissions.view` | `users_permissions.manage` |
| `/permissions` | `users_permissions.view` | `users_permissions.manage` |
| `/user-roles` | `users_permissions.view` | `users_permissions.manage` |
| `/role-permissions` | `users_permissions.view` | `users_permissions.manage` |
| `/system-modules` | `users_permissions.view` | `users_permissions.manage` |
| `/account-statuses` | `users_permissions.view` | `system_settings.manage` |
| `/login-audit-logs` | `users_permissions.view` | Read-only |
| `/user-activity-logs` | `users_permissions.view` | Read-only |

Password-reset token hashes are intentionally not exposed through REST CRUD.

## Create a user

`POST /api/v1/users`

```json
{
  "username": "registrar.one",
  "email": "registrar.one@university.edu",
  "password": "a-long-random-password",
  "password_confirmation": "a-long-random-password",
  "account_status_id": 1,
  "employee_id": 7,
  "student_id": null,
  "board_member_id": null
}
```

Rules:

- `username` and `email` are unique.
- Passwords are hashed by the server and must be at least 12 characters.
- Exactly zero or one of `student_id`, `employee_id`, and `board_member_id`
  may be supplied.
- `created_by_user_id`, failed-login counters, and timestamps are derived by
  the server and cannot be spoofed by the client.

Updating a password or setting an account to a non-active status revokes all of
that user's access tokens.

## Disable a user

`DELETE /api/v1/users/{user}`

This endpoint does not hard-delete the database row. It changes the account
status to `disabled` and revokes all tokens. A user cannot disable their own
account.

## Assign a role

`POST /api/v1/user-roles`

```json
{
  "user_id": 4,
  "role_id": 10
}
```

`assigned_by_user_id` and `assigned_at` are always derived from the signed-in
administrator and server clock. Assigning the same role reactivates the
existing assignment instead of creating a duplicate.

`DELETE /api/v1/user-roles/{userRole}` deactivates the assignment and preserves
its audit history. An administrator cannot deactivate their own role.

## Grant a permission

`POST /api/v1/role-permissions`

```json
{
  "role_id": 10,
  "permission_id": 38
}
```

The server records `granted_at`. Duplicate grants are rejected. Role-permission
records support list, show, create, and delete only; an existing grant cannot
be repointed to another role or permission.

## Permission semantics

- `super_admin` bypasses permission checks.
- A `*.manage` grant implies the matching `*.view` capability.
- Inactive roles, user-role assignments, and permissions do not grant access.
- The backend is authoritative. Frontend guards and hidden navigation improve
  the user experience but are not a security boundary.
