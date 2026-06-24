# Employees API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages university staff: employees, faculty members, organizational units, positions, and related assignment/status/type lookups. It supports HR and academic staffing workflows linked to course instructors and organizational structure.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

All resources below support:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List (paginated, `?per_page=15`) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete (**existing** — currently hard delete) |

**Success (store):** HTTP 201  
**Success (update/show):** HTTP 200  
**Success (destroy):** HTTP 200, `"data": []`  
**Validation error:** HTTP 422 with `{ success: false, message: "Validation failed", errors: {} }`

> **Note:** Soft delete, restore, and force-delete routes are **not yet implemented**. See [Deletion Policy](#deletion-policy) for the recommended future contract.

---

## Endpoint List

| Resource | Base path |
|----------|-----------|
| Employees | `/api/v1/employees` |
| Faculty members | `/api/v1/faculty-members` |
| Employee positions | `/api/v1/employee-positions` |
| Employee unit assignments | `/api/v1/employee-unit-assignments` |
| Employee types | `/api/v1/employee-types` |
| Employee statuses | `/api/v1/employee-statuses` |
| Organizational units | `/api/v1/organizational-units` |
| Organizational unit types | `/api/v1/organizational-unit-types` |
| Positions | `/api/v1/positions` |

---

## POST /api/v1/employees

**Purpose:** Create an employee record.

### Request body

```json
{
  "employee_number": "EMP-2026-001",
  "first_name": "Khalid",
  "last_name": "Omar",
  "father_name": "Hassan",
  "mother_name": "Layla",
  "phone_number": "+963912345678",
  "email": "khalid@university.edu",
  "hire_date": "2024-09-01",
  "employee_type_id": 1,
  "employee_status_id": 1,
  "organizational_unit_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `employee_number` | `required\|string\|max:50` |
| `first_name` | `required\|string\|max:100` — recommended: `required\|string\|min:2\|max:100\|regex:/^[\pL\s-'.]+$/u` |
| `last_name` | `required\|string\|max:100` — recommended name rule |
| `father_name` | `nullable\|string\|max:100` — recommended optional name rule |
| `mother_name` | `nullable\|string\|max:100` — recommended optional name rule |
| `phone_number` | `nullable\|string\|max:30` — recommended: `nullable\|string\|max:30\|regex:/^+?[0-9\s-()]+$/` |
| `email` | `nullable\|string\|max:150` — recommended: `nullable\|email\|max:255` |
| `hire_date` | `nullable\|date` |
| `employee_type_id` | `required\|integer\|exists:employee_types,employee_type_id` |
| `employee_status_id` | `required\|integer\|exists:employee_statuses,employee_status_id` |
| `organizational_unit_id` | `nullable\|integer\|exists:organizational_units,organizational_unit_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "employee_id": 1,
    "employee_number": "EMP-2026-001",
    "first_name": "Khalid",
    "last_name": "Omar",
    "employee_type_id": 1,
    "employee_status_id": 1
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "first_name": ["The first name field is required."],
    "employee_type_id": ["The employee type id field is required."]
  }
}
```

### Frontend notes

- Load `employee-types` and `employee-statuses` for form dropdowns.
- Link to `organizational-units` for department/college assignment.

---

## POST /api/v1/faculty-members

**Purpose:** Mark an employee as faculty (instructor) with academic metadata.

### Request body

```json
{
  "employee_id": 1,
  "academic_rank": "Assistant Professor",
  "specialization": "Computer Science",
  "office_location": "Building A, Room 201",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `employee_id` | `required\|integer\|exists:employees,employee_id` |
| `academic_rank` | `nullable\|string\|max:100` |
| `specialization` | `nullable\|string\|max:200` |
| `office_location` | `nullable\|string\|max:150` |
| `is_active` | `required\|integer` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "faculty_member_id": 1,
    "employee_id": 1,
    "academic_rank": "Assistant Professor",
    "is_active": 1
  }
}
```

### Frontend notes

- Faculty members are referenced by `course-offerings` and attendance sessions.
- `is_active` uses integer (0/1) in the API, not boolean.

---

## POST /api/v1/organizational-units

### Request body

```json
{
  "unit_code": "CS-DEPT",
  "unit_name": "Computer Science Department",
  "unit_type_id": 1,
  "parent_unit_id": null,
  "description": null,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `unit_code` | `nullable\|string\|max:50` — recommended code rule when provided |
| `unit_name` | `required\|string\|max:200` |
| `unit_type_id` | `required\|integer\|exists:organizational_unit_types,unit_type_id` |
| `parent_unit_id` | `nullable\|integer\|exists:organizational_units,organizational_unit_id` |
| `description` | `nullable\|string` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/employee-types

### Request body

```json
{
  "type_code": "FACULTY",
  "type_name": "Faculty",
  "description": "Academic staff",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `type_code` | `required\|string\|max:50` — recommended: `required\|string\|max:50\|regex:/^[A-Z0-9_-]+$/` |
| `type_name` | `required\|string\|max:100` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/employee-statuses

### Request body

```json
{
  "status_code": "ACTIVE",
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

## POST /api/v1/positions

### Request body

```json
{
  "position_code": "DEAN",
  "position_title": "Dean",
  "description": null,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `position_code` | `required\|string\|max:50` |
| `position_title` | `required\|string\|max:150` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/employee-positions

Links employees to positions (see store validation in backend FormRequest).

## POST /api/v1/employee-unit-assignments

Links employees to organizational units (see store validation in backend FormRequest).

### Frontend notes (general)

- Paginate list endpoints with `?per_page=N`.
- Updates support partial payloads (`sometimes|nullable` on all fields).
- Use `faculty-members` ID when assigning instructors to course offerings.

---

## Deletion Policy

Documents **Soft Delete** (archive) and **Permanent Delete** for employee-related resources. Applies primarily to `/api/v1/employees`; the same pattern is **recommended** for `faculty-members`, `employee-positions`, and `employee-unit-assignments`.

### Soft Delete vs Permanent Delete

| Action | Meaning | Field |
|--------|---------|-------|
| **Soft Delete** | Archive record | Set `deleted_at` timestamp |
| **Permanent Delete** | Remove row from database | Super Admin only; blocked if related data exists |

> **Implementation status:** No `deleted_at` / `SoftDeletes` in the current backend. Existing `DELETE /api/v1/employees/{id}` (**existing**) performs **hard delete**. Proposed routes below are **recommended future endpoints**.

### Who may perform each action

| Action | Recommended role |
|--------|------------------|
| Soft Delete | HR Admin, Admin |
| Restore | HR Admin, Admin |
| Permanent Delete | **Super Admin only** |
| View deleted list | HR Admin, Admin, Super Admin |

### Business rules before deletion

**Permanent delete blocked when employee has:**

- Faculty member record linked to course offerings or attendance sessions
- Active employee position or unit assignments
- Linked user account (`users.employee_id`)
- Library borrowings (active or historical)
- Board member records
- Course instructor assignments

**Recommended:** Soft delete when employee leaves the university; preserve history for audits and past course assignments.

### Proposed endpoints (employees)

| Method | URL | Status |
|--------|-----|--------|
| DELETE | `/api/v1/employees/{employee_id}` | **Existing** — recommend changing to soft delete |
| GET | `/api/v1/employees/deleted` | **Proposed endpoint** |
| POST | `/api/v1/employees/{employee_id}/restore` | **Proposed endpoint** |
| DELETE | `/api/v1/employees/{employee_id}/force` | **Proposed endpoint** |

**Optional request body (soft delete / force delete):**

```json
{
  "delete_reason": "Employee resigned",
  "deleted_by_user_id": 1
}
```

**Validation rules:**

| Field | Rules |
|-------|-------|
| `employee_id` (URL) | `required\|integer\|exists:employees,employee_id` |
| `delete_reason` | `nullable\|string\|max:1000` |
| `deleted_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Standard responses

**Soft delete success:**

```json
{
  "success": true,
  "message": "Record archived successfully.",
  "data": null
}
```

**Restore success:**

```json
{
  "success": true,
  "message": "Record restored successfully.",
  "data": {
    "employee_id": 1,
    "employee_number": "EMP-2026-001",
    "deleted_at": null
  }
}
```

**Permanent delete success:**

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
      "faculty_members",
      "course_offerings",
      "user_accounts",
      "library_borrowings"
    ]
  }
}
```

### Frontend behavior

- Show active employees by default; hide archived from normal HR lists.
- Use **Archive** label instead of **Delete** for employees.
- Provide **Deleted Employees** admin view (proposed `GET /employees/deleted`).
- **Restore** from archived view.
- **Permanent Delete** — Super Admin only, confirmation modal, warn that action is irreversible.
- Disable permanent delete when related records exist; display blocking reasons from `errors.related_records`.
- Prefer deactivating `employee_status_id` or soft delete over permanent delete for staff with employment history.
