# Disciplinary Cases API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

هذا الوحدة تدير **نظام العقوبات التأديبية** وفق الفصل العاشر من اللائحة (المواد 68–73): تسجيل مخالفات الطلاب، ربطها بأنواع العقوبات، تطبيق عقوبة صفر المقرر والمقررات اللاحقة عند الحاجة، وتقديم الطعون والبت فيها.

This module manages student disciplinary cases: create cases with a penalty type, optionally cascade zero marks to the trigger course and subsequent same-term offerings (by theoretical exam date), and handle appeals (submit / accept / reject with mark restore on accept).

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
| Case statuses | `active`, `appealed`, `overturned`, `served`, `expired` |
| Investigation | If penalty type `requires_investigation`, case is created with `investigation_status = pending` (tracked only; not enforced synchronously) |
| `zero_and_subsequent` | Requires `trigger_course_offering_id`; zeros trigger + same year/semester offerings whose theoretical `exam_date` ≥ trigger exam date |
| Appeal accept | Restores previous marks from `disciplinary_case_affected_courses` and sets case to `overturned` |
| Appeal reject | Sets parent case `case_status` back to `active` |

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/disciplinary-cases` | List disciplinary cases |
| GET | `/api/v1/disciplinary-cases/{id}` | Show one case |
| POST | `/api/v1/disciplinary-cases` | Create a disciplinary case |
| GET | `/api/v1/students/{student}/disciplinary-cases` | Cases for one student |
| GET | `/api/v1/disciplinary-case-appeals` | List appeals |
| GET | `/api/v1/disciplinary-case-appeals/{id}` | Show one appeal |
| POST | `/api/v1/disciplinary-case-appeals` | Submit an appeal |
| POST | `/api/v1/disciplinary-case-appeals/{id}/decide` | Accept or reject an appeal |

---

## GET /api/v1/disciplinary-cases

**Purpose:** Paginated list of disciplinary cases.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [
      {
        "case_id": 1,
        "student_id": 10,
        "violation_type_id": 2,
        "trigger_course_offering_id": 5,
        "violation_description": "Cheating during theoretical exam",
        "violation_date": "2026-01-10",
        "penalty_type_id": 7,
        "case_status": "active",
        "decided_by_authority": "disciplinary_council",
        "decision_date": "2026-01-15"
      }
    ],
    "links": {},
    "meta": {}
  }
}
```

### Error response (401)

```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

### Frontend notes

- Use for admin/registry case lists; filter client-side or via future query params if needed.

---

## GET /api/v1/disciplinary-cases/{id}

**Purpose:** Retrieve a single disciplinary case by `case_id`.

**URL parameter:** `{id}` = `case_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "case_id": 1,
    "student_id": 10,
    "violation_type_id": 2,
    "trigger_course_offering_id": 5,
    "violation_description": "Cheating during theoretical exam",
    "violation_date": "2026-01-10",
    "reported_by_user_id": 3,
    "investigation_status": "pending",
    "decided_by_authority": "disciplinary_council",
    "decided_by_user_id": 3,
    "decision_number": "DC-2026-014",
    "decision_date": "2026-01-15",
    "penalty_type_id": 7,
    "penalty_start_date": "2026-01-15",
    "penalty_end_date": null,
    "is_in_absentia": false,
    "case_status": "active",
    "created_at": "2026-01-15T10:00:00.000000Z",
    "updated_at": "2026-01-15T10:00:00.000000Z"
  }
}
```

### Error response (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Load violation/penalty type labels from lookup tables for display.

---

## POST /api/v1/disciplinary-cases

**Purpose:** Create a disciplinary case and, when the penalty cascades, apply zero marks to the trigger offering and subsequent same-term offerings.

### Request body example

```json
{
  "student_id": 10,
  "violation_type_id": 2,
  "trigger_course_offering_id": 5,
  "violation_description": "Cheating during theoretical exam",
  "violation_date": "2026-01-10",
  "decision_number": "DC-2026-014",
  "decision_date": "2026-01-15",
  "penalty_type_id": 7,
  "penalty_start_date": "2026-01-15",
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

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "case_id": 1,
    "student_id": 10,
    "penalty_type_id": 7,
    "case_status": "active",
    "investigation_status": null
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Penalty type zero_and_subsequent requires trigger_course_offering_id to be set.",
  "errors": {}
}
```

### Frontend notes

- Choosing the penalty type whose code is **`zero_and_subsequent`** (typically `cascades_to_subsequent_courses = 1`) **requires** `trigger_course_offering_id` to be set.
- The endpoint returns **422** if that ID is missing, or if the trigger course offering has **no theoretical `exam_date`** configured on its grade components yet — configure the exam date before applying this penalty.
- Other penalty types may omit `trigger_course_offering_id` unless your workflow still wants it for record-keeping.

---

## GET /api/v1/students/{student}/disciplinary-cases

**Purpose:** List all disciplinary cases for one student (profile page).

**URL parameter:** `{student}` = `student_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": [
    {
      "case_id": 1,
      "student_id": 10,
      "case_status": "active",
      "violation_date": "2026-01-10",
      "penalty_type_id": 7
    }
  ]
}
```

### Error response (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Use on the student profile under a “Disciplinary cases” section.

---

## GET /api/v1/disciplinary-case-appeals

**Purpose:** Paginated list of disciplinary case appeals.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [
      {
        "appeal_id": 1,
        "case_id": 1,
        "appeal_reason": "Procedural error in investigation",
        "appeal_status_id": 1,
        "submitted_at": "2026-01-20T09:00:00.000000Z"
      }
    ],
    "links": {},
    "meta": {}
  }
}
```

