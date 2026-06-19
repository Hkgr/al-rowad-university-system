# Al Rowad University API Overview

Documentation for the React frontend developer consuming the Laravel REST API.

---

## 1. Project Overview

- **Backend:** Laravel API for the Al Rowad University System
- **Frontend:** React SPA will consume this API
- **Authentication:** Laravel Sanctum (Bearer token)
- **Protected routes:** All `/api/v1/*` endpoints require a valid Bearer token
- **Public route:** `POST /api/login` only

The API is organized in phases:

| Phase | Domain |
|-------|--------|
| A | Academic structure + student core |
| B | Courses + course offerings |
| C | Registration logic |
| D | Grades, results, GPA/CGPA |
| E | Attendance + deprivation |

Additional CRUD resources exist under `/api/v1` but are not listed here unless needed for the frontend MVP.

---

## 2. Base URLs

| Purpose | URL |
|---------|-----|
| Backend (Laravel) | `http://127.0.0.1:8000` |
| API base (versioned) | `http://127.0.0.1:8000/api/v1` |
| Login (unversioned) | `http://127.0.0.1:8000/api/login` |
| Current user | `http://127.0.0.1:8000/api/user` |
| Logout | `http://127.0.0.1:8000/api/logout` |

Start the backend:

```bash
php artisan serve
```

---

## 3. Authentication

### Login (public)

```
POST /api/login
```

**Request body:**

```json
{
  "email": "admin@rowad.edu",
  "password": "your-password"
}
```

**Success response:**

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { },
    "token": "1|xxxxxxxx",
    "token_type": "Bearer"
  }
}
```

Store `data.token` in the frontend (e.g. localStorage or secure storage).

### Get current user (protected)

```
GET /api/user
Authorization: Bearer {token}
```

### Logout (protected)

```
POST /api/logout
Authorization: Bearer {token}
```

### Required headers for all protected requests

```
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

Send `Content-Type: application/json` on `POST`, `PUT`, and `PATCH` requests with a JSON body.

---

## 4. Standard API Response Format

### Success

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {}
}
```

- `data` may be an object, array, or paginated structure.
- Paginated list endpoints often return Laravel pagination inside `data` (`data`, `links`, `meta`).

### Error

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

Common HTTP status codes:

| Code | Meaning |
|------|---------|
| 422 | Validation or business rule failure |
| 404 | Resource not found |
| 401 | Missing or invalid token |
| 500 | Unexpected server error |

Business-rule errors (registration, grades, attendance) return clean messages in `message` — not raw SQL errors.

---

## 5. Frontend MVP Modules and Endpoints

All paths below are relative to **`/api/v1`** unless noted.  
Example: `GET /students` → `GET http://127.0.0.1:8000/api/v1/students`

### Auth (unversioned `/api`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/login` | Login and receive token |
| GET | `/api/user` | Current authenticated user |
| POST | `/api/logout` | Revoke current token |

---

### Students

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/students` | List students (paginated) |
| GET | `/students/search?q=` | Search by number, name, email, phone |
| GET | `/students/{id}/profile` | Full student profile |
| GET | `/students/{id}/academic-info` | Program, department, college, level |
| GET | `/students/{id}/registration-summary?academic_year_id=1&semester_id=1` | Registration summary for term |
| GET | `/students/{id}/transcript` | Academic transcript |
| GET | `/students/{id}/gpa?academic_year_id=1&semester_id=1` | Semester GPA |
| GET | `/students/{id}/cgpa` | Cumulative GPA |
| POST | `/students` | Create student |
| PUT | `/students/{id}` | Update student |

---

### Course Offerings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/course-offerings/open` | Open offerings (paginated) |
| GET | `/course-offerings/{id}/details` | Offering details |
| GET | `/course-offerings/{id}/students` | Registered students |
| GET | `/course-offerings/{id}/capacity` | Capacity and seat usage |
| GET | `/course-offerings/by-semester?academic_year_id=1&semester_id=1` | Offerings by term |
| GET | `/course-offerings/by-program/{program_id}` | Offerings by program |

---

