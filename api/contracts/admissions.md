# Admissions API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages the admissions pipeline: applicants (prospective students), admission applications linked to programs and academic years, and document type definitions used for applicant/student document requirements.

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
| DELETE | `/api/v1/{resource}/{id}` | Delete (**existing** — currently hard delete) |

> **Note:** Soft delete, restore, and force-delete routes are **not yet implemented**. See [Deletion Policy](#deletion-policy) for the recommended future contract.

---

## Endpoint List

| Resource | Base path |
|----------|-----------|
| Applicants | `/api/v1/applicants` |
| Admission applications | `/api/v1/admission-applications` |
| Document types | `/api/v1/document-types` |

### Related student endpoint

When an applicant is accepted, student creation may reference the application:

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/v1/students` | Create student (optional `admission_application_id`) |

---

## POST /api/v1/applicants

**Purpose:** Register a new applicant before formal admission decision.

### Request body

```json
{
  "applicant_number": "APP-2026-001",
  "first_name": "Layla",
  "last_name": "Hassan",
  "father_name": "Omar",
  "mother_name": "Fatima",
  "date_of_birth": "2006-03-15",
  "gender": "female",
  "phone_number": "+963933333333",
  "email": "layla@example.com",
  "address": "Damascus",
  "nationality": "Syrian"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `applicant_number` | `required\|string\|max:50\|unique:applicants,applicant_number` |
| `first_name` | `required\|string\|max:100` — recommended name rule |
| `last_name` | `required\|string\|max:100` — recommended name rule |
| `father_name` | `nullable\|string\|max:100` |
| `mother_name` | `nullable\|string\|max:100` |
| `date_of_birth` | `nullable\|date` |
| `gender` | `nullable\|string\|max:20` |
| `phone_number` | `nullable\|string\|max:30` |
| `email` | `nullable\|email\|max:150` |
| `address` | `nullable\|string\|max:255` |
| `nationality` | `nullable\|string\|max:100` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "applicant_id": 1,
    "applicant_number": "APP-2026-001",
    "first_name": "Layla",
    "last_name": "Hassan",
    "email": "layla@example.com"
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "applicant_number": ["The applicant number has already been taken."]
  }
}
```

### Frontend notes

- Validate names (no digits) and email format before submit.
- `applicant_number` must be unique.

---

## POST /api/v1/admission-applications

**Purpose:** Submit or record an admission application for an applicant to a specific program and year.

### Request body

```json
{
  "applicant_id": 1,
  "academic_program_id": 1,
  "academic_year_id": 1,
  "application_date": "2026-05-01",
  "decision_status": "pending",
  "decision_date": null,
  "decided_by_user_id": null,
  "notes": "Submitted online"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `applicant_id` | `required\|integer\|exists:applicants,applicant_id` |
| `academic_program_id` | `required\|integer\|exists:academic_programs,academic_program_id` |
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `application_date` | `required\|date` |
| `decision_status` | `required\|string\|max:50` |
| `decision_date` | `nullable\|date` |
| `decided_by_user_id` | `nullable\|integer\|exists:users,user_id` |
| `notes` | `nullable\|string` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "admission_application_id": 1,
    "applicant_id": 1,
    "academic_program_id": 1,
    "academic_year_id": 1,
    "application_date": "2026-05-01",
    "decision_status": "pending"
  }
}
```

### Frontend notes

- Load lookups: `academic-programs`, `academic-years`, `applicants`.
- Common `decision_status` values: `pending`, `accepted`, `rejected`, `waitlisted` (confirm with seeded data).
- On acceptance, create student with `admission_application_id` linking back to this record.
- Update decision via PUT with `decision_date` and `decided_by_user_id`.

---

## POST /api/v1/document-types

**Purpose:** Define document types required for admission or student records.

### Request body

```json
{
  "type_code": "NATIONAL_ID",
  "type_name": "National ID",
  "is_required": true,
  "description": "Government-issued identification",
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `type_code` | `required\|string\|max:50\|unique:document_types,type_code` |
| `type_name` | `required\|string\|max:150` |
| `is_required` | `required\|boolean` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|boolean` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "document_type_id": 1,
    "type_code": "NATIONAL_ID",
    "type_name": "National ID",
    "is_required": true,
    "is_active": true
  }
}
```

### Frontend notes

- Use document types when building applicant document upload checklists.
- Student documents (`POST /api/v1/student-documents`) reference `document_type_id`.

---

## GET /api/v1/admission-applications (list)

**Query:** `?per_page=15`

Filter/sort in UI client-side or extend with future query params.

### Success response (200)

Paginated admission applications with nested applicant/program/year when loaded.

### Frontend notes

- Admissions dashboard: list applications → filter by `decision_status` → open detail → update decision → enroll accepted applicant as student.

---

## Deletion Policy

Documents **Soft Delete** and **Permanent Delete** for applicants, admission applications, and document types.

### Soft Delete vs Permanent Delete

| Action | Meaning |
|--------|---------|
| **Soft Delete** | Archive applicant or application — retain admissions audit trail |
| **Permanent Delete** | Remove record — **Super Admin only**; blocked when linked to enrolled student |

> **Implementation status:** Existing `DELETE` on `applicants`, `admission-applications`, `document-types` (**existing**, hard delete). Proposed archive/restore/force routes are **recommended future endpoints**.

### Who may perform each action

| Action | Recommended role |
|--------|------------------|
| Soft Delete | Admissions Officer, Admin |
| Restore | Admissions Officer, Admin |
| Permanent Delete | **Super Admin only** |

### Business rules before deletion

**Applicants — permanent delete blocked when:**

- One or more `admission-applications` exist
- A `students` record references `admission_application_id` from this applicant's accepted application

**Admission applications — permanent delete blocked when:**

- Application was accepted and a student record was created
- Application has linked documents or decision audit data (recommended archive only)

**Document types — permanent delete blocked when:**

- Referenced by `student-documents` or applicant uploads
- Prefer `is_active = false` instead of delete

### Proposed endpoints

**Applicants:**

| Method | URL | Status |
|--------|-----|--------|
| DELETE | `/api/v1/applicants/{applicant_id}` | **Existing** — recommend soft delete |
| GET | `/api/v1/applicants/deleted` | **Proposed endpoint** |
| POST | `/api/v1/applicants/{applicant_id}/restore` | **Proposed endpoint** |
| DELETE | `/api/v1/applicants/{applicant_id}/force` | **Proposed endpoint** |

**Admission applications** — same pattern with `/admission-applications/...`.

**Optional request body:**

```json
{
  "delete_reason": "Duplicate application submitted in error",
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

**Soft delete:**

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
    "applicant_id": 1,
    "applicant_number": "APP-2026-001",
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
      "admission_applications",
      "students"
    ]
  }
}
```

### Frontend behavior

- Show active applicants/applications in default admissions lists.
- Hide archived records unless viewing **Deleted / Archived** admin screen.
- Use **Archive** instead of **Delete** for applicants and applications.
- **Restore** from archived view.
- **Permanent Delete** — Super Admin only, confirmation modal, irreversible warning.
- Never permanently delete an applicant whose application produced an enrolled student — archive only.
- For rejected/withdrawn applications, prefer soft delete over permanent delete to preserve reporting history.
