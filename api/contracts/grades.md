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
| POST | `/api/v1/course-offerings/{id}/announce-results` | Officially announce results (sets `result_announced_at`) |

### Student grade views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/{student}/transcript` | Transcript |
| GET | `/api/v1/students/{student}/gpa` | Term GPA |
| GET | `/api/v1/students/{student}/cgpa` | CGPA |
| GET | `/api/v1/students/{student}/grade-appeals` | Student's own grade appeals |

### Grade appeals

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/grade-appeals` | List appeals (paginated) |
| POST | `/api/v1/grade-appeals` | Submit a grade appeal (student) |
| GET | `/api/v1/grade-appeals/{id}` | Show one appeal |
| POST | `/api/v1/grade-appeals/{id}/decide` | Review/decide an appeal (examinations staff) |

### CRUD resources

| Resource | Base path |
|----------|-----------|
| Grading policies | `/api/v1/grading-policies` |
| Grade components | `/api/v1/grade-components` |
| Student course results (read-only) | `/api/v1/student-course-results` |
| Grade appeals (index/show/store only) | `/api/v1/grade-appeals` |
| Grade approvals | `/api/v1/grade-approvals` |
| Grade audit logs | `/api/v1/grade-audit-logs` |
| Supplementary exam periods | `/api/v1/supplementary-exam-periods` |
| Supplementary exam results | `/api/v1/supplementary-exam-results` |

Raw student results support only GET list/detail. Raw grade-component routes are not exposed. Grade writes must use `/registrations/{id}/grades`, which enforces role, section ownership, and registration eligibility. Grade appeal status/decision changes must use `POST /grade-appeals/{id}/decide` (requires `exams.manage` permission); raw PUT/PATCH/DELETE on `/grade-appeals` is not exposed.

---

## POST /api/v1/course-offerings/{id}/announce-results

**Purpose:** Officially announce course results for a course offering. Sets `result_announced_at = now()` on every `student_course_results` row linked to registrations under this offering where `result_announced_at` is currently `NULL`. Idempotent — calling again does not overwrite existing announcement dates.

**Authorization:** Examination Committee (`exams.manage` permission and `exam_officer` or `super_admin` role), enforced in controller via `AcademicAuthorizationService::assertExaminationCommittee()`.

**URL parameter:** `{id}` = `course_offering_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "newly_announced_count": 28
  }
}
```

### Error response (403)

```json
{
  "success": false,
  "message": "This operation requires Examination Committee permission.",
  "error_code": "forbidden",
  "errors": []
}
```

### Frontend notes

- Call once per offering when results are ready for student viewing and appeals.
- `newly_announced_count` is `0` if all results were already announced.
- Students cannot submit appeals until their registration's result has a non-null `result_announced_at`.

---

## POST /api/v1/grade-appeals

**Purpose:** Submit exactly one grade appeal per course registration, within 7 days of the official result announcement.

**Authorization:** Authenticated user must have a linked `student_id` on their user account. The registration must belong to that student.

**Request body:**

```json
{
  "student_course_registration_id": 10,
  "appeal_reason": "I believe my practical exam mark was recorded incorrectly."
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_course_registration_id` | `required\|integer\|exists:student_course_registrations,student_course_registration_id` |
| `appeal_reason` | `required\|string` |

The following fields are **not accepted** from the client: `student_id`, `appeal_status_id`, `submitted_at`, `reviewed_by_user_id`, `review_notes`, `decision_date`, `created_at`, `updated_at`. The server sets `student_id` from the authenticated user, `appeal_status_id` to `submitted`, and `submitted_at` to `now()`.

### Business rules (enforced after validation, in order)

| Check | HTTP | Message |
|-------|------|---------|
| User has no linked `student_id`, or registration does not belong to that student | 403 | `You are not authorized to submit an appeal for this registration.` (or generic 403 if no linked student — checked in `authorize()` before validation) |
| No result row, or `result_announced_at` is null | 422 | `لم يتم إعلان النتيجة رسمياً بعد لهذا المقرر.` |
| Current time is more than 7 days after `result_announced_at` | 422 | `انتهت مهلة تقديم الاعتراض (أسبوع واحد من تاريخ إعلان النتيجة).` |
| An appeal already exists for this `student_course_registration_id` (any status) | 422 | `تم تقديم اعتراض على هذا المقرر مسبقاً.` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "grade_appeal_id": 1,
    "student_id": 3,
    "student_course_registration_id": 10,
    "appeal_reason": "I believe my practical exam mark was recorded incorrectly.",
    "appeal_status_id": 1,
    "submitted_at": "2026-08-22T10:00:00.000000Z",
    "reviewed_by_user_id": null,
    "review_notes": null,
    "decision_date": null,
    "created_at": "2026-08-22T10:00:00.000000Z",
    "updated_at": "2026-08-22T10:00:00.000000Z"
  }
}
```

### Frontend notes

- Only student accounts (users with linked `student_id`) can submit.
- One appeal per course registration, ever — even if a previous appeal was rejected.
- Appeal window starts when examinations staff call `announce-results` for the offering.

---

## POST /api/v1/grade-appeals/{id}/decide

**Purpose:** Update appeal status and record an examinations review decision.

**Authorization:** Requires `exams.manage` permission (route middleware).

**URL parameter:** `{id}` = `grade_appeal_id`

**Request body:**

```json
{
  "status_code": "accepted",
  "notes": "Mark corrected after re-checking the answer sheet."
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|in:under_review,accepted,rejected,closed` |
| `notes` | `nullable\|string` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "grade_appeal_id": 1,
    "student_id": 3,
    "student_course_registration_id": 10,
    "appeal_reason": "I believe my practical exam mark was recorded incorrectly.",
    "appeal_status_id": 3,
    "submitted_at": "2026-08-22T10:00:00.000000Z",
    "reviewed_by_user_id": 2,
    "review_notes": "Mark corrected after re-checking the answer sheet.",
    "decision_date": "2026-08-25T00:00:00.000000Z",
    "created_at": "2026-08-22T10:00:00.000000Z",
    "updated_at": "2026-08-25T14:30:00.000000Z"
  }
}
```

### Error response (403)

```json
{
  "success": false,
  "message": "You do not have permission to perform this operation.",
  "error_code": "forbidden",
  "errors": []
}
```

### Frontend notes

- Use this endpoint exclusively for status changes — PUT/PATCH on `/grade-appeals/{id}` is not available.
- `appeal_status_id` values map to `appeal_statuses.status_code`: `submitted`(1), `under_review`(2), `accepted`(3), `rejected`(4), `closed`(5).

---

## GET /api/v1/students/{student}/grade-appeals

**Purpose:** List all grade appeals for one student (student self-view or authorized staff).

**Authorization:** Student may view their own record; staff require an authorized academic role via `AcademicAuthorizationService::assertStudentRecord()`.

**URL parameter:** `{student}` = `student_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": [
    {
      "grade_appeal_id": 1,
      "student_id": 3,
      "student_course_registration_id": 10,
      "appeal_reason": "I believe my practical exam mark was recorded incorrectly.",
      "appeal_status_id": 2,
      "submitted_at": "2026-08-22T10:00:00.000000Z",
      "reviewed_by_user_id": null,
      "review_notes": null,
      "decision_date": null,
      "created_at": "2026-08-22T10:00:00.000000Z",
      "updated_at": "2026-08-22T10:00:00.000000Z"
    }
  ]
}
```

### Frontend notes

- Resolve `appeal_status_id` via `/api/v1/appeal-statuses` lookup for display labels.
- Show `review_notes` when present so the student can read the decision rationale.

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
