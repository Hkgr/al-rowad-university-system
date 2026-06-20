# Grades API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages course grades tied to a **student course registration** (`student_course_registration_id`). It supports viewing, creating, updating marks, and recalculating letter grades and result status. Related CRUD resources cover grading policies, grade components, appeals, approvals, and supplementary exams.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Grading Business Rules

| Rule | Detail |
|------|--------|
| Theoretical mark | Out of **60** — `numeric\|min:0\|max:60` |
| Practical mark | Out of **40** — `numeric\|min:0\|max:40` |
| Final mark | `theoretical_mark + practical_mark` (max **100**) |
| Passing (default policy) | `theoretical_mark >= 15`, `practical_mark >= 10`, `final_mark >= 50` |
| Letter grade F | Counts as **0.00** grade points |
| W, Z, I | Excluded from GPA/CGPA (0.00 points, not in weighted average) |
| Deprived (Z) | Absence > 15%; cannot recalculate automatically |
| Dropped/withdrawn | Grading blocked; excluded from GPA |

### Letter grade scale (when passed)

A+ (≥98), A (≥95), A- (≥90), B+ (≥85), B (≥80), B- (≥75), C+ (≥70), C (≥65), C- (≥60), D+ (≥55), D (≥50), F (<50 or component fail)

### GPA / CGPA

- **GPA:** Credit-hour weighted average for a specific term.
- **CGPA:** Best attempt per course across all terms.
- Excludes: dropped/withdrawn registrations; incomplete, deprived, withdrawn results.

---

## Endpoint List

### Registration grade operations

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/registrations/{id}/grades` | Get grades for registration |
| POST | `/api/v1/registrations/{id}/grades` | Enter grades (first time) |
| PUT | `/api/v1/registrations/{id}/grades` | Update existing grades |
| POST | `/api/v1/registrations/{id}/calculate-result` | Recalculate letter grade & status |

### Offering-level grade views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/course-offerings/{id}/grade-sheet` | Full grade sheet |
| GET | `/api/v1/course-offerings/{id}/results-summary` | Pass/fail statistics |

### Student grade views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/{student}/transcript` | Transcript |
| GET | `/api/v1/students/{student}/gpa` | Term GPA |
| GET | `/api/v1/students/{student}/cgpa` | CGPA |

### CRUD resources

| Resource | Base path |
|----------|-----------|
| Grading policies | `/api/v1/grading-policies` |
| Grade components | `/api/v1/grade-components` |
| Student grade components | `/api/v1/student-grade-components` |
| Student course results | `/api/v1/student-course-results` |
| Grade appeals | `/api/v1/grade-appeals` |
| Grade approvals | `/api/v1/grade-approvals` |
| Grade audit logs | `/api/v1/grade-audit-logs` |
| Supplementary exam periods | `/api/v1/supplementary-exam-periods` |
| Supplementary exam results | `/api/v1/supplementary-exam-results` |

Each CRUD resource supports GET (list), POST, GET/{id}, PUT/PATCH/{id}, DELETE/{id}.

---

## GET /api/v1/registrations/{id}/grades

**Purpose:** Retrieve marks, letter grade, and result status for one registration.

**URL parameter:** `{id}` = `student_course_registration_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "registration": {
      "student_course_registration_id": 10,
      "registration_date": "2026-09-01",
      "registration_status": { "status_code": "registered", "status_name": "Registered" }
    },
    "student": {
      "student_id": 1,
      "student_number": "2026-0001",
      "full_name": "Ahmad Ali"
    },
    "course": {
      "course_id": 1,
      "course_code": "CS101",
      "course_name": "Intro to Programming",
      "credit_hours": 3
    },
    "theoretical_mark": 45.0,
    "practical_mark": 32.0,
    "final_mark": 77.0,
    "letter_grade": "B",
    "grade_points": 3.0,
    "result_status": { "status_code": "passed", "status_name": "Passed" },
    "notes": null
  }
}
```

---

## POST /api/v1/registrations/{id}/grades

**Purpose:** Enter grades for the first time.

### Request body

```json
{
  "theoretical_mark": 45,
  "practical_mark": 32,
  "notes": "Midterm included"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `theoretical_mark` | `required\|numeric\|min:0\|max:60` |
| `practical_mark` | `required\|numeric\|min:0\|max:40` |
| `notes` | `nullable\|string` |

### Success response (201)

Same shape as GET grades response with calculated `final_mark`, `letter_grade`, `grade_points`, `result_status`.

### Error response (422)

```json
{
  "success": false,
  "message": "Grades already exist for this registration. Use update endpoint instead.",
  "errors": {}
}
```

Other errors: registration dropped/withdrawn; no marks when deprived.

### Frontend notes

- Validate marks client-side against 0–60 and 0–40 before submit.
- Show live preview: `final_mark = theoretical + practical`.
- Highlight component failures (< 15 theoretical or < 10 practical) even if final ≥ 50.

---

## PUT /api/v1/registrations/{id}/grades

**Purpose:** Update marks on an existing result.

### Request body

Same as POST.

### Validation rules

Same as POST.

### Success response (200)

Updated grade object (same shape as GET).

### Error response (422)

```json
{
  "success": false,
  "message": "No grades found for this registration. Use create endpoint first.",
  "errors": {}
}
```

---

## POST /api/v1/registrations/{id}/calculate-result

**Purpose:** Recalculate letter grade, grade points, and result status from stored marks using the active grading policy.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Result calculated successfully",
  "data": {
    "registration_id": 10,
    "theoretical_mark": 45.0,
    "practical_mark": 32.0,
    "final_mark": 77.0,
    "letter_grade": "B",
    "grade_points": 3.0,
    "result_status": { "status_code": "passed", "status_name": "Passed" },
    "calculation_details": {
      "minimum_theoretical_mark": 15,
      "minimum_practical_mark": 10,
      "minimum_final_mark": 50,
      "theoretical_passed": true,
      "practical_passed": true,
      "final_passed": true
    }
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Deprived results cannot be recalculated automatically.",
  "errors": {}
}
```

### Frontend notes

- Call after manual mark edits if UI needs refreshed letter grade without re-PUTting marks.
- Deprived (Z) results require administrative handling, not recalculation.

---

## GET /api/v1/course-offerings/{id}/grade-sheet

**Query:** `include_inactive` (boolean, default false)

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "course_code": "CS101",
    "course_name": "Intro to Programming",
    "students": [
      {
        "student_course_registration_id": 10,
        "student_number": "2026-0001",
        "full_name": "Ahmad Ali",
        "theoretical_mark": 45.0,
        "practical_mark": 32.0,
        "final_mark": 77.0,
        "letter_grade": "B",
        "grade_points": 3.0,
        "result_status": { "status_code": "passed" }
      }
    ]
  }
}
```

---

## GET /api/v1/course-offerings/{id}/results-summary

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "total_registered_students": 30,
    "total_students_with_results": 28,
    "passed_count": 22,
    "failed_count": 4,
    "incomplete_count": 1,
    "deprived_count": 1,
    "withdrawn_count": 0,
    "average_final_mark": 68.5,
    "pass_rate": 78.57
  }
}
```

---

## Standard validation error (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "theoretical_mark": ["The theoretical mark must not be greater than 60."],
    "practical_mark": ["The practical mark field is required."]
  }
}
```

### Frontend notes

- Grade entry UI should key off `student_course_registration_id` from the grade sheet.
- Display W/Z/I courses distinctly in transcript and exclude from GPA displays.
- F shows 0.00 points but remains in GPA denominator.
