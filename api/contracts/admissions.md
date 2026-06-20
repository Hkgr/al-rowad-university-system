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
| DELETE | `/api/v1/{resource}/{id}` | Delete |

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