### Registration

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/students/{id}/available-courses?academic_year_id=1&semester_id=1` | Eligible open offerings |
| GET | `/students/{id}/registered-hours?academic_year_id=1&semester_id=1` | Credit hours for term |
| GET | `/students/{id}/registration-summary?academic_year_id=1&semester_id=1` | Registration summary |
| POST | `/registrations/register-student` | Register student in offering |
| POST | `/registrations/{id}/drop` | Drop registration |
| POST | `/registrations/{id}/withdraw` | Withdraw registration |

---

### Grades

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/course-offerings/{id}/grade-sheet` | Grade sheet for offering |
| GET | `/registrations/{id}/grades` | Grades for one registration |
| POST | `/registrations/{id}/grades` | Create grades |
| PUT | `/registrations/{id}/grades` | Update grades |
| POST | `/registrations/{id}/calculate-result` | Recalculate result |
| GET | `/course-offerings/{id}/results-summary` | Pass/fail statistics |

**Grade sheet query parameter:**

- `include_inactive=true` — include dropped/withdrawn registrations (default: active only)

---

### Attendance

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/course-offerings/{id}/attendance-sessions` | Sessions for offering |
| POST | `/course-offerings/{id}/attendance-sessions` | Create session |
| GET | `/attendance-sessions/{id}/students` | Students for session roster |
| POST | `/attendance-sessions/{id}/record` | Record attendance |
| GET | `/students/{id}/attendance` | Student attendance history |
| GET | `/students/{id}/absence-percentage?course_offering_id=1` | Absence % for offering |
| GET | `/course-offerings/{id}/deprived-students` | Students above 15% absence |
| POST | `/course-offerings/{id}/apply-deprivation` | Apply deprivation status |

**Attendance history query parameters (optional):**

- `academic_year_id`
- `semester_id`
- `course_offering_id`

---

### Reports

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/students/{id}/transcript` | Full transcript |
| GET | `/students/{id}/gpa?academic_year_id=1&semester_id=1` | Semester GPA report |
| GET | `/students/{id}/cgpa` | Cumulative GPA report |
| GET | `/course-offerings/{id}/results-summary` | Course results summary |
| GET | `/course-offerings/{id}/deprived-students` | Deprivation report |

---

## 6. Example Request Bodies

### Login

```
POST /api/login
```

```json
{
  "email": "registrar@rowad.edu",
  "password": "password"
}
```

---

### Register student in course offering

```
POST /api/v1/registrations/register-student
```

```json
{
  "student_id": 1,
  "course_offering_id": 1,
  "registered_by_user_id": 2,
  "advisor_user_id": 3,
  "registration_date": "2026-06-14"
}
```

Notes:

- `registered_by_user_id` is optional if the authenticated user is the registrar.
- Duplicate registration returns a clean error: `"Student is already registered in this course offering."`

---

### Create grades

```
POST /api/v1/registrations/{id}/grades
```

```json
{
  "theoretical_mark": 45,
  "practical_mark": 35,
  "notes": "Initial grade entry"
}
```

- `theoretical_mark`: 0–60
- `practical_mark`: 0–40
- If grades already exist, use `PUT` instead.

---

### Update grades

```
PUT /api/v1/registrations/{id}/grades
```

```json
{
  "theoretical_mark": 50,
  "practical_mark": 30,
  "notes": "Updated after review"
}
```

---

### Create attendance session

```
POST /api/v1/course-offerings/{id}/attendance-sessions
```

```json
{
  "session_date": "2026-06-14",
  "session_type": "lecture",
  "topic": "Introduction"
}
```

Notes:

- `topic` is stored in the session `notes` field.
- `session_type` may be mapped internally (e.g. `lecture` → `theoretical`) to match database values.

---

### Record attendance

```
POST /api/v1/attendance-sessions/{id}/record
```

**Option A — by status code (recommended):**

```json
{
  "records": [
    {
      "student_course_registration_id": 1,
      "status_code": "present",
      "notes": "On time"
    },
    {
      "student_course_registration_id": 2,
      "status_code": "absent",
      "notes": "Test absence"
    }
  ]
}
```

**Option B — by status ID:**

```json
{
  "records": [
    {
      "student_course_registration_id": 1,
      "attendance_status_id": 1,
      "notes": "Present"
    }
  ]
}
```

**Attendance status codes:**

