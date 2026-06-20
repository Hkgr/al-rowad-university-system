# Users, Roles & Permissions API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages system users, roles, permissions, and their associations. It also includes audit logs, system modules (for permission grouping), and password reset token records. Authentication tokens are issued via the Auth module (`POST /api/login`); this module handles user account CRUD and authorization configuration.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List (paginated) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete |

Query: `?per_page=15` (default 15)

---

## Endpoint List

| Resource | Base path |
|----------|-----------|
| Users | `/api/v1/users` |
| Roles | `/api/v1/roles` |
| Permissions | `/api/v1/permissions` |
| User roles | `/api/v1/user-roles` |
| Role permissions | `/api/v1/role-permissions` |
| System modules | `/api/v1/system-modules` |
| Login audit logs | `/api/v1/login-audit-logs` |
| User activity logs | `/api/v1/user-activity-logs` |
| Password reset tokens | `/api/v1/password-reset-tokens` |
| Account statuses | `/api/v1/account-statuses` |

Related auth endpoints (no `/v1` prefix):

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/user` | Current user |
| POST | `/api/logout` | Logout |

---

## POST /api/v1/users

**Purpose:** Create a system user account.

### Request body

```json
{
  "username": "jdoe",
  "email": "jdoe@university.edu",
  "password_hash": "hashedOrPlainPerBackend",
  "account_status_id": 1,
  "student_id": null,
  "employee_id": 1,
  "board_member_id": null,
  "failed_login_attempts": 0,
  "created_by_user_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `username` | `required\|string\|max:80` |
| `email` | `required\|string\|max:150` — recommended: `required\|email\|max:255` |
| `password_hash` | `required\|string\|max:255` |
| `account_status_id` | `required\|integer\|exists:account_statuses,account_status_id` |
| `student_id` | `nullable\|integer\|exists:students,student_id` |
| `employee_id` | `nullable\|integer\|exists:employees,employee_id` |
| `board_member_id` | `nullable\|integer\|exists:board_members,board_member_id` |
| `last_login_at` | `nullable\|date` |
| `email_verified_at` | `nullable\|date` |
| `failed_login_attempts` | `required\|integer` |
| `created_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "user_id": 2,
    "username": "jdoe",
    "email": "jdoe@university.edu",
    "account_status_id": 1,
    "student_id": null,
    "employee_id": 1
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "account_status_id": ["The account status id field is required."]
  }
}
```

### Frontend notes

- Link users to at most one profile type when applicable (`student_id`, `employee_id`, or `board_member_id`).
- Login uses `email` + `password` at `POST /api/login`, not `username`.
- Admin user forms should load `account-statuses` lookup.

---

## POST /api/v1/roles

### Request body

```json
{
  "role_code": "REGISTRAR",
  "role_name": "Registrar",
  "description": "Manages student registration",
  "is_system_role": 0,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `role_code` | `required\|string\|max:80` — recommended: `required\|string\|max:50\|regex:/^[A-Z0-9_-]+$/` |
| `role_name` | `required\|string\|max:150` |
| `description` | `nullable\|string\|max:255` |
| `is_system_role` | `required\|integer` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/permissions

### Request body

```json
{
  "module_id": 1,
  "permission_code": "students.create",
  "permission_name": "Create Students",
  "description": null,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `module_id` | `required\|integer\|exists:system_modules,module_id` |
| `permission_code` | `required\|string\|max:120` |
| `permission_name` | `required\|string\|max:150` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/user-roles

**Purpose:** Assign a role to a user.

### Request body

```json
{
  "user_id": 2,
  "role_id": 3,
  "assigned_by_user_id": 1,
  "assigned_at": "2026-06-20",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `user_id` | `required\|integer\|exists:users,user_id` |
| `role_id` | `required\|integer\|exists:roles,role_id` |
| `assigned_by_user_id` | `nullable\|integer\|exists:users,user_id` |
| `assigned_at` | `nullable\|date` |
| `is_active` | `required\|integer` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "user_role_id": 1,
    "user_id": 2,
    "role_id": 3,
    "is_active": 1
  }
}
```

---

## POST /api/v1/role-permissions

**Purpose:** Grant a permission to a role.

### Request body

```json
{
  "role_id": 3,
  "permission_id": 5,
  "granted_at": "2026-06-20"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `role_id` | `required\|integer\|exists:roles,role_id` |
| `permission_id` | `required\|integer\|exists:permissions,permission_id` |
| `granted_at` | `nullable\|date` |

---

## POST /api/v1/system-modules

### Request body

```json
{
  "module_code": "STUDENTS",
  "module_name": "Students",
  "description": "Student management",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `module_code` | `required\|string\|max:80` |
| `module_name` | `required\|string\|max:150` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## GET /api/v1/users (list)

**Purpose:** Paginated user list for admin screens.

### Query

| Param | Default |
|-------|---------|
| `per_page` | 15 |

### Success response (200)

Paginated user resources in standard envelope.

### Frontend notes

- Build RBAC UI: list roles → assign permissions → assign roles to users.
- Deactivate via `is_active` on user-role records rather than deleting historical assignments.
- Audit tables (`login-audit-logs`, `user-activity-logs`) are read-mostly for security dashboards.
