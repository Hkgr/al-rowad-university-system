# Disciplinary Cases API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module documents the disciplinary penalties workflow (Chapter 10, Articles 68-73): case registration, penalty assignment, course-grade impact, and appeal decisions.

This module manages student disciplinary cases and appeals: create case records, optionally apply zeroing to trigger/subsequent courses, submit appeals, and decide appeals with automatic grade restoration when accepted.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Business Rules

| Rule | Detail |
|------|--------|
| Case creation status | New cases are created with `case_status = active` |
| Investigation flag | If selected penalty type has `requires_investigation = true`, the service sets `investigation_status = pending` |
| `zero_and_subsequent` guard 1 | Requires `trigger_course_offering_id`; otherwise API returns 422 with service exception message |
| `zero_and_subsequent` guard 2 | Trigger offering must have a theoretical `grade_components.exam_date`; otherwise API returns 422 |
| Zeroing behavior | Matching same-student, same-year, same-semester offerings with theoretical exam date `>=` trigger date are zeroed and tracked in `disciplinary_case_affected_courses` |
| Appeal submit behavior | Creates appeal with `appeal_status = submitted` and sets parent case `case_status = appealed` |
| Appeal decide behavior | `accepted` automatically calls revert logic and sets case `overturned`; `rejected` sets case back to `active` |

### Penalty Type Codes (by severity order)

| severity_order | penalty_code |
|---:|---|
| 1 | `warning` |
| 2 | `deprive_services` |
| 3 | `ban_attendance_month` |
| 4 | `suspend_college_month` |
| 5 | `ban_exam_specific_courses` |
| 6 | `zero_specific_courses` |
| 6 | `zero_and_subsequent` |
| 7 | `suspend_college_semester` |
| 8 | `ban_exam_full_semester` |
| 9 | `suspend_college_over_semester` |
| 10 | `expel_university` |

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/disciplinary-cases` | List cases (paginated) |
| GET | `/api/v1/disciplinary-cases/{id}` | Show one case |
| POST | `/api/v1/disciplinary-cases` | Create a disciplinary case |
| GET | `/api/v1/students/{student}/disciplinary-cases` | List all cases for one student |
| GET | `/api/v1/disciplinary-case-appeals` | List appeals (paginated) |
| GET | `/api/v1/disciplinary-case-appeals/{id}` | Show one appeal |
| POST | `/api/v1/disciplinary-case-appeals` | Submit appeal |
| POST | `/api/v1/disciplinary-case-appeals/{id}/decide` | Decide appeal (`accepted` or `rejected`) |

---

## GET /api/v1/disciplinary-cases

**Purpose:** Retrieve paginated disciplinary cases.

**Request body:** None

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "current_page": 1,
    "data": [
      {
        "case_id": 3,
        "student_id": 15,
        "violation_type_id": 2,
        "trigger_course_offering_id": 44,
        "violation_description": "Cheating in final exam",
        "violation_date": "2026-01-12",
        "reported_by_user_id": 7,
        "investigation_status": "pending",
        "investigation_date": null,
        "investigation_notes": null,
        "decided_by_authority": "disciplinary_council",
        "decided_by_user_id": 7,
        "decision_number": "DC-2026-014",
        "decision_date": "2026-01-20",
        "penalty_type_id": 7,
        "penalty_start_date": "2026-01-21",
        "penalty_end_date": null,
        "is_in_absentia": false,
        "guardian_notified_at": null,
        "case_status": "active",
        "created_at": "2026-01-20T10:00:00.000000Z",
        "updated_at": "2026-01-20T10:00:00.000000Z"
      }
    ]
  }
}
```

### Error response example (401)

```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

### Frontend notes

- Use this for admin/disciplinary case queue pages.
- Response is paginated by default (`per_page` query is supported by shared CRUD trait).

---

## GET /api/v1/disciplinary-cases/{id}

**Purpose:** Retrieve one disciplinary case by `case_id`.

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "case_id": 3,
    "student_id": 15,
    "violation_type_id": 2,
    "trigger_course_offering_id": 44,
    "violation_description": "Cheating in final exam",
    "violation_date": "2026-01-12",
    "reported_by_user_id": 7,
    "investigation_status": "pending",
    "investigation_date": null,
    "investigation_notes": null,
    "decided_by_authority": "disciplinary_council",
    "decided_by_user_id": 7,
    "decision_number": "DC-2026-014",
    "decision_date": "2026-01-20",
    "penalty_type_id": 7,
    "penalty_start_date": "2026-01-21",
    "penalty_end_date": null,
    "is_in_absentia": false,
    "guardian_notified_at": null,
    "case_status": "active",
    "created_at": "2026-01-20T10:00:00.000000Z",
    "updated_at": "2026-01-20T10:00:00.000000Z"
  }
}
```

### Error response example (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Use this endpoint as detail source after selecting an item from the list.

---

## POST /api/v1/disciplinary-cases

**Purpose:** Create a disciplinary case. If selected penalty cascades to subsequent courses, zeroing is applied in the same transaction.

### Request body example

