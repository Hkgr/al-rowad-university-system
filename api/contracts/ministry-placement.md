# Ministry Placement (مفاضلة) API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

هذه الوحدة تستورد ملفات مفاضلة وزارة التعليم العالي، تتيح مطابقة كل سجل ببرنامج أكاديمي حقيقي، وتحويل السجلات إلى متقدمين وطلبات قبول. تسجيل طالب جديد يتطلب وجود طلب قبول مقبول (`decision_status = accepted`) مرتبط بسجل مفاضلة مستورد.

This module imports Ministry placement Excel files, lets staff match records to academic programs, convert them to applicants/admission applications, and gates new student creation on an accepted application that traces back to a placement record.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

For import, use `multipart/form-data` (and omit JSON `Content-Type`).

## Business Rules

| Rule | Detail |
|------|--------|
| Excel layout | Row 1 = title, row 2 = headers, data from row 3 |
| Blank civil IDs | Rows with empty `national_civil_id` are skipped |
| Boolean cells | Accepts `TRUE`/`FALSE`, `نعم`/`لا` (case-insensitive for Latin) |
| Match program | Sets `matched_academic_program_id` only; does not change `processing_status` |
| Convert | Requires matched program; creates applicant + `admission_applications` with `decision_status = pending` |
| Student gate | `POST /students` requires `admission_application_id` pointing to an **accepted** application whose applicant has a `ministry_placement_records` row |

### `processing_status` values

`imported`, `applicant_created`, `documents_pending`, `enrolled`, `rejected`

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/v1/ministry-placements/import` | Import a Ministry Excel batch |
| GET | `/api/v1/ministry-placements` | List import batches |
| GET | `/api/v1/ministry-placements/{id}` | Show one batch with records |
| GET | `/api/v1/ministry-placements/{id}/records` | Paginated records for a batch |
| POST | `/api/v1/ministry-placement-records/{id}/match-program` | Match record to academic program |
| POST | `/api/v1/ministry-placement-records/{id}/convert-to-applicant` | Create applicant + pending application |

### Related student gate

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/v1/students` | Create student — requires accepted Ministry-linked application |

---

## POST /api/v1/ministry-placements/import

**Purpose:** Upload a Ministry Excel file and create a batch plus placement records (`processing_status = imported`).

**Content-Type:** `multipart/form-data`

### Request body (form-data)

| Field | Type | Example |
|-------|------|---------|
| `file` | file | `placement_2026.xlsx` |
| `batch_name` | string | `مفاضلة 2026 — دفعة 1` |
| `academic_year_id` | integer | `1` |
| `notes` | string (optional) | `Round 1 import` |

### Validation rules

| Field | Rules |
|-------|-------|
| `file` | `required\|file\|mimes:xlsx,xls\|max:10240` |
| `batch_name` | `required\|string\|max:255` |
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `notes` | `nullable\|string` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "batch_id": 1,
    "batch_name": "مفاضلة 2026 — دفعة 1",
    "source_file_name": "placement_2026.xlsx",
    "academic_year_id": 1,
    "import_date": "2026-08-17",
    "imported_by_user_id": 2,
    "notes": "Round 1 import",
    "records": [
      {
        "placement_record_id": 1,
        "national_civil_id": "01020304050",
        "first_name": "أحمد",
        "last_name": "علي",
        "processing_status": "imported"
      }
    ]
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "file": ["The file field is required."]
  }
}
```

### Frontend notes

- Send as multipart form; do not JSON-encode the file.
- After import, open the batch records UI to match programs before convert.

---

## GET /api/v1/ministry-placements

**Purpose:** Paginated list of import batches.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [
      {
        "batch_id": 1,
        "batch_name": "مفاضلة 2026 — دفعة 1",
        "academic_year_id": 1,
        "import_date": "2026-08-17",
        "records_count": 120
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

- Use as the landing list for the Ministry placement admin screen.

---

## GET /api/v1/ministry-placements/{id}

**Purpose:** Show one batch including its records.

**URL parameter:** `{id}` = `batch_id`

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "batch_id": 1,
    "batch_name": "مفاضلة 2026 — دفعة 1",
    "source_file_name": "placement_2026.xlsx",
    "records": []
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

- Prefer `.../records` for large batches (paginated).

---

## GET /api/v1/ministry-placements/{id}/records

**Purpose:** Paginated placement records for review/match UI.

**URL parameter:** `{id}` = `batch_id`

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [
      {
        "placement_record_id": 10,
        "full_name": "أحمد علي",
        "national_civil_id": "01020304050",
        "accepted_preference_text": "كلية الهندسة المعلوماتية",
        "matched_academic_program_id": null,
        "processing_status": "imported"
      }
    ],
    "links": {},
    "meta": {}
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

- Show `accepted_preference_text` next to a program picker for matching.

---

## POST /api/v1/ministry-placement-records/{id}/match-program

**Purpose:** Attach a real `academic_programs` row to a placement record.

**URL parameter:** `{id}` = `placement_record_id`

### Request body example

```json
{
  "academic_program_id": 3
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `academic_program_id` | `required\|integer\|exists:academic_programs,academic_program_id` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "placement_record_id": 10,
    "matched_academic_program_id": 3,
    "processing_status": "imported"
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "academic_program_id": ["The selected academic program id is invalid."]
  }
}
```

### Frontend notes

- Matching does **not** change `processing_status`; convert is a separate step.

---

## POST /api/v1/ministry-placement-records/{id}/convert-to-applicant

**Purpose:** Create an applicant and a pending admission application from a matched placement record; set `processing_status` to `applicant_created`.

**URL parameter:** `{id}` = `placement_record_id`

**Request body:** None (empty JSON object is fine)

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "placement_record_id": 10,
    "applicant_id": 55,
    "matched_academic_program_id": 3,
    "processing_status": "applicant_created"
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "A matched academic program is required before converting a placement record to an applicant.",
  "errors": {}
}
```

### Frontend notes

- Call only after match-program succeeds.
- The created admission application starts as `pending`; staff must later set it to `accepted` before student creation.

---

## Related: POST /api/v1/students (placement gate)

**Purpose:** Create a student. `admission_application_id` is **required**.

### Gate rules

When `admission_application_id` is provided (always, now that it is required):

1. The application’s `decision_status` must be **`accepted`** (not `approved` — that value does not exist here).
2. A `ministry_placement_records` row must exist with `applicant_id = admission_applications.applicant_id`.

Otherwise the API returns **422** on `admission_application_id`.

### Frontend notes

- Do not create students from free-form demographic entry alone: enroll only after Ministry import → match → convert → accept application.
- Omitting `admission_application_id` now fails validation (`required`).
