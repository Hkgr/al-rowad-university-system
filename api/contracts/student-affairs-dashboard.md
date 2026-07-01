# Student Affairs Dashboard API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This endpoint returns **aggregate counts** for the Student Affairs dashboard in a **single request**, replacing multiple parallel list calls that previously inferred totals from pagination metadata or array lengths.

## Authentication Requirements

```http
Authorization: Bearer {token}
Accept: application/json
```

---

## Endpoint

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/student-affairs/dashboard-stats` | Dashboard aggregate counts |

---

## GET /api/v1/student-affairs/dashboard-stats

**Purpose:** Return all counts needed by the Student Affairs home dashboard.

### Replaces these frontend calls

| Old call | Replaced by field |
|----------|-------------------|
| `GET /api/v1/students?per_page=1` (read `meta.total`) | `total_students` |
| `GET /api/v1/students?per_page=1&student_status_id=3` | `graduates_count` |
| `GET /api/v1/colleges?per_page=50` (count rows) | `colleges_count` |
| `GET /api/v1/departments?per_page=100` | `departments_count` |
| `GET /api/v1/academic-programs?per_page=100` | `programs_count` |

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "total_students": 70,
    "graduates_count": 2,
    "colleges_count": 3,
    "departments_count": 6,
    "programs_count": 6
  }
}
```

### Response fields

| Field | Type | Counting rule |
|-------|------|---------------|
| `total_students` | integer | Non-soft-deleted students (`Student::query()` — archived students excluded) |
| `graduates_count` | integer | Students where `student_status_id = 3` (current graduate status convention) |
| `colleges_count` | integer | Active colleges (`is_active = true`) when `is_active` column exists; otherwise all colleges |
| `departments_count` | integer | Active departments when `is_active` exists; otherwise all |
| `programs_count` | integer | Active academic programs when `is_active` exists; otherwise all |

### Notes

- Does **not** use `withTrashed()` for student counts.
- `graduates_count` uses `student_status_id = 3` for now, matching the previous frontend filter convention.
- Counts are exact — not limited by `per_page` pagination caps.

### Example request

```http
GET /api/v1/student-affairs/dashboard-stats
Authorization: Bearer {token}
Accept: application/json
```

---

## Frontend notes

- **`StudentAffairsHome.jsx`** should call this **one endpoint** instead of the five parallel requests listed above.
- Map response fields directly to dashboard stat cards:

```javascript
const { data } = await api.get('/student-affairs/dashboard-stats');
const stats = data.data;

setTotalStudents(stats.total_students);
setGraduates(stats.graduates_count);
setColleges(stats.colleges_count);
setDepartments(stats.departments_count);
setPrograms(stats.programs_count);
```

- Do not infer dashboard totals from `GET /api/v1/students?per_page=1` anymore — use this dedicated endpoint for accuracy and performance.

---

## Related contracts

- [students.md](./students.md) — student list filters (`GET /api/v1/students`)
- [academic-structure.md](./academic-structure.md) — colleges, departments, academic programs
