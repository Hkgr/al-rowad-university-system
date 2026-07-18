# Alrowad University System — Frontend

React SPA (plain JS, no TypeScript) for the Al Rowad University administrative system. Talks to the Laravel API in `../backend`.

For a narrative of what was recently built, known bugs, and open threads for the next developer, read **[HANDOFF.md](./HANDOFF.md)** first.

## Stack

- React 19 + Vite 8, plain `.jsx` (no TypeScript — `@types/react*` are dev-only, for editor intellisense)
- React Router 7 (`BrowserRouter`)
- Tailwind CSS 4 (CSS-first config, no `tailwind.config.js` — theme tokens live in `src/styles/global.css` via `@theme`)
- Framer Motion (animations), react-icons (icons)
- jsPDF + html2canvas-pro (PDF export — transcripts, student lists, course tables)
- Node 24 (pinned in `.node-version`)

## Getting started

```bash
npm install
cp .env.example .env   # then set VITE_API_BASE_URL if not using the default
npm run dev
```

- `npm run dev` — Vite dev server. No fixed port is configured, so if the default (5173) is taken, Vite silently moves to the next free port (5174, 5175, ...). **This matters**: the backend's CORS `allowed_origins` (`backend/config/cors.php`) only whitelists `http://localhost:5173`. If your dev server lands on a different port, API calls will fail with a generic "تعذّر الاتصال بالخادم" (can't connect to server) error that looks like a backend outage but is actually CORS silently blocking the request. Free up 5173, or add your port to `allowed_origins` and restart `php artisan serve`.
- `npm run build` — production build.
- `npm run lint` — ESLint (flat config in `eslint.config.js`).

**Note:** `VITE_API_BASE_URL` is only read by a handful of files (`LoginCard.jsx`, `AddStudentPage.jsx`, `GraduatesPage.jsx`). Most pages hardcode `https://rust.alrowaduni.edu.sy/api/v1` directly — see [HANDOFF.md](./HANDOFF.md) for details before assuming the env var controls where the app points.

## Project structure

```
src/
├── app/App.jsx              # router root — all routes defined here, imported by main.jsx
├── main.jsx                 # createRoot entry, imports global.css + App
├── styles/global.css        # Tailwind import, @theme color tokens, all @keyframes animations
├── components/               # shared UI (thin — see HANDOFF.md)
│   ├── layout/DashboardLayout.jsx   # sidebar + topbar shell used by every protected route group
│   └── table/DataTable.jsx, FilterBar.jsx   # generic paginated table + search/filter row
├── services/apiClient.js     # a fetch wrapper — NOT currently used by any page, see HANDOFF.md
├── utils/pdfExport.js        # exportRowsToPdf() — html2canvas-pro + jsPDF
├── hooks/                    # scaffolded, currently empty — feature hooks live in their own feature folder instead
└── features/
    ├── auth/                 # LoginPage + LoginCard (public, unauthenticated)
    ├── student-affairs/      # student CRUD, archive, graduates
    ├── student-dashboard/    # student-facing: transcript, GPA, attendance, registration
    ├── exam-board/           # courses, offerings, course table, grade entry/sheet, approvals, deprivation
    ├── academic-structure/   # colleges, departments, programs
    ├── hr-dashboard/         # employees, faculty, positions
    └── professor-dashboard/  # professor's own offerings, attendance
```

### Feature-folder convention

Each `features/<name>/` has `nav.js` (sidebar config passed into `DashboardLayout`) + `pages/`, and optionally `components/` for widgets shared within that feature (e.g. `exam-board/components/InstructorAssignment.jsx` is used by both `CourseOfferingsPage` and `CourseTablePage`). `professor-dashboard` is the only feature with `hooks/` and `lib/` subfolders (`lib/professorApi.js` + `hooks/useMyOfferings.js`) — a small local API-service pattern that isn't replicated elsewhere.

### Routing & auth

- Route protection (`ProtectedRoute` in `App.jsx`) only checks that a token exists in `localStorage` — it does **not** check role. Any logged-in user can navigate to any dashboard URL by hand; separation between roles (student / professor / exam-board / HR / student-affairs) is enforced by which nav items are shown and which redirect happens after login, not by the router.
- No `AuthContext` exists. Components read `localStorage.getItem('token')` / `JSON.parse(localStorage.getItem('user') || '{}')` directly wherever they need the current user.
- After login (`features/auth/components/LoginCard.jsx`), the redirect target is picked from which ID field is present on `user` (`student_id` → `/student`, `board_member_id` → `/exam-board`, `employee_id` → `/professor` if a matching `faculty_members` row exists, else `/student-affairs`).

See [HANDOFF.md](./HANDOFF.md) for the fuller list of known gaps in this area (no shared auth hook, no role-based guarding, inconsistent API base URL) and why they haven't been addressed yet.

## Backend API reference

`../backend/docs/API_OVERVIEW.md` documents the Laravel API's endpoints, response envelope (`{success, message, data}` / `{success, message, errors}`), auth flow, and business rules (registration limits, grading formulas, GPA/CGPA, attendance/deprivation). Read it before wiring up a new page against a new endpoint.
