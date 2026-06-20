# Boards API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages university governance boards: board definitions, members, meetings, decisions, decision attachments, and meeting attendees. It supports recording formal decisions and meeting minutes linked to organizational units.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List (paginated) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete |

Query: `?per_page=15`

---

## Endpoint List

| Resource | Base path |
|----------|-----------|
| Boards | `/api/v1/boards` |
| Board members | `/api/v1/board-members` |
| Board meetings | `/api/v1/board-meetings` |
| Board decisions | `/api/v1/board-decisions` |
| Board decision attachments | `/api/v1/board-decision-attachments` |
| Meeting attendees | `/api/v1/meeting-attendees` |

---

## POST /api/v1/boards

**Purpose:** Create a governance board.

### Request body

```json
{
  "board_code": "ACAD-COUNCIL",
  "board_name": "Academic Council",
  "organizational_unit_id": 1,
  "description": "Academic policy board",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `board_code` | `required\|string\|max:50` — recommended: `required\|string\|max:50\|regex:/^[A-Z0-9_-]+$/` |
| `board_name` | `required\|string\|max:150` |
| `organizational_unit_id` | `nullable\|integer\|exists:organizational_units,organizational_unit_id` |
| `description` | `nullable\|string` |
| `is_active` | `required\|integer` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "board_id": 1,
    "board_code": "ACAD-COUNCIL",
    "board_name": "Academic Council",
    "is_active": 1
  }
}
```

---

## POST /api/v1/board-members

### Request body

```json
{
  "board_id": 1,
  "employee_id": 1,
  "full_name": "Dr. Ahmad Ali",
  "member_title": "Chair",
  "start_date": "2025-09-01",
  "end_date": null,
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `board_id` | `required\|integer\|exists:boards,board_id` |
| `employee_id` | `nullable\|integer\|exists:employees,employee_id` |
| `full_name` | `required\|string\|max:200` — recommended name rule |
| `member_title` | `nullable\|string\|max:150` |
| `start_date` | `nullable\|date` |
| `end_date` | `nullable\|date` |
| `is_active` | `required\|integer` |

### Frontend notes

- Link to employee when member is staff; otherwise use `full_name` for external members.

---

## POST /api/v1/board-meetings

### Request body

```json
{
  "board_id": 1,
  "meeting_title": "Monthly Academic Meeting",
  "meeting_date": "2026-06-15",
  "location": "Main Hall",
  "agenda": "1. Curriculum updates\n2. Budget review",
  "minutes": null,
  "created_by_user_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `board_id` | `required\|integer\|exists:boards,board_id` |
| `meeting_title` | `required\|string\|max:200` |
| `meeting_date` | `required\|date` |
| `location` | `nullable\|string\|max:200` |
| `agenda` | `nullable\|string` |
| `minutes` | `nullable\|string` |
| `created_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "board_meeting_id": 1,
    "board_id": 1,
    "meeting_title": "Monthly Academic Meeting",
    "meeting_date": "2026-06-15"
  }
}
```

---

## POST /api/v1/board-decisions

### Request body

```json
{
  "board_meeting_id": 1,
  "decision_number": "2026/014",
  "decision_title": "Approve new CS elective",
  "decision_text": "The council approves adding CS450 as an elective course.",
  "decision_date": "2026-06-15"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `board_meeting_id` | `required\|integer\|exists:board_meetings,board_meeting_id` |
| `decision_number` | `nullable\|string\|max:80` |
| `decision_title` | `required\|string\|max:200` |
| `decision_text` | `required\|string` |
| `decision_date` | `nullable\|date` |

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "decision_title": ["The decision title field is required."],
    "decision_text": ["The decision text field is required."]
  }
}
```

---

## POST /api/v1/board-decision-attachments

**Purpose:** Attach files/metadata to a decision (CRUD resource).

Typical fields include decision reference, file name, and file URL (see backend FormRequest).

---

## POST /api/v1/meeting-attendees

**Purpose:** Record attendance at a board meeting (CRUD resource).

Links meetings to board members or attendees (see backend FormRequest).

### Frontend notes (general)

- Workflow: create board → add members → schedule meeting → record attendees → log decisions → attach documents.
- Use date pickers validated as ISO dates (`YYYY-MM-DD`).
- Minutes can be updated via PUT after the meeting.
- `is_active` fields use integer 0/1 throughout.
