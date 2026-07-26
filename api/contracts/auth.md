# Authentication and Identity API Contract

## Base URL

- Application: `http://127.0.0.1:8000`
- Authentication routes: `http://127.0.0.1:8000/api`
- Versioned API: `http://127.0.0.1:8000/api/v1`

## Security model

Laravel Sanctum authenticates the request. Active RBAC roles and permissions
authorize each API operation. A valid token alone is not sufficient.

All authenticated routes also verify that the linked account status is the
active status. Disabling or locking an account invalidates the token on its
next request.

| Endpoint | Authentication | Additional rule |
|---|---|---|
| `POST /api/login` | Public | Throttled to 5 attempts per minute |
| `GET /api/user` | Bearer token | Account must be active |
| `POST /api/logout` | Bearer token | Account must be active |
| `/api/v1/*` | Bearer token | Account active and route permission required |

Authenticated requests use:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard response envelope

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {}
}
```

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

## POST /api/login

Request:

```json
{
  "email": "exam.board@university.edu",
  "password": "secret-password"
}
```

Successful response:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "user_id": 4,
      "username": "exam.board",
      "email": "exam.board@university.edu",
      "account_status": {
        "code": "active",
        "name": "Active"
      },
      "student_id": null,
      "employee_id": 7,
      "faculty_member_id": null,
      "board_member_id": null,
      "last_login_at": "2026-07-26T10:00:00.000000Z",
      "roles": [
        {
          "code": "exam_officer",
          "name": "Exam Officer"
        }
      ],
      "role_codes": ["exam_officer"],
      "permissions": [
        "courses.view",
        "exams.manage",
        "exams.view",
        "grades.manage",
        "grades.view",
        "students.view"
      ],
      "dashboards": [
        {
          "code": "exam-board",
          "path": "/exam-board"
        }
      ],
      "default_dashboard": "/exam-board"
    },
    "token": "1|plainTextTokenValue",
    "token_type": "Bearer"
  }
}
```

Invalid credentials return `422`. An inactive, disabled, locked, or pending
account returns `403`:

```json
{
  "success": false,
  "message": "This account is not active.",
  "errors": {
    "account": ["Contact the system administrator to restore access."]
  }
}
```

## GET /api/user

Returns the same identity object shown under `data.user` in the login response,
but directly under `data`.

The frontend must hydrate its authorization context from this response. It must
use `default_dashboard`, `dashboards`, and `permissions`; it must not infer a
role from `student_id`, `employee_id`, or `board_member_id`.

## POST /api/logout

Revokes the current Sanctum token:

```json
{
  "success": true,
  "message": "Logged out successfully",
  "data": []
}
```

## Authorization failures

A signed-in user who lacks the route permission receives `403`:

```json
{
  "success": false,
  "message": "You do not have permission to perform this action.",
  "errors": {
    "required_permissions": ["grades.manage"]
  }
}
```

Student-owned endpoints permit the linked student to access only their own
record. Staff access requires the corresponding permission. Faculty grade and
attendance operations are additionally restricted to course offerings assigned
to that faculty member.
