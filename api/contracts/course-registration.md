# Course Registration API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module handles enrolling an existing student in a course offering (section), dropping a registration, and withdrawing from a course. The primary business endpoint is `POST /registrations/register-student`, which enforces seat availability, offering status, prerequisites, credit limits, and duplicate prevention. **Do not delete registrations** — use drop or withdraw instead.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Business Rules

1. **Duplicate prevention:** A student cannot register twice in the same course offering.
2. **Offering must be open:** `course_offerings.status` must be `"open"`.
3. **Seat check:** `available_seats` must be greater than 0; seats decrement on successful registration and restore on drop/withdraw (when previously registered).
4. **Credit hour limits:** Registered hours + course credit hours must not exceed the student's max allowed hours for the term (default **18** unless a `student-credit-limits` record overrides).
5. **Prerequisites:** If configured, missing prerequisites block registration with a descriptive error.
6. **Drop vs withdraw:** Use dedicated endpoints; do not DELETE registration records for normal workflow.
7. **Registered by:** If no authenticated user, `registered_by_user_id` must be supplied in the request body.

## Standard Error Envelope

Domain errors return HTTP **422**:

```json
{
  "success": false,
  "message": "Student is already registered in this course offering.",
  "errors": {
    "course_offering_id": ["Student is already registered in this course offering."]
  }
}
```

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/v1/registrations/register-student` | Register student in offering |
| POST | `/api/v1/registrations/{id}/drop` | Drop registration |
| POST | `/api/v1/registrations/{id}/withdraw` | Withdraw from course |
| GET | `/api/v1/student-course-registrations` | CRUD — raw registration records (admin) |

### Supporting read endpoints (Students / Academic Structure modules)

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/search` | Find student |
| GET | `/api/v1/course-offerings/open` | List open sections |
| GET | `/api/v1/students/{student}/available-courses` | Eligible offerings |
| GET | `/api/v1/students/{student}/registration-summary` | Term summary |
| GET | `/api/v1/students/{student}/registered-hours` | Hour limits |
| GET | `/api/v1/course-offerings/{id}/capacity` | Seat availability |

---

## POST /api/v1/registrations/register-student

**Purpose:** Register an existing student in a course offering (section).

### Request body

```json
{
  "student_id": 1,
  "course_offering_id": 5,
  "registered_by_user_id": 1,
  "advisor_user_id": null,
  "registration_date": "2026-09-01"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `course_offering_id` | `required\|integer\|exists:course_offerings,course_offering_id` |
| `registered_by_user_id` | `nullable\|integer\|exists:users,user_id` |
| `advisor_user_id` | `nullable\|integer\|exists:users,user_id` |
| `registration_date` | `nullable\|date` |

### Success response (201)

```json
{
  "success": true,
  "message": "Student registered successfully",
  "data": {
    "registration": {
      "student_course_registration_id": 10,
      "student_id": 1,
      "course_offering_id": 5,
      "registration_date": "2026-09-01",
      "registration_status": { "status_code": "registered", "status_name": "Registered" }
    },
    "student": { "student_id": 1, "student_number": "2026-0001", "first_name": "Ahmad", "last_name": "Ali" },
    "course_offering": { "course_offering_id": 5, "status": "open", "available_seats": 11 },
    "course": { "course_id": 1, "course_code": "CS101", "course_name": "Intro to Programming", "credit_hours": 3 },
    "registered_hours": 15,
    "max_allowed_hours": 18,
    "remaining_hours": 3,
    "available_seats": 11
  }
}
```

### Error response (422 — business rule)

```json
{
  "success": false,
  "message": "No available seats remain for the selected course offering.",
  "errors": {
    "course_offering_id": ["No available seats remain for the selected course offering."]
  }
}
```

### Other possible business errors (422)

| Message | Cause |
|---------|-------|
| Student is already registered in this course offering. | Duplicate registration |
| The selected course offering is not open for registration. | Status ≠ open |
| Credit hour limit exceeded for this academic term. | Hour cap exceeded |
| Student has missing prerequisites: … | Prerequisites not satisfied |
| registered_by_user_id is required when no authenticated user is available. | Missing registrar |

### Frontend notes

- Pre-validate with `available-courses` and `registered-hours` before submit.
- Show `remaining_hours` and `available_seats` after success.
- On duplicate or full section, display server `message` on the offering row.
- Default `registration_date` to today if omitted (server uses current date).

---

## POST /api/v1/registrations/{id}/drop

**Purpose:** Mark registration as dropped and restore a seat if status was `registered`.

**URL parameter:** `{id}` = `student_course_registration_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Registration dropped successfully",
  "data": {
    "student_course_registration_id": 10,
    "student_id": 1,
    "course_offering_id": 5,
    "registration_status": { "status_code": "dropped", "status_name": "Dropped" }
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Registration is already dropped.",
  "errors": {}
}
```

### Frontend notes

- Confirm with user before drop; dropped courses are excluded from GPA.
- Use registration ID from list or register response, not student/offering IDs.

---

## POST /api/v1/registrations/{id}/withdraw

**Purpose:** Mark registration as withdrawn and restore a seat if applicable.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Registration withdrawn successfully",
  "data": {
    "student_course_registration_id": 10,
    "registration_status": { "status_code": "withdrawn", "status_name": "Withdrawn" }
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Registration is already withdrawn or dropped.",
  "errors": {}
}
```

### Frontend notes

- Withdrawn courses receive grade **W** and are excluded from GPA/CGPA.
- Do not call DELETE on `student-course-registrations` for normal student workflow.

---

## POST /api/v1/student-course-registrations (admin CRUD)

**Purpose:** Direct database-style create (bypasses business rules). Prefer `register-student` for frontend registration flows.

### Request body

```json
{
  "student_id": 1,
  "course_offering_id": 5,
  "registration_date": "2026-09-01",
  "registered_by_user_id": 1,
  "advisor_user_id": null,
  "registration_status_id": null,
  "result_status_id": null,
  "notes": null
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `course_offering_id` | `required\|integer\|exists:course_offerings,course_offering_id` |
| `registration_date` | `nullable\|date` |
| `registered_by_user_id` | `required\|integer\|exists:users,user_id` |
| `advisor_user_id` | `nullable\|integer\|exists:users,user_id` |
| `registration_status_id` | `nullable\|integer\|exists:registration_statuses,registration_status_id` |
| `result_status_id` | `nullable\|integer\|exists:result_statuses,result_status_id` |
| `notes` | `nullable\|string\|max:255` |

**Frontend notes:** Use only for admin/back-office tools, not student self-registration.
