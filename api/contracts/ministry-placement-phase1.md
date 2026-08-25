# Ministry Placement Phase 1 API

All endpoints require an authenticated, active account, the effective/actually
assigned RBAC permission listed below (excluding the virtual `super_admin`
bypass), and an actual university access scope. The `super_admin` role does
not replace either requirement.

## Excel contract

- Row 1 is informational. A blank row produces `blank_title_row` only.
- Row 2 contains the A:X headers and the required positional anchors, including
  accepted preference in K, date of birth in Q, and last name in T.
- Non-empty data after X is a structural error; formatting outside A:X is
  ignored.
- An additional empty or formatting-only worksheet is ignored with
  `additional_empty_sheet_ignored`; actual content on it is blocking.
- Data starts at row 3 and follows the existing 24-column Ministry mapping.
- Excel serial dates require a genuinely numeric workbook cell. Numeric-looking
  text is validated as text and is not interpreted as a serial date.
- Identifiers remain trimmed strings. Duplicate comparison alone normalizes
  Arabic digit forms and Unicode whitespace; it never casts to a number.
- Preview writes nothing. Import parses and validates the workbook again.

## Endpoints

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| POST | `/api/v1/ministry-placements/preview` | `admissions.manage` | Validate and preview an Excel workbook |
| POST | `/api/v1/ministry-placements/import` | `admissions.manage` | Atomically create one batch and its records |
| GET | `/api/v1/ministry-placements` | `admissions.view` | Paginated batch list |
| GET | `/api/v1/ministry-placements/{batch}` | `admissions.view` | Batch metadata and record count |
| GET | `/api/v1/ministry-placements/{batch}/records` | `admissions.view` | Paginated, searchable read-only records |

Both POST requests use `multipart/form-data`. Import accepts `file`,
`batch_name`, `academic_year_id`, and optional `notes`. Batch and record lists
accept `page` and `per_page` up to 100; records also accept `q` and
`processing_status`.

Phase 1 exposes no program matching, applicant conversion, admission decision,
student creation, or user-account provisioning endpoint.
