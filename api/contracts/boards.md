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
| DELETE | `/api/v1/{resource}/{id}` | Delete (**existing** — currently hard delete) |

Query: `?per_page=15`

> **Note:** Soft delete, restore, and force-delete routes are **not yet implemented**. See [Deletion Policy](#deletion-policy) for the recommended future contract.

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

---

## Deletion Policy

Documents **Soft Delete** and **Permanent Delete** for governance boards, meetings, decisions, and related records.

### Soft Delete vs Permanent Delete

| Action | Meaning |
|--------|---------|
| **Soft Delete** | Archive board governance record — preserve legal/historical trail |
| **Permanent Delete** | Remove record — **Super Admin only**; strongly discouraged for decisions and minutes |

> **Implementation status:** Existing `DELETE` on board resources (**existing**, hard delete). Proposed archive/restore/force routes are **recommended future endpoints**.

### Who may perform each action

| Action | Recommended role |
|--------|------------------|
| Soft Delete | Admin, Secretary |
| Restore | Admin |
| Permanent Delete | **Super Admin only** (rare) |

### Business rules before deletion

| Resource | Permanent delete blocked when |
|----------|--------------------------------|
| `boards` | Has `board-meetings`, `board-members` |
| `board-meetings` | Has `board-decisions`, `meeting-attendees` |
| `board-decisions` | Has `board-decision-attachments` |
| `board-members` | Referenced in active governance period (recommended soft delete only) |

**Strong recommendation:** Board decisions and meeting minutes should **never** be permanently deleted once published — archive only.

### Proposed endpoints (boards)

| Method | URL | Status |
|--------|-----|--------|
| DELETE | `/api/v1/boards/{board_id}` | **Existing** — recommend soft delete |
| GET | `/api/v1/boards/deleted` | **Proposed endpoint** |
| POST | `/api/v1/boards/{board_id}/restore` | **Proposed endpoint** |
| DELETE | `/api/v1/boards/{board_id}/force` | **Proposed endpoint** |

Apply the same pattern to `board-meetings`, `board-decisions`, `board-members`, `board-decision-attachments`, `meeting-attendees`.

**Optional request body:**

```json
{
  "delete_reason": "Board restructured",
  "deleted_by_user_id": 1
}
```

**Validation rules:**

| Field | Rules |
|-------|-------|
| Resource ID (URL) | `required\|integer\|exists:{table},{primary_key}` |
| `delete_reason` | `nullable\|string\|max:1000` |
| `deleted_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Standard responses

**Soft delete:**

```json
{
  "success": true,
  "message": "Record archived successfully.",
  "data": null
}
```

**Restore:**

```json
{
  "success": true,
  "message": "Record restored successfully.",
  "data": {
    "board_id": 1,
    "board_name": "Academic Council",
    "deleted_at": null
  }
}
```

**Permanent delete:**

```json
{
  "success": true,
  "message": "Record permanently deleted successfully.",
  "data": null
}
```

**Blocked permanent delete (422):**

```json
{
  "success": false,
  "message": "Record cannot be permanently deleted because related records exist.",
  "errors": {
    "related_records": [
      "board_meetings",
      "board_decisions",
      "board_members"
    ]
  }
}
```

### Frontend behavior

- Hide archived boards/meetings from default views.
- Use **Archive** for boards; do not offer permanent delete on decisions with attachments.
- **Permanent Delete** — Super Admin only, confirmation modal, irreversible warning.
- Prefer `is_active = 0` on `board-members` when membership ends instead of permanent delete.
