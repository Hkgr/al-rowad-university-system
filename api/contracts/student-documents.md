# Student Documents API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages student document records: metadata CRUD, **protected multipart file upload**, and **authenticated download**. Files are stored on a **private** Laravel storage disk — they are **not** publicly accessible by direct URL.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
```

For **file upload**, use `multipart/form-data` (do not send `Content-Type: application/json` on the upload request — the client sets the multipart boundary automatically).

For **file download**, send the Bearer token; `Accept` may be `application/octet-stream` or `application/json` (JSON is returned only on error responses).

## Standard Response Envelope

**Success:**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {}
}
```

**Not found (404):**

```json
{
  "success": false,
  "message": "File not found.",
  "errors": []
}
```

**Unauthorized (401):** Returned when no valid Bearer token is provided.

---

## Endpoint List

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/student-documents` | List all student documents (paginated) |
| POST | `/api/v1/student-documents` | Create document **metadata** via JSON (legacy) |
| GET | `/api/v1/student-documents/{studentDocument}` | Show one document |
| PUT/PATCH | `/api/v1/student-documents/{studentDocument}` | Update document metadata |
| DELETE | `/api/v1/student-documents/{studentDocument}` | Delete file from storage + DB record |
| GET | `/api/v1/students/{student}/documents` | List documents for one student |
| POST | `/api/v1/students/{student}/documents` | **Upload** a file (multipart) |
| GET | `/api/v1/student-documents/{studentDocument}/download` | **Protected download** |

---

## Resource fields (`StudentDocumentResource`)

| Field | Type | Description |
|-------|------|-------------|
| `student_document_id` | integer | Primary key |
| `student_id` | integer | Owner student |
| `document_type_id` | integer | FK to `document_types` |
| `document_type` | object \| null | Included when relation is loaded |
| `file_name` | string | Original upload filename |
| `file_url` | string | **Internal storage path only** — not a public browser URL |
| `download_url` | string | API path: `/api/v1/student-documents/{id}/download` |
| `verification_status` | string | e.g. `pending`, `verified`, `rejected` |
| `verified_by_user_id` | integer \| null | User who verified |
| `verified_at` | datetime \| null | Verification timestamp |
| `verification_notes` | string \| null | Notes |
| `uploaded_at` | datetime | Upload timestamp |

### Example resource

```json
{
  "student_document_id": 1,
  "student_id": 12,
  "document_type_id": 2,
  "document_type": {
    "document_type_id": 2,
    "type_name": "National ID"
  },
  "file_name": "national_id.pdf",
  "file_url": "students/2026-DEMO-001/documents/doc_67890abcdef.pdf",
  "download_url": "http://127.0.0.1:8000/api/v1/student-documents/1/download",
  "verification_status": "pending",
  "verified_by_user_id": null,
  "verified_at": null,
  "verification_notes": null,
  "uploaded_at": "2026-06-20T10:30:00.000000Z"
}
```

---

## GET /api/v1/student-documents

**Purpose:** Paginated list of all student documents.

### Query parameters

| Param | Rules |
|-------|-------|
| `per_page` | optional integer, default 15 |
| `page` | optional integer |

---

## POST /api/v1/student-documents

**Purpose:** Create document **metadata** via JSON (no file upload). Prefer `POST /api/v1/students/{student}/documents` for real file uploads.

### Request body

```json
{
  "student_id": 1,
  "document_type_id": 1,
  "file_name": "national_id.pdf",
  "file_url": "students/2026-DEMO-001/documents/legacy-reference.pdf",
  "verification_status": "pending",
  "verification_notes": null,
  "uploaded_at": "2026-06-20"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `student_id` | `required\|integer\|exists:students,student_id` |
| `document_type_id` | `required\|integer\|exists:document_types,document_type_id` |
| `file_name` | `required\|string\|max:255` |
| `file_url` | `required\|string\|max:500` — internal path or legacy reference, **not** a public URL |
| `verification_status` | `nullable\|string\|max:50` |
| `verified_by_user_id` | `nullable\|integer\|exists:users,user_id` |
| `verified_at` | `nullable\|date` |
| `verification_notes` | `nullable\|string\|max:255` |
| `uploaded_at` | `nullable\|date` |

---

## GET /api/v1/students/{student}/documents

**Purpose:** List all documents for a specific student.

`{student}` is the numeric `student_id` (route model binding).

### Success response (200)

Returns a collection of `StudentDocumentResource` items in the standard success envelope.

---

## POST /api/v1/students/{student}/documents

**Purpose:** Upload a student document file to **private** storage and create the database record.

### Request type

`multipart/form-data`

### Form fields

| Field | Rules | Description |
|-------|-------|-------------|
| `document_type_id` | `required\|integer\|exists:document_types,document_type_id` | Document type |
| `file` | `required\|file\|mimes:pdf,jpg,jpeg,png\|max:5120` | File (max **5 MB**) |
| `verification_notes` | `nullable\|string\|max:255` | Optional notes |
| `uploaded_at` | `nullable\|date` | Optional; defaults to server `now()` |

### Storage behavior

- Files are stored on the **`local`** disk (`storage/app/private`) — **not** the `public` disk.
- Path format: `students/{student_number}/documents/{generated_filename}`
- `file_name` stores the **original** filename.
- `file_url` stores the **internal storage path** (e.g. `students/2026-DEMO-001/documents/doc_....pdf`).
- `verification_status` defaults to **`pending`**.
- No `php artisan storage:link` is required for access — files are served only through the protected download endpoint.

### Security

- Files are **NOT** public.
- `file_url` is **not** a browser-accessible URL.
- Access requires `Authorization: Bearer {token}`.

### Success response (201)

```json
{
  "success": true,
  "message": "Student document uploaded successfully",
  "data": {
    "student_document_id": 1,
    "student_id": 12,
    "document_type_id": 2,
    "file_name": "passport.pdf",
    "file_url": "students/2026-DEMO-001/documents/doc_67890abcdef.pdf",
    "download_url": "http://127.0.0.1:8000/api/v1/student-documents/1/download",
    "verification_status": "pending",
    "verified_by_user_id": null,
    "verified_at": null,
    "verification_notes": null,
    "uploaded_at": "2026-06-20T10:30:00.000000Z",
    "document_type": {
      "document_type_id": 2,
      "type_name": "Passport"
    }
  }
}
```

### Example (Bruno / Postman)

```http
POST /api/v1/students/12/documents
Authorization: Bearer {token}
Content-Type: multipart/form-data

document_type_id: 2
file: [select passport.pdf]
verification_notes: Submitted for admission review
```

---

## GET /api/v1/student-documents/{studentDocument}/download

**Purpose:** Download the physical file for an authenticated user.

### Headers

```http
Authorization: Bearer {token}
Accept: application/octet-stream
```

(`Accept: application/json` is also accepted; errors return JSON.)

### Behavior

| Condition | Response |
|-----------|----------|
| Valid token + file exists | File stream with `Content-Disposition: attachment` |
| No / invalid token | **401** Unauthorized |
| DB record missing | **404** |
| Physical file missing on disk | **404** JSON: `"File not found."` |

- Does **not** expose the physical server path to the client.
- Does **not** return a public URL.

### Example

```http
GET /api/v1/student-documents/1/download
Authorization: Bearer {token}
```

---

## DELETE /api/v1/student-documents/{studentDocument}

**Purpose:** Remove a student document.

### Behavior

1. Deletes the **physical file** from private storage if it exists.
2. Deletes the **database record**.
3. Does **not** crash if the physical file is already missing — the DB record is still removed.

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": []
}
```

---

## Frontend notes

- **Do not** open `download_url` directly in the browser tab or `<a href>` — the browser will not send the `Authorization` header.
- Download using **fetch** or **axios** with `Authorization: Bearer {token}`, then convert the response to a **Blob** and trigger a client-side save:

```javascript
const response = await fetch(downloadUrl, {
  headers: { Authorization: `Bearer ${token}` },
});
const blob = await response.blob();
const url = URL.createObjectURL(blob);
const a = document.createElement('a');
a.href = url;
a.download = fileName;
a.click();
URL.revokeObjectURL(url);
```

- For upload, use `FormData` and append `file` as a `File` object; do not JSON-stringify the body.
- Display `file_name` to the user; treat `file_url` as an internal backend path only.
- Use `download_url` from the API response for authenticated downloads.

---

## Related contracts

- [students.md](./students.md) — student CRUD and per-student document listing
- [lookups.md](./lookups.md) — `document_types` lookup
- [admissions.md](./admissions.md) — admission workflow document references
