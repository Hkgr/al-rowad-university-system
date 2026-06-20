# Lookups API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module provides reference/lookup tables (status codes and types) used across registration, grades, attendance, accounts, and approvals. Each lookup supports standard CRUD for administrators and read-only list endpoints for frontend dropdowns.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

Every lookup resource supports:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List all (paginated) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show one |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete |

**Query:** `?per_page=15` (default)

**List success (200):**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [
      {
        "status_code": "registered",
        "status_name": "Registered",
        "is_active": 1
      }
    ],
    "links": {},
    "meta": {}
  }
}
```

**Validation error (422):**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "status_code": ["The status code field is required."]
  }
}
```

---

## Endpoint List

| Lookup | Base path | Used by |
|--------|-----------|---------|
| Registration statuses | `/api/v1/registration-statuses` | Course registration (registered, dropped, withdrawn) |
| Result statuses | `/api/v1/result-statuses` | Grades (passed, failed, deprived, incomplete) |
| Student statuses | `/api/v1/student-statuses` | Student records |
| Account statuses | `/api/v1/account-statuses` | User accounts |
| Attendance statuses | `/api/v1/attendance-statuses` | Attendance marking |
| Appeal statuses | `/api/v1/appeal-statuses` | Grade appeals |
| Approval statuses | `/api/v1/approval-statuses` | Grade approvals |
| Document types | `/api/v1/document-types` | Admissions / student documents |
| Employee types | `/api/v1/employee-types` | Employees module |
| Employee statuses | `/api/v1/employee-statuses` | Employees module |

---

## POST /api/v1/registration-statuses

### Request body

```json
{
  "status_code": "registered",
  "status_name": "Registered",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50` — recommended: `required\|string\|max:50\|regex:/^[A-Z0-9_-]+$/` |
| `status_name` | `required\|string\|max:100` |
| `is_active` | `required\|integer` |

### Domain codes (expected in business logic)

| Code | Meaning |
|------|---------|
| `registered` | Active enrollment |
| `dropped` | Dropped course |
| `withdrawn` | Withdrawn course |

---

## POST /api/v1/result-statuses

### Request body

```json
{
  "status_code": "passed",
  "status_name": "Passed",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50` |
| `status_name` | `required\|string\|max:100` |
| `is_active` | `required\|integer` |

### Domain codes (expected in business logic)

| Code | Letter grade | GPA impact |
|------|--------------|------------|
| `passed` | A+ to D | Included |
| `failed` | F (0.00 points) | Included |
| `deprived` | Z | **Excluded** |
| `incomplete` | I | **Excluded** |
| `withdrawn` | W | **Excluded** |

---

## POST /api/v1/student-statuses

### Request body

```json
{
  "status_code": "active",
  "status_name": "Active",
  "description": "Currently enrolled",
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50\|unique:student_statuses,status_code` |
| `status_name` | `required\|string\|max:100` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/account-statuses

### Request body

```json
{
  "status_code": "active",
  "status_name": "Active",
  "description": null,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50` |
| `status_name` | `required\|string\|max:100` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/attendance-statuses

### Request body

```json
{
  "status_code": "present",
  "status_name": "Present",
  "counts_as_absent": 0,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50` |
| `status_name` | `required\|string\|max:100` |
| `counts_as_absent` | `required\|integer` (0 = present-type, 1 = counts toward absence) |
| `is_active` | `required\|integer` |

### Domain codes (typical)

| Code | counts_as_absent |
|------|------------------|
| `present` | 0 |
| `late` | 0 |
| `absent` | 1 |
| `excused` | configurable |

**Frontend notes:** Absence percentage uses statuses where `counts_as_absent = 1`. Deprivation applies when absence **> 15%**.

---

## POST /api/v1/appeal-statuses

### Request body

```json
{
  "status_code": "pending",
  "status_name": "Pending",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|max:50` |
| `status_name` | `required\|string\|max:100` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/approval-statuses

### Request body

```json
{
  "status_code": "approved",
  "status_name": "Approved",
  "is_active": 1
}
```

### Validation rules

Same pattern as appeal statuses.

---

## GET /api/v1/registration-statuses (list)

**Purpose:** Populate registration status dropdowns.

**Request body:** None

### Success response (200)

Paginated list of status records.

### Frontend notes

- Cache lookup lists in frontend state; refresh on admin changes.
- Filter client-side with `is_active = 1` for dropdowns.
- Do not hardcode IDs — always use `status_code` for display logic and IDs from API for writes.
- Critical lookups for frontend forms:
  - **Student create:** `student-statuses`
  - **Registration UI:** `registration-statuses` (read-only context)
  - **Grade display:** `result-statuses`
  - **Attendance marking:** `attendance-statuses`
  - **User create:** `account-statuses`

---

## Calendar context endpoints (related lookups)

These are not status tables but commonly used alongside lookups:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/academic-years/current` | Current year |
| GET | `/api/v1/semesters/active` | Active semesters |
| GET | `/api/v1/academic-years` | All years |
| GET | `/api/v1/semesters` | All semesters |

See **Academic Structure** contract for details.
