# Students API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages student records and student-centric read APIs: CRUD for students and related sub-resources (documents, academic terms, credit limits, etc.), plus dashboard endpoints for profile, transcript, GPA/CGPA, registrations, attendance, and registration planning.

## Authentication Requirements

All endpoints require:

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

**Validation error (422):**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

**Not found (404):**

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

---

## Endpoint List

### Student CRUD and sub-resources

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students` | List students (paginated) |
| POST | `/api/v1/students` | Create student |
| GET | `/api/v1/students/{id}` | Show student |
| PUT/PATCH | `/api/v1/students/{id}` | Update student |
| DELETE | `/api/v1/students/{id}` | Delete student |
| GET | `/api/v1/student-academic-terms` | CRUD — academic term records |
| GET | `/api/v1/student-documents` | CRUD — uploaded documents |
| GET | `/api/v1/student-credit-limits` | CRUD — per-term credit limits |
| GET | `/api/v1/student-course-registrations` | CRUD — raw registration records |
| GET | `/api/v1/student-course-results` | CRUD — course result records |
| GET | `/api/v1/student-attendance` | CRUD — individual attendance rows |
| GET | `/api/v1/student-grade-components` | CRUD — grade component marks |
| GET | `/api/v1/student-statuses` | CRUD — student status lookup |

Each sub-resource above also supports POST, GET/{id}, PUT/PATCH/{id}, DELETE/{id} on its base path.

### Student profile and dashboard

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/search` | Search students |
| GET | `/api/v1/students/{student}/profile` | Full profile |
| GET | `/api/v1/students/{student}/academic-info` | Academic summary |
| GET | `/api/v1/students/{student}/documents` | Student documents |
| GET | `/api/v1/students/{student}/registrations` | Course registrations |
| GET | `/api/v1/students/{student}/transcript` | Academic transcript |
| GET | `/api/v1/students/{student}/gpa` | Term GPA |
| GET | `/api/v1/students/{student}/cgpa` | Cumulative GPA |
| GET | `/api/v1/students/{student}/attendance` | Attendance summary |
| GET | `/api/v1/students/{student}/absence-percentage` | Absence % for one offering |
| GET | `/api/v1/students/{student}/available-courses` | Eligible open offerings |
| GET | `/api/v1/students/{student}/registered-hours` | Credit hours snapshot |
| GET | `/api/v1/students/{student}/registration-summary` | Registration overview |

---

## POST /api/v1/students

**Purpose:** Create a new student record.

### Request body

```json
{
  "student_number": "2026-0001",
  "admission_application_id": null,
  "first_name": "Ahmad",
  "last_name": "Ali",
  "father_name": "Mohammad",
  "mother_name": "Fatima",
  "date_of_birth": "2005-05-10",
  "gender": "male",
  "phone_number": "+963999999999",
  "email": "student@example.com",
  "address": "Aleppo",
  "nationality": "Syrian",
  "academic_program_id": 1,
  "current_academic_level_id": 1,
  "enrollment_date": "2026-09-01",
  "student_status_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_number` | `required\|string\|max:50\|unique:students,student_number` |
| `admission_application_id` | `nullable\|integer\|exists:admission_applications,admission_application_id` |
| `first_name` | `required\|string\|max:100` — recommended: `required\|string\|min:2\|max:100\|regex:/^[\pL\s-'.]+$/u` |
| `last_name` | `required\|string\|max:100` — recommended name rule |
| `father_name` | `nullable\|string\|max:100` — recommended optional name rule |
| `mother_name` | `nullable\|string\|max:100` — recommended optional name rule |
| `date_of_birth` | `nullable\|date` |
| `gender` | `nullable\|string\|max:20` |
| `phone_number` | `nullable\|string\|max:30` — recommended: `nullable\|string\|max:30\|regex:/^+?[0-9\s-()]+$/` |
| `email` | `nullable\|email\|max:150\|unique:students,email` |
| `address` | `nullable\|string\|max:255` |
| `nationality` | `nullable\|string\|max:100` |
| `academic_program_id` | `required\|integer\|exists:academic_programs,academic_program_id` |
| `current_academic_level_id` | `required\|integer\|exists:academic_levels,academic_level_id` |
| `enrollment_date` | `required\|date` |
| `student_status_id` | `required\|integer\|exists:student_statuses,student_status_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "student_id": 1,
    "student_number": "2026-0001",
    "first_name": "Ahmad",
    "last_name": "Ali",
    "academic_program_id": 1,
    "current_academic_level_id": 1,
    "enrollment_date": "2026-09-01",
    "student_status_id": 1
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "student_number": ["The student number field is required."],
    "first_name": ["The first name field is required."]
  }
}
```

### Frontend notes

- Load lookups before submit: `GET /api/v1/academic-programs`, `GET /api/v1/academic-levels`, `GET /api/v1/student-statuses`.
- Validate names client-side (no digits; Arabic/English allowed) before sending.
- `student_number` must be unique; show server error if duplicate.

---

## GET /api/v1/students/search

**Purpose:** Search students by number, name, email, or phone.

### Query parameters

