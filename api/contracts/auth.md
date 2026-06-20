# Authentication API Contract

## Base URL

- Application: `http://127.0.0.1:8000`
- Login and session routes: `http://127.0.0.1:8000/api`

## Introduction

This module handles user authentication for the Al Rowad University System. It provides login to obtain a Bearer token (Laravel Sanctum), retrieval of the current authenticated user, and logout. All `/api/v1/*` endpoints require a valid token obtained from this module.

## Authentication Requirements

| Endpoint | Auth required |
|----------|---------------|
| `POST /api/login` | No |
| `GET /api/user` | Yes — Bearer token |
| `POST /api/logout` | Yes — Bearer token |

### Required headers (authenticated routes)

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard Response Envelope

**Success:**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {}
}
```

**Error:**

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/login` | Authenticate and receive Bearer token |
| GET | `/api/user` | Get current authenticated user |
| POST | `/api/logout` | Revoke current access token |

---

## POST /api/login

**Purpose:** Authenticate a user with email and password and return a Sanctum Bearer token.

**Auth:** Not required

### Request body

```json
{
  "email": "admin@university.edu",
  "password": "secret-password"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `email` | `required\|email\|max:255` |
| `password` | `required\|string` |

### Success response (200)

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "user_id": 1,
      "username": "admin",
      "email": "admin@university.edu",
      "account_status_id": 1,
      "student_id": null,
      "employee_id": 1,
      "board_member_id": null
    },
    "token": "1|plainTextTokenValue",
    "token_type": "Bearer"
  }
}
```

### Error response (422 — invalid credentials)

```json
{
  "success": false,
  "message": "Invalid email or password",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

### Error response (422 — validation)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field is required."]
  }
}
```

### Frontend notes

- Store `data.token` and send it as `Authorization: Bearer {token}` on all subsequent API calls.
- The backend validates against `password_hash`, not `password`.
- On 422 with invalid credentials, display `message` or `errors.email[0]` to the user.
- Do not persist the password; only persist the token (secure storage recommended).

---

## GET /api/user

**Purpose:** Return the currently authenticated user record.

**Auth:** Required

### Request body

None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "user_id": 1,
    "username": "admin",
    "email": "admin@university.edu",
    "account_status_id": 1,
    "student_id": null,
    "employee_id": 1,
    "board_member_id": null,
    "last_login_at": "2026-06-20T10:00:00.000000Z"
  }
}
```

### Error response (401 — unauthenticated)

```json
{
  "message": "Unauthenticated."
}
```

### Frontend notes

- Call on app startup or after login to hydrate user context (linked `student_id`, `employee_id`, etc.).
- If this returns 401, clear stored token and redirect to login.

---

## POST /api/logout

**Purpose:** Delete the current access token (logout from this device/session).

**Auth:** Required

### Request body

None (empty JSON `{}` is acceptable)

### Success response (200)

```json
{
  "success": true,
  "message": "Logged out successfully",
  "data": []
}
```

### Error response (401 — unauthenticated)

```json
{
  "message": "Unauthenticated."
}
```

### Frontend notes

- After success, remove the stored token locally.
- Only the current token is revoked; other sessions remain valid.
