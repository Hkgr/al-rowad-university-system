# Attendance API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages attendance sessions for course offerings, records per-student attendance, and applies deprivation rules when absence exceeds the threshold. It supports instructor workflows (create session, mark attendance) and student/admin views (absence percentage, deprived student lists).

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Attendance Business Rules

| Rule | Detail |
|------|--------|
| Deprivation threshold | **15%** absence |
| Deprivation result | Grade **Z** (deprived status); `final_mark = 0` |
| GPA impact | Deprived courses **excluded** from GPA/CGPA |
| Absence counting | Uses `attendance_statuses.counts_as_absent` flag |
| Present statuses | Typically `present` and `late` |
| Active registrations only | Only `registered` students appear in session rosters |
| Session types | `theoretical`, `practical`, or `lecture` (mapped to `theoretical`) |

---

## Endpoint List

### Session operations

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/course-offerings/{id}/attendance-sessions` | List sessions for offering |
| POST | `/api/v1/course-offerings/{id}/attendance-sessions` | Create session for offering |
| GET | `/api/v1/attendance-sessions/{id}/students` | Roster with attendance status |
| POST | `/api/v1/attendance-sessions/{id}/record` | Bulk record attendance |
| GET | `/api/v1/attendance-sessions` | CRUD — attendance sessions (admin) |
| GET | `/api/v1/student-attendance` | CRUD — individual attendance rows |

### Deprivation

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/course-offerings/{id}/deprived-students` | Students above 15% absence |
| POST | `/api/v1/course-offerings/{id}/apply-deprivation` | Apply Z grade to eligible students |

### Student views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/{student}/attendance` | Student attendance summary |
| GET | `/api/v1/students/{student}/absence-percentage` | Absence % for one offering |

### Lookup

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/attendance-statuses` | Attendance status codes |

---

## GET /api/v1/course-offerings/{id}/attendance-sessions

**Purpose:** List all attendance sessions for a course offering with summary counts.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "sessions": [
      {
        "attendance_session_id": 1,
        "session_type": "theoretical",
        "session_date": "2026-10-01",
        "start_time": "09:00:00",
        "end_time": "10:30:00",
        "registered_students_count": 28,
        "recorded_count": 25,
        "present_count": 22,
        "absent_count": 3
      }
    ]
  }
}
```

---

## POST /api/v1/course-offerings/{id}/attendance-sessions

**Purpose:** Create a new attendance session for a course offering.

### Request body

```json
{
  "session_date": "2026-10-01",
  "session_type": "theoretical",
  "topic": "Introduction to arrays",
  "start_time": "09:00:00",
  "end_time": "10:30:00",
  "faculty_member_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `session_date` | `required\|date` |
| `session_type` | `nullable\|string\|max:50\|in:theoretical,practical,lecture` |
| `topic` | `nullable\|string\|max:255` |
| `start_time` | `nullable\|date_format:H:i:s` |
| `end_time` | `nullable\|date_format:H:i:s` |
| `faculty_member_id` | `nullable\|integer\|exists:faculty_members,faculty_member_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Attendance session created successfully",
  "data": {
    "attendance_session_id": 1,
    "course_offering_id": 5,
    "session_type": "theoretical",
    "session_date": "2026-10-01",
    "registered_students_count": 28,
    "recorded_count": 0
  }
}
```

### Frontend notes

- `created_by_user_id` is set automatically from the authenticated user.
- `lecture` is stored as `theoretical`.

---

## GET /api/v1/attendance-sessions/{id}/students

**Purpose:** Get registered students for a session with their current attendance status.

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "attendance_session_id": 1,
    "course_offering_id": 5,
    "students": [
      {
        "student_course_registration_id": 10,
        "student_id": 1,
        "student_number": "2026-0001",
        "full_name": "Ahmad Ali",
        "attendance_status": {
          "attendance_status_id": 1,
          "status_code": "present",
          "status_name": "Present"
        },
        "attendance_status_id": 1,
        "notes": null
      }
    ]
  }
}
```

---

## POST /api/v1/attendance-sessions/{id}/record

**Purpose:** Record or update attendance for multiple students in one session.

### Request body

