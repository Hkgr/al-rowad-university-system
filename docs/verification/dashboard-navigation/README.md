# Dashboard navigation order

Base: `origin/develop` at `0af10ea`. Reviewed on 2026-10-27.

This change only rearranges existing frontend navigation entries and their section headings. It preserves every link, label, icon, matching flag and access predicate. No route, authentication, API, page workflow, backend, dependency or database changes. Existing dirty historical-import work in the original checkout was left separate in its original branch.

## Portal inventory and order

| Implemented sidebar | Ordering |
| --- | --- |
| Exam Affairs / admissions (shared sidebar) | Home/calendar; courses, offerings and registration; attendance, manual marks, grade sheets, approval and Incomplete cases; supplementary exams and marks; appeals; both existing report links together; settings; help. |
| HR | Home/calendar; HR workspace, employees, add employee, faculty and positions together; reports; help. |
| Student Affairs | Home before calendar; students; registration; graduates; reports; help. |
| Academic structure | Home/calendar; colleges, departments, programs; reports; help. |
| Professor | Home/calendar; attendance, grades, supplementary exams; reports; help. |
| Student | Home/calendar/email; plan and registration; transcript, GPA and attendance; reports; help. |
| Dean | Home/calendar; students/staff; courses/offerings; registration/supplementary exams; reports; help. |
| Ministry | Home; colleges/courses; students/staff/deans/leadership; reports; help. |
| Technical office | Accounts/email; activity; reports; help. No invented home destination. |
| Administrative VP | Existing 71/72/73 organizational groups retained. Exam office 735 follows the course-to-results sequence, ending with its existing report. Technical office 715 places accounts/email before activity/reports. Other offices and global shortcuts unchanged. |
| Scientific VP | Existing home, organizational groups 81/82, reports and help already follow the intended order; retained. |
| President | Existing home, domain pages, follow-up and reports already follow the intended order; retained. |
| University owner | Existing two-item home/payroll navigation retained. |

`src/app/App.jsx` determines the active configurations. The older unused `dean-portal/nav.js` and `vice-president-portal/nav.js` were not changed. Admissions continues using the existing shared Exam Affairs sidebar and existing filters. Reordering its imports also preserves the derived administrative VP office links.

## References and visual evidence

Reused without modification: `frontend/src/components/layout/DashboardLayout.jsx`, `DashboardHeader.jsx`, `features/vice-presidency/components/AdministrativeNavigation.jsx`, `ScientificNavigation.jsx`, and `styles/global.css`. Section headers, spacing, Cairo, green theme, RTL, collapsed sidebar and mobile drawer remain the existing components/styles.

The permitted in-app browser rendered the actual `DashboardLayout` and actual navigation modules with a synthetic identity and a placeholder outlet. This was frontend-only, without API calls or live Laravel/database integration. Functional destination pages were not exercised. A temporary local fixture and local Vite servers were used; the fixture is not shipped.

Desktop viewport: 1101 × 831. Mobile: 390 × 844. Inspected Exam Affairs and HR, sidebar collapse, mobile opening, vertical scrolling down to reports/settings/help, and the manual-grade-entry link updating the outlet and title. Exam Affairs mobile document width was 390, equal to the viewport. Compared the same shell against a clean baseline. Existing long HR link text remains unchanged and can be clipped by the existing single-line sidebar styling; changing shared styling was outside this ordering change.

| Evidence | Current | Clean develop reference |
| --- | --- | --- |
| Exam Affairs desktop | [Current](exams-desktop.jpg) | [Reference](exams-baseline-desktop.jpg) |
| HR desktop | [Current](hr-desktop.jpg) | [Reference](hr-baseline-desktop.jpg) |
| HR mobile | [Current](hr-mobile.jpg) | [Reference](hr-baseline-mobile.jpg) |
| Exam Affairs mobile | [Top](exams-mobile.jpg), [follow-up/reports/help](exams-mobile-followup.jpg) | Desktop reference above documents the original order. |

Other portal layouts were source/export audited, not visually accepted in a live browser. These screenshots do not establish backend integration or functional-page acceptance.

## Actual verification

- `npm ci --no-audit --no-fund`: installed existing locked dependencies in the isolated worktree; package files unchanged. Node 24.11.0, npm 11.6.1, Vite 8.0.16.
- Changed-file ESLint: passed for all ten changed JavaScript files.
- `npm run build`: passed; existing large-chunk warning remains (main bundle above 500 kB).
- Compared Vite-loaded baseline/current exports across all 13 implemented sidebar configurations: all **150 link appearances** retain their full definitions, including access metadata, icons, URLs, text and `end`. No duplicates within any configuration. The baseline modules came from `git show HEAD`, temporarily loaded beside the originals to preserve import resolution, then removed.
- `node --test tests/administrativeOrganization.test.mjs tests/portalReports.test.mjs tests/userGuides.test.mjs tests/authLandingRoute.test.mjs`: **22 passed, 4 failed**. All four failures reproduced on a clean archive of the same base, including backend files required by the guide checks.
- `node --test tests/components/administrative-organization.mjs`: **6 passed, 1 failed**, identically on the clean base. This executes React/router behavior in a no-layout synthetic DOM, not browser layout.
- `git diff --check`: passed.

Inherited failures left unchanged:

1. Administrative static test expects three `AdministrativeNavigation` uses; current base has four.
2. Guide static test does not recognize the scientific guide item.
3. Guide HR-recruitment target reachability check fails for the administrative VP.
4. Closure-review diagram assertion expects the former scientific closure wording.
5. Administrative component test expects 22 super-admin links; current base renders 51. React also reports the existing missing fragment key warning in `DashboardLayout`.

No new tests, dependencies, permission grants, production connection, deployment or data operations were needed. Deployment, when authorized separately, only requires rebuilding and publishing frontend assets.
