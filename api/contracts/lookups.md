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
| DELETE | `/api/v1/{resource}/{id}` | Delete (**existing** — currently hard delete) |

> **Note:** Soft delete, restore, and force-delete routes are **not yet implemented**. See [Deletion Policy](#deletion-policy) for the recommended future contract.

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

---

## Deletion Policy

Documents **Soft Delete** and **Permanent Delete** for lookup/status tables. Lookups are referenced by foreign keys across the system — **permanent delete is rarely appropriate**.

### Soft Delete vs Permanent Delete

| Action | Meaning |
|--------|---------|
| **Soft Delete** | Archive lookup row (`deleted_at`) or set `is_active = 0` |
| **Permanent Delete** | Remove row — **Super Admin only**; blocked when referenced by live records |

> **Implementation status:** Existing `DELETE` on lookup resources (**existing**, hard delete). Most lookups already have `is_active` — **deactivating** is the preferred current approach. Full soft-delete with `deleted_at` is a **proposed future enhancement**.

### Who may perform each action

| Action | Recommended role |
|--------|------------------|
| Deactivate / Soft Delete | Admin |
| Restore | Admin |
| Permanent Delete | **Super Admin only** (almost never for lookups) |

### Business rules before deletion

**Permanent delete blocked when lookup is referenced by:**

| Lookup | Referenced by |
|--------|---------------|
| `registration-statuses` | `student_course_registrations` |
| `result-statuses` | `student_course_results`, grades, prerequisites |
| `student-statuses` | `students` |
| `account-statuses` | `users` |
| `attendance-statuses` | `student_attendance` |
| `appeal-statuses` | `grade-appeals` |
| `approval-statuses` | `grade-approvals` |

**Recommended approach:** Set `is_active = 0` (or soft delete) instead of permanent delete. Historical records keep valid FK references.

### Proposed endpoints (example: student-statuses)

| Method | URL | Status |
|--------|-----|--------|
| DELETE | `/api/v1/student-statuses/{id}` | **Existing** — recommend deactivate or soft delete |
| GET | `/api/v1/student-statuses/deleted` | **Proposed endpoint** |
| POST | `/api/v1/student-statuses/{id}/restore` | **Proposed endpoint** |
| DELETE | `/api/v1/student-statuses/{id}/force` | **Proposed endpoint** |

Apply the same pattern to all lookup resources in this module.

**Optional request body:**

```json
{
  "delete_reason": "Status code replaced by new workflow",
  "deleted_by_user_id": 1
}
```

**Validation rules:**

| Field | Rules |
|-------|-------|
| Resource ID (URL) | `required\|integer\|exists:{table},{primary_key}` |
| `delete_reason` | `nullable\|string\|max:1000` |
| `deleted_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Standard responses

**Soft delete / deactivate:**

```json
{
  "success": true,
  "message": "Record archived successfully.",
  "data": null
}
```

**Restore:**

```json
{
  "success": true,
  "message": "Record restored successfully.",
  "data": {
    "student_status_id": 1,
    "status_code": "active",
    "is_active": true,
    "deleted_at": null
  }
}
```

**Permanent delete:**

```json
{
  "success": true,
  "message": "Record permanently deleted successfully.",
  "data": null
}
```

**Blocked permanent delete (422):**

```json
{
  "success": false,
  "message": "Record cannot be permanently deleted because related records exist.",
  "errors": {
    "related_records": [
      "students",
      "course_registrations"
    ]
  }
}
```

### Frontend behavior

- Show only `is_active = 1` (and non-deleted) lookups in dropdowns.
- Admin screens may show inactive/archived lookups with a badge.
- Use **Deactivate** or **Archive** instead of **Delete** in lookup management UI.
- **Permanent Delete** — Super Admin only, confirmation modal; almost never used for lookups.
- Warn admin if lookup is still referenced before any delete action.
- Never permanently delete status codes that appear in historical grades, registrations, or attendance.
