# Academic Structure API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages the university academic hierarchy and catalog: academic years, semesters, colleges, departments, programs, levels, courses, course offerings (sections), program study plans, prerequisites, and related junction data. It supports both administrative CRUD and read-only relationship endpoints used by registration, grades, and student dashboards.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

Most resources in this module expose Laravel `apiResource` routes:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List (paginated) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show one |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete |

**Query parameters (index):**

| Param | Rules | Default |
|-------|-------|---------|
| `per_page` | integer, min 1 | 15 |

**Paginated success response shape:**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "data": [],
    "links": {},
    "meta": {}
  }
}
```

**Store success:** HTTP 201 with single resource in `data`.

**Update/Show success:** HTTP 200 with single resource in `data`.

**Destroy success:** HTTP 200 with `"data": []`.

**Validation error (422):**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

**Not found (404):**

```json
{
  "success": false,
  "message": "Resource not found",
  "errors": []
}
```

**Update requests:** All update FormRequests use `sometimes|nullable` on each field (partial updates supported).

---

## Endpoint List

### Calendar / context

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/academic-years/current` | Current academic year |
| GET | `/api/v1/semesters/active` | Active semesters |

### REST resources

| Resource | Base path |
|----------|-----------|
| Academic levels | `/api/v1/academic-levels` |
| Academic programs | `/api/v1/academic-programs` |
| Academic years | `/api/v1/academic-years` |
| Semesters | `/api/v1/semesters` |
| Colleges | `/api/v1/colleges` |
| Departments | `/api/v1/departments` |
| Courses | `/api/v1/courses` |
| Course departments | `/api/v1/course-departments` |
| Course instructors | `/api/v1/course-instructors` |
| Course offerings | `/api/v1/course-offerings` |
| Course prerequisites | `/api/v1/course-prerequisites` |
| Program courses | `/api/v1/program-courses` |

### Relationship / query endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/colleges/{college}/departments` | Departments in a college |
| GET | `/api/v1/departments/{department}/programs` | Programs in a department |
| GET | `/api/v1/programs/{academic_program}/students` | Students in a program |
| GET | `/api/v1/programs/{academic_program}/courses` | Courses in a program |
| GET | `/api/v1/programs/{id}/mandatory-courses` | Mandatory plan courses |
| GET | `/api/v1/programs/{id}/elective-courses` | Elective plan courses |
| GET | `/api/v1/programs/{id}/study-plan` | Full study plan grouped by level/semester |
| GET | `/api/v1/courses/{id}/departments` | Departments offering a course |
| GET | `/api/v1/courses/{id}/programs` | Programs including a course |
| GET | `/api/v1/courses/{id}/prerequisites` | Prerequisites for a course |
| GET | `/api/v1/courses/{id}/instructors` | Instructors assigned to a course |
| GET | `/api/v1/course-offerings/open` | Open offerings for registration |
| GET | `/api/v1/course-offerings/{id}/details` | Offering details + registered count |
| GET | `/api/v1/course-offerings/{id}/students` | Registered students |
| GET | `/api/v1/course-offerings/{id}/capacity` | Seat capacity snapshot |
| GET | `/api/v1/course-offerings/by-semester` | Offerings filtered by term |
| GET | `/api/v1/course-offerings/by-program/{program_id}` | Offerings for a program |
| GET | `/api/v1/course-offerings/{id}/grade-sheet` | Grade sheet for offering |
| GET | `/api/v1/course-offerings/{id}/results-summary` | Pass/fail statistics |

---

## GET /api/v1/academic-years/current

**Purpose:** Return the academic year marked as current (`is_current = true`).

**Request body:** None

