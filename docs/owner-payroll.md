# University owner portal — Home and Payroll (phase 1)

Arabic RTL portal for the university owner: **الرئيسية** (Home) and **الرواتب** (Payroll). Phase 1 is **one current payroll working sheet**; there are no months, periods, approvals, closing, payments or history (see "Deferred").

Base: `origin/develop` `ffcda5d` (PR #154), built in the dedicated worktree `/home/user/wt-owner-payroll` on branch `codex/university-owner-payroll`. No other working directory, branch or database was touched.

## 1. Access and permissions

| Item | Code |
|---|---|
| Module | `owner_portal` |
| Role | `university_owner` — «مالك الجامعة» (system role, **assigned to nobody by default**) |
| Permissions (all mapped to `university_owner` only) | `owner_portal.access`, `owner_portal.home.view`, `owner_payroll.view`, `owner_payroll.employees.manage`, `owner_payroll.bodies.manage`, `owner_payroll.amounts.edit`, `owner_payroll.export` |

Server rule (`App\Support\OwnerPortal`, middleware `RequireOwnerPortal`, every `/api/v1/owner/*` route):

- allowed if the account is the **central administrator** (active `super_admin`, existing behaviour unchanged), **or**
- the account is active, holds an **active `university_owner` assignment**, and **that role itself** grants `owner_portal.access` plus the route's permission.
- Anything else is 403 (`owner_portal_forbidden`). An owner permission mapped to another role (for example by mistake) does **not** open the portal. The university president, both vice presidents, HR, technical team, deans and the ministry observer have no owner permission and no access; tests assert this for each.
- Payroll access is **not** derived from the president, vice presidents or any organizational placement. The president is not assumed to be the owner.

React mirrors this (`ACCESS.ownerPortal/ownerHome/ownerPayroll` in `auth.js`); the server is authoritative. The sidebar has exactly two items. The owner role lands on `/owner`; no other role's landing changed. Shared edits are additive: `auth.js`, `App.jsx`, `adminPortalNav.js` (one link for the administrator), `apiClient.js` (`apiDownload`), `ManualGradeDialog.jsx` (optional `hideCancel`, default unchanged), `routes/api.php`.

### Granting access (authorized administrator)

1. Deploy (section 3) and run `php artisan owner-portal:provision-access`. It creates the module, role and permissions idempotently and **never creates users or assigns the role**. `--check` reports the state without writing. It refuses (no partial change) if the module/role/permissions are inactive, conflict, or an owner permission is mapped to another role.
2. Sign in as `super_admin` → المكتب التقني → الحسابات والصلاحيات → create the owner's account (or choose an existing account) and assign the role «مالك الجامعة». The role is not in `AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES`, so only `super_admin` can assign or revoke it.
3. The owner signs in again (the browser caches identity) and lands on `/owner`.

No production account, credential or assignment is created by this change.

## 2. Isolated data

Tables (Laravel migration `2026_10_08_000000_create_owner_payroll_tables.php`):

| Table | Purpose |
|---|---|
| `payroll_bodies` | Payroll classifications (الهيئات): `name` (unique), `is_active`, `revision` |
| `payroll_employees` | Identity/classification: manual `employee_number` (**string, unique constraint**, separate bigint PK), `full_name`, `job_title`, `payroll_body_id`, `workplace` (`afrin`/`jarablus`/`afrin_jarablus`/`other`), `workplace_other`, `academic_level` (free text, nullable), `revision` |
| `payroll_entries` | Current amounts in **integer USD cents** (`fixed_salary_cents`, `deduction_cents`, `compensation_cents`, NULL = blank), `revision`. One row per employee, created blank |

- Payroll employees are independent records. No foreign key leaves the `payroll_*` tables (a test inspects the schema). `created_by_user_id`/`updated_by_user_id` are plain audit integers, deliberately not foreign keys.
- Creating a payroll employee writes only these tables: no user, teacher, HR employee, appointment, role assignment or personnel record (a test compares row counts of the existing tables). A source-contract test proves no existing application file references the payroll tables, so workforce statistics, reports and workflows cannot include payroll people. Nothing is imported or synchronised from existing records, and the organizational chart was not used to seed anything.
- Identity/classification (`payroll_employees`, `payroll_bodies`) is separate from the financial values (`payroll_entries`), so a later monthly phase can add a period key to the financial table without touching employee records.
- `down()` refuses to drop populated tables.

## 3. Deployment (not executed against production)

1. Back up the database. The production dump of 2026-10-07 was checked: `roles`, `permissions`, `role_permissions`, `system_modules`, `user_roles` have the expected columns, `migrations` exists, and no `payroll_*`/`owner*` object exists.
2. Backend: `composer install` (adds `tecnickcom/tcpdf` 6.11.4, LGPL; `phpoffice/phpspreadsheet` is now a direct requirement at the already-locked 5.9.0). Production PHP is 8.4 (the lockfile requires it).
3. `php artisan migrate --path=database/migrations/2026_10_08_000000_create_owner_payroll_tables.php --force` — only this migration.
4. `php artisan owner-portal:provision-access`.
5. Build/deploy the frontend (`npm ci && npm run build`).
6. Make `storage/app` writable: the first PDF converts the shipped Cairo TTFs into TCPDF font definitions under `storage/app/pdf-fonts` (cached).
7. Assign the role (section 1). Rollback: revoke the assignment; the tables are preserved by design.

## 4. Local setup and verification commands

- Backend tests (in-memory SQLite, no shared database): `cd backend && php artisan test tests/Feature/OwnerPayrollTest.php`. The same file also passes against a real MariaDB: `DB_CONNECTION=mysql DB_HOST=… DB_DATABASE=<scratch db> php artisan test tests/Feature/OwnerPayrollTest.php`.
- Concurrency on a real engine: `tests/Support/owner_payroll_concurrency.php` (independent processes: one winner per revision, one row per employee number, no deadlock for opposite-order batches).
- Frontend logic: `cd frontend && node --test tests/ownerPayrollLogic.test.mjs tests/ownerPortal.test.mjs`.
- Live browser run: `tests/browser/owner-payroll.mjs` (header comment lists the steps; fixture `backend/tests/Support/owner_payroll_browser_fixture.php`, which refuses to run unless the database name says scratch/test/dev and `OWNER_PAYROLL_FIXTURE_CONFIRM=disposable`). It drives real Chromium against the real Laravel API with synthetic data.

## 5. Grid dependency and license

Existing dependencies were inspected first: the project has no grid; `xlsx`/`jspdf` are export helpers. Candidates were checked against their published packages:

| Candidate | Result |
|---|---|
| `react-datasheet-grid` 4.11.6 (MIT) | No column resizing or RTL; rejected |
| `react-data-grid` 7.0.0-beta.61 (MIT) | RTL, resizing, frozen columns, but **no range selection**; rejected |
| `@revolist/revogrid` + `@revolist/react-datagrid` **4.28.0 (MIT)** | RTL, range selection, frozen columns, resizing, keyboard navigation, in-cell editor, clipboard events — **selected** |

Verified in Chromium (not assumed): RTL layout, cell focus, arrow navigation, range selection (keyboard and drag), frozen columns, resizing, horizontal scroll, F2/typing/double-click editing. Findings and how they are handled:

- Tab/Enter order is not RTL-aware → handled in the application: Tab moves to the cell on the **left** (reading direction), Shift+Tab right, Enter down, Shift+Enter up, also while editing.
- Its clipboard plugin is **switched off** (`useClipboard={false}`), autofill and range-edit events are cancelled: copy/paste/clear are implemented in the application so every multi-cell write is validated first (RevoGrid's RTL paste order also differs from Excel's).
- Cancelling its `beforeedit` event leaves its keyboard state stuck, so amounts use a **custom cell editor** (`moneyEditor.js`) that refuses an invalid value where it is typed.
- **Limitation:** a range selection cannot cross the boundary between the frozen columns (employee number, name) and the scrolling columns. Financial ranges (the main use) are unaffected. On phones only the employee number is frozen so the money columns remain reachable.
- Its sorting and column filter are unused: sorting/filtering go to the server so the grid, totals and exports share one order.

Licenses: RevoGrid MIT, TCPDF LGPL-3.0-or-later, PhpSpreadsheet MIT, Cairo font SIL OFL 1.1 (`backend/resources/pdf-fonts/OFL.txt`). The shipped Cairo TTFs come from the `@expo-google-fonts/cairo` 0.4.2 package (Regular, Bold) with one change: the missing *isolated* Arabic presentation-form code points (36 per file) were mapped onto Cairo's own base glyphs (cmap only, no outline change), because TCPDF shapes to those code points and boxes appeared without them.

## 6. Calculation rules

- All amounts USD, integer cents, two decimals, `$` in headings and cells. Persisted values never use binary floating point.
- **Net payable = fixed salary − deduction + compensation**, computed on the server (and recomputed locally for instant feedback). A client-submitted payable is ignored.
- Blank salary → payable blank. Blank deduction/compensation count as 0 but stay blank (NULL) in storage and display. An entered `0` is stored as 0 and displays `$0.00`.
- Invalid values (negative, exponent, more than 2 decimals, > 999,999,999.99, non-numeric) are rejected on client and server. A negative result is shown signed (`-$150.50`, red), never clamped.
- Totals sum the displayed rows. **Total payable sums only rows that have a fixed salary.** Totals follow the active filters across all matching rows (the sheet endpoint returns every match; there is no pagination), and the scope is labelled.
- Pasted Excel formatting (`$1,234.50`, Arabic-Indic digits) is normalised on the client; the server accepts only plain ASCII decimals.

## 7. Saving, undo and conflicts

Each operation (typed edit, paste, clear, undo, redo) is **one atomic request** to `PATCH /api/v1/owner/payroll/amounts`, keyed by employee id and the row's `expected_revision`. Invalid → 422 and nothing written; any stale row → 409 and nothing written. Operations are queued so revisions chain. Failed or conflicting operations halt the queue, keep the entered values visible (marked, never shown as saved) and offer retry / keep-mine-and-resave / use-server-values. A retry whose first attempt actually succeeded is recognised and not treated as a conflict. Undo/redo (Ctrl+Z / Ctrl+Y and buttons) use the same endpoint and rules. Leaving with unsaved edits is guarded.

## 8. Exports

`GET /api/v1/owner/payroll/export/xlsx|pdf` take the same filters and sort as the grid and export **all matching rows in the displayed order**, built from one transaction; totals are summed from those rows. Scope, order, timestamp and "current working sheet, not a monthly record" are printed in the file. The UI waits for pending saves and refuses to export while an edit is failed or conflicting.

- **Excel:** real `.xlsx`, Arabic headings, right-to-left sheet, employee numbers as text, money as numeric cells with USD format, payable `=IF(G6="","",G6-H6+I6)` and `SUM` totals (verified by recalculating in LibreOffice and equal to the grid totals), user text always written as literal strings (quote-prefixed when it starts with `= + - @`).
- **PDF:** TCPDF, embedded Cairo subset, Arabic shaping and RTL, A3 landscape (all ten columns at 9.5 pt), repeating headings and running title/scope on every page, «صفحة X من Y», totals row. The rendered pages were inspected as images.

## 9. Visual references (frontend/AGENTS.md)

Reused rendered components: `ministry-portal/components/MinistryUi.jsx` (`PageHeader`, `StatCard`, `Section`, `Notice`, `StatePanel`), `components/table/FilterBar.jsx`, the `DataTable` header look (dark band, white bold labels, reproduced in `payrollGrid.css`), `exam-board/components/ManualGradeDialog.jsx`, `hr-dashboard/pages/AddEmployeePage.jsx` field styling, and `DashboardLayout`. Compared with `docs/images/ministry-portal/desktop-01-home.png`. Screenshots (synthetic data, live backend) are in `docs/images/owner-payroll/`.

## 10. Deferred (not implemented)

Month/period selection, payroll periods, approval, closing, payment execution, historical monthly snapshots and carry-forward. The sheet is labelled as the current working sheet everywhere and is never presented as a historical record.

## 11. Known limitations

- The frozen/scrolling column boundary stops range selection (section 5).
- RevoGrid mounts the cell editor a tick after an edit starts. Typing and an immediate Enter/Tab are handled (the key is held and replayed into the editor, verified in the browser run); F2 followed by Escape within the same instant can leave the editor open until Escape is pressed again.
- The sheet loads all matching rows in one request (fine for hundreds to low thousands of employees).
- Development in this environment used PHP 8.3 (production runs 8.4): the Laravel test client works, while a real HTTP server needed a local `request_parse_body()` shim that is **not** part of the repository.

## 12. Verification record

| Check | Result |
|---|---|
| `OwnerPayrollTest` (20 tests, 530+ assertions) | passes on in-memory SQLite **and** on MariaDB 10.11 (isolated instance) |
| `tests/Support/owner_payroll_concurrency.php` on MariaDB | 7/7: single winner per revision, one row per employee number across 8 processes, opposite-order batches never deadlock |
| Frontend `node --test tests/*.test.mjs` | 373/373 (21 + 7 new). `tests/userGuides.test.mjs` pins the exact dependency list; `@revolist/react-datagrid` was added to it |
| `tests/browser/owner-payroll.mjs` (real Chromium, real API, desktop 1440/1900 and 390 px) | 117/117 |
| `npx eslint` on every changed/new file | clean. Whole-repo `npm run lint` is unchanged at 90 errors / 16 warnings, all in untouched files |
| `vite build` | passes (existing large-chunk advisory) |
| Excel recalculated in LibreOffice; PDF rendered with `pdftoppm` and inspected | totals equal the grid; Arabic shaped, RTL, repeated headings, page numbers |
| Full backend suite | The suite cannot run as one process: `AcademicCalendarPhase5OccurrenceResponseTest` fatals on PHPUnit 12 (`final` method override), which stops the run. Each file was therefore run separately and compared with a clean `origin/develop` worktree: the same 44 files fail or crash there. The **only** difference is `SupplementaryExamEndToEndHardeningContractTest`, a historical branch-wide guard that forbids any `database/migrations` change in a diff; it cannot coexist with this task's required migration (the university-email migration documented the same conflict). It was not weakened. |