### Error response (401)

```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

### Frontend notes

- Pair with `appeal-statuses` lookup for status labels.

---

## GET /api/v1/disciplinary-case-appeals/{id}

**Purpose:** Retrieve one appeal by `appeal_id`.

**URL parameter:** `{id}` = `appeal_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 1,
    "case_id": 1,
    "submitted_at": "2026-01-20T09:00:00.000000Z",
    "appeal_reason": "Procedural error in investigation",
    "appeal_status_id": 1,
    "reviewed_by_user_id": null,
    "decision_date": null,
    "decision_notes": null
  }
}
```

### Error response (404)

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

### Frontend notes

- Show linked case details via `GET /disciplinary-cases/{case_id}` when needed.

---

## POST /api/v1/disciplinary-case-appeals

**Purpose:** Submit an appeal against a disciplinary case; sets parent case status to `appealed`.

### Request body example

```json
{
  "case_id": 1,
  "appeal_reason": "Procedural error in investigation"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `case_id` | `required\|integer\|exists:student_disciplinary_cases,case_id` |
| `appeal_reason` | `required\|string` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 1,
    "case_id": 1,
    "appeal_status_id": 1,
    "submitted_at": "2026-01-20T09:00:00.000000Z",
    "appeal_reason": "Procedural error in investigation"
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "case_id": ["The selected case id is invalid."]
  }
}
```

### Frontend notes

- Initial appeal status is the `submitted` row in `appeal_statuses`.

---

## POST /api/v1/disciplinary-case-appeals/{id}/decide

**Purpose:** Accept or reject an appeal. Accepting restores affected course marks and sets the case to `overturned`; rejecting sets the case back to `active`.

**URL parameter:** `{id}` = `appeal_id`

### Request body example

```json
{
  "status_code": "accepted",
  "notes": "Appeal upheld — marks restored"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `status_code` | `required\|string\|in:accepted,rejected` |
| `notes` | `nullable\|string` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "appeal_id": 1,
    "case_id": 1,
    "appeal_status_id": 3,
    "decision_date": "2026-01-25",
    "decision_notes": "Appeal upheld — marks restored",
    "reviewed_by_user_id": 4
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "status_code": ["The selected status code is invalid."]
  }
}
```

### Frontend notes

- Use `accepted` / `rejected` only (matches seeded `appeal_statuses`).
- After accept, re-fetch the case and grade sheet to confirm restored marks.