```json
{
  "student_id": 15,
  "violation_type_id": 2,
  "trigger_course_offering_id": 44,
  "violation_description": "Cheating in final exam",
  "violation_date": "2026-01-12",
  "decision_number": "DC-2026-014",
  "decision_date": "2026-01-20",
  "penalty_type_id": 7,
  "penalty_start_date": "2026-01-21",
  "penalty_end_date": null,
  "is_in_absentia": false,
  "decided_by_authority": "disciplinary_council"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `violation_type_id` | `required\|integer\|exists:disciplinary_violation_types,violation_type_id` |
| `trigger_course_offering_id` | `nullable\|integer\|exists:course_offerings,course_offering_id` |
| `violation_description` | `required\|string` |
| `violation_date` | `required\|date` |
| `decision_number` | `nullable\|string\|max:80` |
| `decision_date` | `required\|date` |
| `penalty_type_id` | `required\|integer\|exists:disciplinary_penalty_types,penalty_type_id` |
| `penalty_start_date` | `nullable\|date` |
| `penalty_end_date` | `nullable\|date\|after_or_equal:penalty_start_date` |
| `is_in_absentia` | `boolean` |
| `decided_by_authority` | `required\|string\|in:instructor,dean_or_institute_director,university_president,disciplinary_council` |

### Success response example (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "case_id": 3,
    "student_id": 15,
    "penalty_type_id": 7,
    "case_status": "active",
    "investigation_status": "pending"
  }
}
```

### Error response examples (422)

```json
{
  "success": false,
  "message": "Penalty type zero_and_subsequent requires trigger_course_offering_id to be set.",
  "errors": {}
}
```

```json
{
  "success": false,
  "message": "No exam_date is configured on theoretical grade components for the trigger course offering.",
  "errors": {}
}
```

### Frontend notes

- If user selects penalty code `zero_and_subsequent`, enforce `trigger_course_offering_id` in UI.
- Make sure trigger offering has a theoretical exam date before submit; otherwise backend returns 422.

---

## GET /api/v1/students/{student}/disciplinary-cases

**Purpose:** Retrieve all disciplinary cases for one student (non-paginated list).

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": [
    {
      "case_id": 3,
      "student_id": 15,
      "penalty_type_id": 7,
      "case_status": "active",
      "violation_date": "2026-01-12"
    }
  ]
}
```

### Error response example (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Intended for student profile timeline/history sections.

---

## GET /api/v1/disciplinary-case-appeals

**Purpose:** Retrieve paginated appeal records.

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "current_page": 1,
    "data": [
      {
        "appeal_id": 12,
        "case_id": 3,
        "submitted_at": "2026-01-25T09:00:00.000000Z",
        "appeal_reason": "Procedural issue",
        "appeal_status_id": 1,
        "reviewed_by_user_id": null,
        "decision_date": null,
        "decision_notes": null,
        "created_at": "2026-01-25T09:00:00.000000Z",
        "updated_at": "2026-01-25T09:00:00.000000Z"
      }
    ]
  }
}
```

### Error response example (401)

```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

### Frontend notes

- Pair with `appeal_statuses` lookup to display status labels.

---

## GET /api/v1/disciplinary-case-appeals/{id}

**Purpose:** Retrieve one appeal by `appeal_id`.

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 12,
    "case_id": 3,
    "submitted_at": "2026-01-25T09:00:00.000000Z",
    "appeal_reason": "Procedural issue",
    "appeal_status_id": 1,
    "reviewed_by_user_id": null,
    "decision_date": null,
    "decision_notes": null,
    "created_at": "2026-01-25T09:00:00.000000Z",
    "updated_at": "2026-01-25T09:00:00.000000Z"
  }
}
```

### Error response example (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Use this for detailed review and decision screens.

---

## POST /api/v1/disciplinary-case-appeals

**Purpose:** Submit an appeal for a disciplinary case.

### Request body example

```json
{
  "case_id": 3,
  "appeal_reason": "Procedural issue"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `case_id` | `required\|integer\|exists:student_disciplinary_cases,case_id` |
| `appeal_reason` | `required\|string` |

### Success response example (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 12,
    "case_id": 3,
    "submitted_at": "2026-01-25T09:00:00.000000Z",
    "appeal_reason": "Procedural issue",
    "appeal_status_id": 1
  }
}
```

### Error response example (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "case_id": [
      "The selected case id is invalid."
    ]
  }
}
```

### Frontend notes

- Submit route sets case status to `appealed` automatically.

---

## POST /api/v1/disciplinary-case-appeals/{id}/decide

**Purpose:** Decide an appeal status.

- `status_code = accepted`: backend automatically reverts affected course grades and sets case `overturned`.
- `status_code = rejected`: backend sets case back to `active`.

### Request body example

```json
{
  "status_code": "accepted",
  "notes": "Appeal approved after review"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|in:accepted,rejected` |
| `notes` | `nullable\|string` |

### Success response example (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 12,
    "case_id": 3,
    "appeal_status_id": 3,
    "reviewed_by_user_id": 9,
    "decision_date": "2026-01-27",
    "decision_notes": "Appeal approved after review"
  }
}
```

### Error response examples (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "status_code": [
      "The selected status code is invalid."
    ]
  }
}
```

```json
{
  "success": false,
  "message": "Appeal status \"accepted\" was not found in appeal_statuses.",
  "errors": {}
}
```

### Frontend notes

- Only send `accepted` or `rejected`.
- After an accepted decision, refresh case details and grade-related screens to reflect restored values.
