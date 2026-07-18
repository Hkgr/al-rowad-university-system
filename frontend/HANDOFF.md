# Handoff notes

Written for whoever picks up frontend work on this project next. Covers what was recently built, what's known-broken, and open threads that weren't finished. See [README.md](./README.md) for stack/setup/structure basics.

## Recent work (this branch: `develop--frontend-bugfixing-2` → merged into `develop`)

- **Multi-instructor assignment for course offerings** (`features/exam-board/components/InstructorAssignment.jsx`, commits `fdfb6be`, `7fcd0aa`, `5229cd6`): a course offering can now have a نظري (theoretical) instructor and, optionally, a separate عملي (practical) instructor, via the backend's `course_offering_instructors` table (`GET/POST /course-offerings/{id}/instructors`, `PATCH/DELETE /course-offering-instructors/{id}`). The نظري slot maps to `is_primary: true`, and the backend keeps the legacy `course_offerings.faculty_member_id` column in sync with whichever row is primary. `InstructorAssignment` replaced two separate hand-rolled dropdowns (`InstructorSelect` in `CourseOfferingsPage`, `InstructorCell` in `CourseTablePage`) that used to write directly to `faculty_member_id` and didn't support a second instructor. It's wired into both `CourseOfferingsPage.jsx` and `CourseTablePage.jsx`.
- **`DataTable` / `FilterBar`** (`components/table/`): generic paginated table + search/filter row, rolled out across `EmployeesPage`, `ArchivedStudentsPage`, `GraduatesPage`, `StudentsPage`. If you're building a new list page, use these instead of another hand-rolled table.
- **PDF export** (`utils/pdfExport.js`): used on transcripts, archived students, graduates, and course table exports.
- **Professor dashboard**: new feature (`features/professor-dashboard/`) — a professor's own offerings + attendance, gated by whether the logged-in employee has a matching `faculty_members` row (see `findMyFacultyMember` in `lib/professorApi.js`).
- **Course registration / course table**: refactored to support multiple simultaneously-open terms rather than assuming a single current term.

## Open bug: Edit Course (`تعديل المادة`) — still broken, backend-side

`PUT /api/v1/courses/{id}` from the Exam Board → Courses → Edit form returns `500 { success: false, message: "Unexpected error occurred", errors: [] }`.

This was investigated exhaustively on the frontend and backend code (`CourseController`, `UpdateCourseRequest`, `Course` model, `CourseResource`, routes, exception handler chain in `bootstrap/app.php`) with no logic bug found — the payload matches validation rules exactly, and Laravel's generic `Throwable` handler only returns that exact string for a genuine uncaught server exception (never validation or not-found). It's a real runtime error visible only in `storage/logs/laravel.log`.

A backend developer previously claimed this was fixed — **it was not**. Confirmed still broken via Network-tab evidence on an authenticated request:
```
PUT https://rust.alrowaduni.edu.sy/api/v1/courses/1
→ 500 { "success": false, "message": "Unexpected error occurred", "errors": [] }
Wed, 15 Jul 2026 10:06:55 GMT
```
If you pick this up: don't re-derive the frontend/logic investigation above, it's a dead end. Get the backend developer to grep `storage/logs/laravel.log` around a failed request's timestamp for the actual stack trace — that's the only way forward on this one.

**Note on the payload**: `theoretical_hours` + `practical_hours` not summing to `credit_hours` (e.g. 2 + 2 = 4 with `credit_hours: 3`) is **intentional business data**, not a bug — confirmed directly by the university's academic staff ("that's how their system works, not logically but it works"). Don't waste time chasing that as a cause.

## Discussed, not implemented: `instructor_type` schema redesign

There's a pending proposal (from the backend side) to replace `course_offering_instructors.is_primary` (boolean) + `instructor_role` (string) with a single `instructor_type` string column (`'theoretical'` / `'practical'`, matching the vocabulary already used in `attendance_sessions.session_type` and `grade_components.component_type`), plus a unique constraint on `(course_offering_id, instructor_type)`. This would likely be a real fix for ambiguity that can cause constraint-violation exceptions in instructor assignment.