**Success response (200):**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "academic_year_id": 1,
    "year_name": "2025-2026",
    "start_date": "2025-09-01",
    "end_date": "2026-08-31",
    "is_current": true,
    "is_active": true
  }
}
```

**Error (404):** No current academic year configured.

**Frontend notes:** Use as default year context for registration and attendance filters.

---

## GET /api/v1/semesters/active

**Purpose:** List active semesters ordered by `semester_order`.

**Query:** `per_page` (optional)

**Success response (200):** Paginated semester list.

---

## POST /api/v1/academic-levels

**Purpose:** Create an academic level (e.g. Year 1, Year 2).

### Request body

```json
{
  "level_code": "L1",
  "level_name": "First Year",
  "level_order": 1,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `level_code` | `required\|string\|max:50\|unique:academic_levels,level_code` — recommended: `required\|string\|max:50\|regex:/^[A-Z0-9_-]+$/` |
| `level_name` | `required\|string\|max:100` — recommended name rule: `required\|string\|min:2\|max:100\|regex:/^[\pL\s-'.]+$/u` |
| `level_order` | `required\|integer\|min:1` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/academic-programs

### Request body

```json
{
  "department_id": 1,
  "program_code": "CS-BSC",
  "program_name": "Computer Science",
  "degree_level": "Bachelor",
  "total_credit_hours": 160,
  "duration_years": 4,
  "description": "BSc program",
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `department_id` | `required\|integer\|exists:departments,department_id` |
| `program_code` | `required\|string\|max:50\|unique:academic_programs,program_code` |
| `program_name` | `required\|string\|max:200` |
| `degree_level` | `required\|string\|max:80` |
| `total_credit_hours` | `required\|integer\|min:0` |
| `duration_years` | `required\|integer\|min:1` |
| `description` | `nullable\|string` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/academic-years

### Request body

```json
{
  "year_name": "2025-2026",
  "start_date": "2025-09-01",
  "end_date": "2026-08-31",
  "is_current": true,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `year_name` | `required\|string\|max:50\|unique:academic_years,year_name` |
| `start_date` | `required\|date` |
| `end_date` | `required\|date\|after_or_equal:start_date` |
| `is_current` | `required\|boolean` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/semesters

### Request body

```json
{
  "semester_code": "FALL",
  "semester_name": "Fall Semester",
  "semester_order": 1,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `semester_code` | `required\|string\|max:50\|unique:semesters,semester_code` |
| `semester_name` | `required\|string\|max:100` |
| `semester_order` | `required\|integer\|min:1` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/colleges

### Request body

```json
{
  "organizational_unit_id": null,
  "college_code": "ENG",
  "college_name": "College of Engineering",
  "description": null,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `organizational_unit_id` | `nullable\|integer\|exists:organizational_units,organizational_unit_id\|unique:colleges,organizational_unit_id` |
| `college_code` | `required\|string\|max:50\|unique:colleges,college_code` |
| `college_name` | `required\|string\|max:200` |
| `description` | `nullable\|string` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/departments

### Request body

```json
{
  "college_id": 1,
  "organizational_unit_id": null,
  "department_code": "CS",
  "department_name": "Computer Science",
  "description": null,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `college_id` | `required\|integer\|exists:colleges,college_id` |
| `organizational_unit_id` | `nullable\|integer\|exists:organizational_units,organizational_unit_id\|unique:departments,organizational_unit_id` |
| `department_code` | `required\|string\|max:50\|unique:departments,department_code` |
| `department_name` | `required\|string\|max:200` |
| `description` | `nullable\|string` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/courses

### Request body

```json
{
  "course_code": "CS101",
  "course_name": "Introduction to Programming",
  "credit_hours": 3,
  "theoretical_hours": 2,
  "practical_hours": 2,
  "description": null,
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `course_code` | `required\|string\|max:50\|unique:courses,course_code` |
| `course_name` | `required\|string\|max:200` |
| `credit_hours` | `required\|integer\|min:1` — contract convention: `integer\|min:0\|max:30` |
| `theoretical_hours` | `nullable\|integer\|min:0` |
| `practical_hours` | `nullable\|integer\|min:0` |
| `description` | `nullable\|string` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/course-offerings

### Request body

```json
{
  "course_id": 1,
  "academic_year_id": 1,
  "semester_id": 1,
  "department_id": 1,
  "academic_program_id": 1,
  "faculty_member_id": 1,
  "capacity": 40,
  "available_seats": 40,
  "status": "open"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `course_id` | `required\|integer\|exists:courses,course_id` |
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `required\|integer\|exists:semesters,semester_id` |
| `department_id` | `nullable\|integer\|exists:departments,department_id` |
| `academic_program_id` | `nullable\|integer\|exists:academic_programs,academic_program_id` |
| `faculty_member_id` | `nullable\|integer\|exists:faculty_members,faculty_member_id` |
| `capacity` | `required\|integer\|min:1` |
| `available_seats` | `required\|integer\|min:0\|lte:capacity` |
| `status` | `required\|string\|max:50` — use `open` for registration-eligible sections |

**Frontend notes:** Registration only accepts offerings with `status = "open"`.

---

## POST /api/v1/program-courses

### Request body

```json
{
  "academic_program_id": 1,
  "course_id": 1,
  "academic_level_id": 1,
  "recommended_semester_id": 1,
  "course_type": "mandatory",
  "is_active": true
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `academic_program_id` | `required\|integer\|exists:academic_programs,academic_program_id` (unique per course) |
| `course_id` | `required\|integer\|exists:courses,course_id` |
| `academic_level_id` | `required\|integer\|exists:academic_levels,academic_level_id` |
| `recommended_semester_id` | `required\|integer\|exists:semesters,semester_id` |
| `course_type` | `required\|string\|max:50\|in:mandatory,elective` |
| `is_active` | `required\|boolean` |

---

## POST /api/v1/course-prerequisites

### Request body

```json
{
  "course_id": 2,
  "prerequisite_course_id": 1,
  "minimum_result_status_id": null
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `course_id` | `required\|integer\|exists:courses,course_id` (unique pair with prerequisite) |
| `prerequisite_course_id` | `required\|integer\|exists:courses,course_id\|different:course_id` |
| `minimum_result_status_id` | `nullable\|integer\|exists:result_statuses,result_status_id` |

---

## GET /api/v1/course-offerings/open

**Purpose:** Paginated list of offerings with `status = open`.

**Query:** `per_page` (optional)

**Success response (200):** Paginated `CourseOfferingResource` collection with nested course, year, semester.

**Frontend notes:** Primary source for course registration pickers.

---

## GET /api/v1/course-offerings/{id}/capacity

**Purpose:** Real-time seat availability for a section.

**Success response (200):**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "capacity": 40,
    "available_seats": 12,
    "registered_count": 28,
    "remaining_seats": 12,
    "occupancy_percentage": 70.0
  }
}
```

---

## GET /api/v1/course-offerings/by-semester

**Purpose:** Filter offerings by academic term.

**Query parameters:**

| Param | Rules |
|-------|-------|
| `academic_year_id` | `required\|integer\|exists:academic_years,academic_year_id` |
| `semester_id` | `required\|integer\|exists:semesters,semester_id` |
| `department_id` | optional, exists |
| `academic_program_id` | optional, exists |
| `status` | optional, string max 50 |
| `per_page` | optional |

---

## GET /api/v1/programs/{id}/study-plan

**Purpose:** Study plan grouped by academic level and recommended semester.

**Success response (200):** Object with nested levels, semesters, and courses (mandatory/elective).

**Frontend notes:** Use for degree audit and registration guidance UI.

---

## GET /api/v1/course-offerings/{id}/grade-sheet

**Query:** `include_inactive` (boolean, default false)

**Purpose:** All registered students with grade columns for an offering.

**Frontend notes:** Used by instructor grade entry screens; see Grades module contract.