| Code | Meaning |
|------|---------|
| `present` | Present |
| `absent` | Absent (counts toward deprivation) |
| `excused` | Excused (does not count as absence) |
| `late` | Late (treated as present) |

---

## 7. Academic Business Rules Summary

### Registration

- Duplicate registration for the same student and course offering is **prevented**.
- Use **drop** or **withdraw** — do not delete registrations.
- Registration validates: open offering, available seats, prerequisites, credit-hour limit.
- Default max credit hours: **18** (custom limits may exist per student/term).

### Grading

| Field | Range |
|-------|-------|
| `theoretical_mark` | 0–60 |
| `practical_mark` | 0–40 |
| `final_mark` | `theoretical_mark + practical_mark` (out of 100) |

**Pass conditions (all required):**

- `theoretical_mark >= 15`
- `practical_mark >= 10`
- `final_mark >= 50`

**Letter grades (examples):**

| Grade | Final mark range | Grade points |
|-------|------------------|--------------|
| A+ | 98–100 | 4.00 |
| A | 95–97.99 | 3.75 |
| B | 80–84.99 | 3.00 |
| D | 50–54.99 | 1.50 |
| F | Below 50 or failed component | 0.00 |
| Z | Deprived | Excluded from GPA |
| W | Withdrawn | Excluded from GPA |
| I | Incomplete | Excluded from GPA |

### GPA / CGPA

- **GPA formula:** `sum(grade_points × credit_hours) / sum(credit_hours)`
- **CGPA:** Same formula across all courses; repeated courses use **highest attempt only**.
- **Included:** Passed and failed courses (F = 0.00 points).
- **Excluded:** W (withdrawn), Z (deprived), I (incomplete), dropped/withdrawn registrations.

### Attendance / Deprivation

- **Deprivation threshold:** absence > **15%**
- **Formula:** `absence_percentage = absent_count / total_sessions × 100`
- `excused` absences are **not** counted.
- `late` is treated as **present**.
- Deprivation sets result status to **deprived (Z)** via `apply-deprivation` only.
- Deprived courses are **excluded** from GPA/CGPA.
- Read-only endpoints do **not** auto-apply deprivation.

---

## 8. Frontend Development Recommendation

### Recommended React structure

```
src/
  api/
    client.js              # Axios instance + interceptors (Bearer token)
    authApi.js
    studentsApi.js
    registrationApi.js
    courseOfferingsApi.js
    gradesApi.js
    attendanceApi.js
    reportsApi.js

  controllers/
    authController.js
    studentsController.js
    registrationController.js
    gradesController.js
    attendanceController.js
    reportsController.js

  pages/
    ...                    # UI components only
```

### Layering rules

```
Page  →  Controller  →  API service  →  Laravel endpoint
```

| Layer | Responsibility |
|-------|----------------|
| **Pages** | UI, forms, tables, navigation |
| **Controllers** | Orchestrate calls, map API data to view state, handle errors |
| **API services** | HTTP calls only (Axios via `client.js`) |
| **Laravel API** | Business logic, validation, database |

**Do not** call Axios directly from React pages.  
**Do** call controllers from pages; controllers call API services.

### Example API client setup

```javascript
// src/api/client.js
import axios from 'axios';

const client = axios.create({
  baseURL: 'http://127.0.0.1:8000/api/v1',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

client.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default client;
```

```javascript
// src/api/authApi.js — login uses /api, not /api/v1
import axios from 'axios';

export const login = (email, password) =>
  axios.post('http://127.0.0.1:8000/api/login', { email, password });
```

### Error handling tip

Always check `response.data.success`. On failure, show `response.data.message` and field errors from `response.data.errors`.

```javascript
if (!response.data.success) {
  throw new Error(response.data.message);
}
return response.data.data;
```

---

## Quick Reference: MVP Endpoint Checklist

- [ ] Auth: login, user, logout
- [ ] Students: list, search, profile, CRUD
- [ ] Course offerings: open, details, by-semester, by-program
- [ ] Registration: available courses, register, drop, withdraw, summary
- [ ] Grades: grade sheet, create/update grades, calculate result
- [ ] Attendance: sessions, record, absence %, deprivation
- [ ] Reports: transcript, GPA, CGPA, results summary

---

*Last updated for Phases A–E. For full CRUD resource list, run `php artisan route:list --path=api`.*