```json
{
  "records": [
    {
      "student_course_registration_id": 10,
      "status_code": "present",
      "notes": null
    },
    {
      "student_course_registration_id": 11,
      "attendance_status_id": 2,
      "notes": "Medical excuse pending"
    }
  ]
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `records` | `required\|array\|min:1` |
| `records.*.student_course_registration_id` | `required\|integer\|exists:student_course_registrations,student_course_registration_id` |
| `records.*.attendance_status_id` | `nullable\|integer\|exists:attendance_statuses,attendance_status_id` — required if `status_code` omitted |
| `records.*.status_code` | `nullable\|string\|max:50` — required if `attendance_status_id` omitted |
| `records.*.notes` | `nullable\|string\|max:255` |

### Success response (200)

```json
{
  "success": true,
  "message": "Attendance recorded successfully",
  "data": {
    "attendance_session_id": 1,
    "recorded_count": 2,
    "records": []
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Registration does not belong to this session offering.",
  "errors": {}
}
```

### Frontend notes

- Load statuses from `GET /api/v1/attendance-statuses` for dropdown labels.
- Either `status_code` or `attendance_status_id` per row is sufficient.
- Submit entire roster or only changed rows (server upserts per registration).

---

## GET /api/v1/course-offerings/{id}/deprived-students

**Purpose:** List students whose absence percentage exceeds 15%.

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering": {
      "course_offering_id": 5,
      "course_code": "CS101",
      "course_name": "Intro to Programming"
    },
    "deprivation_threshold": 15,
    "students": [
      {
        "student_course_registration_id": 12,
        "student_id": 3,
        "student_number": "2026-0003",
        "full_name": "Sara Hassan",
        "total_sessions": 20,
        "absent_count": 4,
        "absence_percentage": 20.0,
        "is_already_deprived": false
      }
    ]
  }
}
```

---

## POST /api/v1/course-offerings/{id}/apply-deprivation

**Purpose:** Apply deprived (Z) result to all students above the 15% absence threshold.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Deprivation applied successfully",
  "data": {
    "applied_count": 2,
    "skipped_count": 26,
    "students_updated": [
      {
        "student_course_registration_id": 12,
        "student_id": 3,
        "absence_percentage": 20.0
      }
    ],
    "students_skipped": [
      {
        "student_course_registration_id": 10,
        "student_id": 1,
        "reason": "absence_below_threshold"
      }
    ]
  }
}
```

### Skip reasons

| Reason | Meaning |
|--------|---------|
| `absence_below_threshold` | ≤ 15% absence |
| `already_deprived` | Already marked deprived |

### Frontend notes

- Confirm before apply; action sets `is_deprived = true`, `final_mark = 0`, status deprived.
- Show post-action summary with applied vs skipped counts.

---

## GET /api/v1/students/{student}/absence-percentage

**Query:** `course_offering_id` (required)

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
    "absent_count": 4,
    "excused_count": 0
  }
}
```

### Frontend notes

- Show progress bar with 15% threshold marker on student dashboard.
- Warn when `is_deprived_candidate` is true.

---

## POST /api/v1/attendance-sessions (admin CRUD)

### Request body

```json
{
  "course_offering_id": 5,
  "session_type": "theoretical",
  "session_date": "2026-10-01",
  "start_time": "09:00:00",
  "end_time": "10:30:00",
  "faculty_member_id": 1,
  "created_by_user_id": 1,
  "notes": "Week 3"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `course_offering_id` | `required\|integer\|exists:course_offerings,course_offering_id` |
| `session_type` | `required\|string\|max:50` |
| `session_date` | `required\|date` |
| `start_time` | `nullable\|date_format:H:i:s` |
| `end_time` | `nullable\|date_format:H:i:s` |
| `faculty_member_id` | `nullable\|integer\|exists:faculty_members,faculty_member_id` |
| `created_by_user_id` | `required\|integer\|exists:users,user_id` |
| `notes` | `nullable\|string\|max:255` |

**Frontend notes:** Prefer `POST /course-offerings/{id}/attendance-sessions` for instructor UI (auto-fills creator).

---

## POST /api/v1/student-attendance (admin CRUD)

### Request body

```json
{
  "attendance_session_id": 1,
  "student_id": 1,
  "attendance_status_id": 1,
  "notes": null
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `attendance_session_id` | `required\|integer\|exists:attendance_sessions,attendance_session_id` |
| `student_id` | `required\|integer\|exists:students,student_id` |
| `attendance_status_id` | `required\|integer\|exists:attendance_statuses,attendance_status_id` |
| `notes` | `nullable\|string\|max:255` |

**Frontend notes:** Prefer bulk `record` endpoint for session marking UI.