**Do not touch `InstructorAssignment.jsx` for this until you've confirmed the backend has actually shipped the migration + endpoint changes** — as of this writing it was still just a written spec, not deployed code. Verify with the backend developer first.

There's also a separate, unresolved point of confusion: someone on the backend side said to "look for professor and professor assistant in tables" instead of نظري/عملي. `faculty_members.academic_rank` (e.g. `'Assistant Professor'`) is a free-text `varchar(100)` field — a completely different axis from instructor_type/role, and not reliable for filtering (substring-matching "Professor" would wrongly match "Assistant Professor"). If this comes up again, get the backend developer to clarify whether they mean an actual rank-based rule or were just describing the نظري/عملي distinction loosely — don't assume `academic_rank` is meant to drive assignment logic.

## Open: dev server port

Vite has no fixed port configured (see README's "Getting started" section for the CORS implication). At one point during this work, two other unrelated local projects on this machine claimed ports 5173 and 5137, forcing the Alrowad dev server onto 5174+ and breaking CORS. A fix (pin Alrowad to port 5175 in `vite.config.js` + whitelist it in `backend/config/cors.php`) was written and then fully reverted at the request of whoever was driving that session — so **the repo is currently back to the original, unpinned state** (no `server.port` in `vite.config.js`, CORS only allows `localhost:5173`). This will likely recur. If it does, the fix is straightforward (pin a port, whitelist it in CORS) — just confirm with the team which port is actually free before committing to one, since apparently several are already claimed by other local projects on this machine.

## Known rough edges (not bugs, but will slow you down)

- **No shared API client is actually used.** `src/services/apiClient.js` exports a clean `apiRequest()` fetch wrapper (env-based base URL, auth header injection, throws on non-OK) but nothing imports it. Every page instead defines its own local `authHeaders()` (~27 near-identical copies) and calls `fetch()` directly with manual `json.success` unwrapping and try/catch boilerplate. If you're adding a new page, using the existing pattern is the path of least resistance for now, but consolidating onto `apiClient.js` would remove a lot of duplication.
- **API base URL is hardcoded inconsistently across ~30 files** — most pages use `const API = 'https://rust.alrowaduni.edu.sy/api/v1'` directly, ignoring `VITE_API_BASE_URL` entirely. Only `LoginCard.jsx`, `AddStudentPage.jsx`, and `GraduatesPage.jsx` read the env var, and even those don't agree on whether `/v1` is included in the fallback string. Pointing the app at a local backend for development currently means editing multiple files, not just `.env`.
- **No role-based route guarding** — `ProtectedRoute` only checks for a token, not role. See README's Routing & auth section.
- **No `AuthContext`/`useAuth()` hook** — `JSON.parse(localStorage.getItem('user') || '{}')` is duplicated in ~10+ components.
- **No shared low-level UI primitives** (Button, Modal, Input, Card) — forms and buttons are hand-styled per page with duplicated Tailwind class strings (compare `CoursesPage.jsx`, `AddStudentPage.jsx`, `AddEmployeePage.jsx`).
- `src/hooks/` and parts of `src/components/` are scaffolded but effectively unused — don't expect to find shared hooks there; feature-specific hooks live inside their own feature folder (e.g. `professor-dashboard/hooks/useMyOfferings.js`).

## Explicitly not started: visual/animation upgrade

Two externally-supplied component templates (a three.js WebGL "ink" shader background, and a WebGL2 animated hero section with CTAs) were floated as material for a broader visual/animation pass on the site. They were written for a shadcn+TypeScript+Next.js stack, which doesn't match this project, so they'd need adapting rather than dropping in directly. This was discussed but explicitly paused before any code was written or dependencies installed (no `three` package, no new components) — treat it as an idea on the table, not a task in progress.