| Param | Rules |
|-------|-------|
| `q` | `required\|string\|min:1\|max:150` |
| `per_page` | optional integer 1–100, default 15 |

### Success response (200)

Paginated `StudentResource` list in `data`.

### Frontend notes

- Debounce search input; require at least 1 character.
- Use for student picker in registration and admin screens.

---

## GET /api/v1/students/{student}/profile

**Purpose:** Student profile with program, college, level, and status.

**Request body:** None

**Success response (200):** `StudentProfileResource` with nested relations.

---

## GET /api/v1/students/{student}/transcript

**Purpose:** Full transcript grouped by academic year and semester.

**Success response (200):**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "student_id": 1,
    "student_number": "2026-0001",
    "full_name": "Ahmad Ali",
    "terms": [
      {
        "academic_year": { "academic_year_id": 1, "year_name": "2025-2026" },
        "semester": { "semester_id": 1, "semester_name": "Fall" },
        "courses": [
          {
            "course_code": "CS101",
            "course_name": "Intro to Programming",
            "credit_hours": 3,
            "theoretical_mark": 45.0,
            "practical_mark": 30.0,
            "final_mark": 75.0,
            "letter_grade": "B",
            "grade_points": 3.0,
            "result_status": { "status_code": "passed", "status_name": "Passed" }
          }
        ]
      }
    ]
  }
}
```

---

## GET /api/v1/students/{student}/gpa

**Purpose:** Calculate term GPA (credit-hour weighted).

### Query parameters

| Param | Rules |
|-------|-------|
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `required\|integer\|exists:semesters,semester_id` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "student_id": 1,
    "academic_year_id": 1,
    "semester_id": 1,
    "gpa": 3.25,
    "total_credit_hours": 15,
    "included_courses": [],
    "excluded_courses": []
  }
}
```

### Business rules

- GPA uses credit-hour weighted grade points.
- **Excluded from GPA:** dropped/withdrawn registrations; results with status incomplete, deprived, or withdrawn; letter grades **W**, **Z**, **I**.
- **F** counts as **0.00** grade points.

---

## GET /api/v1/students/{student}/cgpa

**Purpose:** Cumulative GPA using best attempt per course.

**Request body:** None

**Success response (200):** Same structure as GPA with cumulative totals; uses highest grade per course.

---

## GET /api/v1/students/{student}/available-courses

**Purpose:** Open course offerings with eligibility flags for registration UI.

### Query parameters (optional)

| Param | Rules |
|-------|-------|
| `academic_year_id` | `integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `integer\|exists:semesters,semester_id` |

### Success response (200)

Array of offerings with `eligibility_status` and `eligibility_reasons` (e.g. prerequisites missing, credit limit, already registered).

### Frontend notes

- Prefer this over raw open offerings when building a student registration screen.
- Display `eligibility_reasons` when status is not eligible.

---

## GET /api/v1/students/{student}/registered-hours

### Query parameters

| Param | Rules |
|-------|-------|
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `required\|integer\|exists:semesters,semester_id` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "registered_hours": 12,
    "max_allowed_hours": 18,
    "remaining_hours": 6
  }
}
```

---

## GET /api/v1/students/{student}/registration-summary

**Purpose:** Overview of current-term registrations and hour totals.

**Query:** optional `academic_year_id`, `semester_id`

---

## GET /api/v1/students/{student}/attendance

**Purpose:** Per-course attendance statistics for a student.

**Query (all optional):** `academic_year_id`, `semester_id`, `course_offering_id`

---

## GET /api/v1/students/{student}/absence-percentage

### Query parameters

| Param | Rules |
|-------|-------|
| `course_offering_id` | `required\|integer\|exists:course_offerings,course_offering_id` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "absence_percentage": 18.5,
    "deprivation_threshold": 15,
    "is_deprived_candidate": true,
    "total_sessions": 20,
    "present_count": 16,
    "absent_count": 4
  }
}
```

### Frontend notes

- Show warning when `absence_percentage > 15` (deprivation threshold).
- Deprived courses receive grade **Z** and are excluded from GPA/CGPA.

---

## POST /api/v1/student-documents

### Request body

```json
{
  "student_id": 1,
  "document_type_id": 1,
  "file_name": "national_id.pdf",
  "file_url": "https://storage.example.com/docs/national_id.pdf",
  "verification_status": "pending"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `document_type_id` | `required\|integer\|exists:document_types,document_type_id` |
| `file_name` | `required\|string\|max:255` |
| `file_url` | `required\|string\|max:500` |
| `verification_status` | `nullable\|string\|max:50` |

---

## POST /api/v1/student-credit-limits

### Request body

```json
{
  "student_id": 1,
  "academic_year_id": 1,
  "semester_id": 1,
  "min_credit_hours": 12,
  "max_credit_hours": 21,
  "is_excellent_student": 0
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `required\|integer\|exists:semesters,semester_id` |
| `min_credit_hours` | `required\|integer` |
| `max_credit_hours` | `required\|integer` |
| `is_excellent_student` | `required\|integer` |

**Frontend notes:** Default max credit hours is 18 when no custom limit exists (registration service).
