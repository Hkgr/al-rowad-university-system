# Student Affairs disciplinary interface

Base: `b73a0394269dab7fa87149b7c0b77afad1b28590` (develop after PR #192).
Branch: `codex/student-disciplinary-interface`. No merge or deployment performed.

## Scope and terminology

Expose the existing PR #63 disciplinary module inside Student Affairs and the
administrative VP portal, using the existing service and portal components.
The user approved **الإنذارات والعقوبات** for the student-profile tab. This is the
disciplinary record; no GPA-warning policy or automatic warning generation is
introduced. No academic calculation, zero/restore algorithm, schema, migration,
permission code, dependency or production data change is included.

Routes:

- `/student-affairs/disciplinary-cases`
- `/vp/administrative/student-affairs/disciplinary-cases`
- `/student-affairs/students/:id?tab=discipline` and its administrative counterpart

The sidebar entry is under **الحياة الجامعية** in Student Affairs and existing
office **733** in the administrative hierarchy. All links use the current office
navigation helper. No organizational-unit records are created.

## Interface

- Search by number/name, status filter and server pagination; URL state survives
  detail opening/closing and keeps existing profile/year filters.
- Details show the stored violation/penalty, dates, issuing authority, decision
  reference, investigation status, affected-course history and appeals/results.
  Missing values remain explicitly unrecorded; no dates or evidence are invented.
- Authorized operators can record an issued penalty, an appeal and its issued
  outcome through the existing POST services. Cascade/restore effects are clearly
  explained before the corresponding operation. No delete or generic edit UI.
- The profile tab appears directly after the existing transcript tab. Tabs now
  reflect the URL, and the selected tab scrolls into view on narrow screens.
- Editing blocks navigation/closing without explicit discard. During submission
  controls are locked synchronously. Invalid or lost acknowledgements retain the
  exact proposal and disable resubmission; recovery reads do not assert success.
- Archived students remain represented in history. Their names are shown as
  archived rather than linked to the existing active-only profile route. The
  new-record student picker searches non-archived students.

Reference components actually inspected: `StudentProfilePage.jsx` and its existing
header/tabs, `StudentsPage.jsx`, `DashboardLayout.jsx`, `DataTable.jsx`,
`FilterBar.jsx`, `GovernanceUi.jsx`, and `AdministrativeNavigation.jsx`. These
components and Cairo/RTL/theme styles are reused, without shared-style changes.

## Server access and existing-service integration

`StudentDisciplineAccess` requires active accounts, factual operator roles and
valid academic scope. Existing `students.view` permits reading; recording also
requires existing `students.manage` and the student's actual mutation scope.
An administrative VP with university scope uses their existing grants without
a registration-officer role. Super-admin authority stays central. Student-self
and instructor-derived scope do not grant disciplinary access.

The old generic resource fallback could not supply a scoped usable list, and
custom legacy writes lacked explicit module/target authorization. The controllers
now check both, including direct appeal routes, before calling the existing
`DisciplinaryCaseService`. Catalog-first transactions and student/case/appeal locks
surround target revalidation. Already-decided appeals return 409 rather than
overwriting their outcome. The record of the issuing authority remains separate
from the user recording the decision.

Read projections expose minimal student identity rather than raw student/account
models. Broad lists require a scoped detail before offering target-specific
actions. Eager loading and request-only presentation capabilities avoid repeated
per-row authorization queries; write authorization never uses that presentation
cache. Missing existing tables return 503, never a false empty record.

## Verification performed (2026-10-27)

Runtime: PHP **8.4.14**, Laravel **13.15.0**, PHPUnit **12.5.30**, SQLite **3.49.2**; Node **24.11.0**,
npm **11.6.1**, Vite **8.0.16**. Existing lockfile dependencies were installed in
this isolated checkout without changing dependency manifests or lockfiles.

| Check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit tests/Feature/StudentDisciplineInterfaceTest.php` | 12 passed, 76 assertions. Actual HTTP middleware, real user/role/permission/scope records, isolated SQLite. |
| `node --test tests/studentDiscipline.test.mjs tests/components/student-discipline.mjs` | 8 passed. Real React/router components in the existing synthetic no-layout host, plus contract/access helpers. |
| Existing Node navigation/reports/guide/landing suites plus the focused tests | 34 total: 30 passed, 4 inherited failures reproduced on clean base. |
| Existing `DataStatusPhase2Test` | 12 total: 4 passed, 6 failures, 2 errors, reproduced on the clean backend base with the same locked dependencies. |
| New/other changed JS lint | Passed. |
| Existing `StudentProfilePage.jsx` lint | Two inherited unused-variable diagnostics (`ac`, `year`), reproduced on the clean base; not suppressed or fixed out of scope. |
| PHP syntax and changed-file Pint | Passed. |
| Composer validate / platform requirements | Passed. Composer retained existing PSR-4 warnings for three unrelated MariaDB fixture classes. |
| Frontend production build | Passed; existing large-chunk warning remains. |
| `git diff --check` | Passed. |

HTTP coverage includes scope/role/permission denials, inactive/future VP grants,
the administrator bypass, archived history, minimal person data, nullable search,
invalid arrays/statuses, non-writing reads, query-count equality for one versus
fourteen visible cases, case creation, appeal/decision recording, rejection of
overwrites, cascade-prerequisite rollback and preservation of official result
content/announcement timestamp. UI tests cover both portal paths, profile-tab
placement/target, context preservation, denial cleanup, double submission and
invalid acknowledgement recovery without another POST.

Inherited backend diagnostics are missing `permissions`/`user_access_scopes` in
old isolated fixtures and one transcript-fixture array assertion. The clean
baseline backend tree is identical (`72a707d2d1751159d68491b1ed3781c2f4b936c6`) to
develop's pre-change backend. Existing Node failures concern the stale count of
AdministrativeNavigation usages, scientific guide detection, the administrative
HR guide target, and former closure-review wording. No old tests were changed.

## Actual browser integration

The permitted in-app browser used real React routes, Laravel HTTP endpoints,
Sanctum tokens, real permission/scope records and a private synthetic SQLite
database. Application APIs were **not intercepted with fixed responses**. The
local Vite proxy only forwarded `/api` to the local Laravel server. A localhost
test-session helper seeded browser storage with synthetic tokens; credentials
and the SQLite file remained outside Git.

Observed: student-profile tab next to transcript; case creation/reopening; appeal
submission and rejected outcome; search; administrative-office grouping; local
profile links in the VP portal; mobile tab selection/form; read-only and
out-of-scope handling. Desktop viewport **1280 × 720**, mobile **390 × 844**.
On mobile the document width was 375 (within 390), and dialog width 343. The
existing table scrolls horizontally inside its container. The browser's native
date keyboard/picker input was used after its programmatic date fill did not
update React; the resulting 422 kept the proposal and created no case, followed
by one successful case registration.

The limited synthetic staff account intentionally lacks grade/attendance and
lookup permissions. The existing profile's background academic/document calls
therefore produce expected 403 responses; initially an absent synthetic document
table also produced a fixture-only error. These are not acceptance of transcript,
GPA, attendance or document workflows. New disciplinary endpoints and the profile
read used by this task were exercised against Laravel.

Final browser-fixture state: four cases (three seeded plus one created), one
appeal with a recorded rejection, and the complete synthetic official-result row
exactly unchanged, including its `81.50` mark and announcement timestamp. No
source, bundle or real student data was used.

Screenshots: [profile desktop](verification/student-discipline/student-profile-desktop.jpg),
[case detail](verification/student-discipline/case-details-desktop.jpg),
[VP list](verification/student-discipline/vp-list-desktop.jpg),
[VP profile mobile](verification/student-discipline/vp-student-profile-mobile.jpg),
[mobile form](verification/student-discipline/case-form-mobile.jpg),
[out-of-scope reader](verification/student-discipline/out-of-scope-reader.jpg),
[existing profile reference](verification/student-discipline/profile-reference-desktop.jpg).

No MariaDB concurrency, production schema or production data was verified. SQLite
does not establish MySQL/MariaDB locking behavior. The isolated tests exercise
the existing fallback catalog transaction path, not an installed production
catalog schema. Full cascading grade restoration
was not visually exercised; its service/writers were not changed.

## Local reproduction and release

Focused checks above use the test-only fixture. To prepare a browser database,
invoke from `backend` (paths must be private and outside Git):

```powershell
php tests/browser/prepare-student-discipline.php D:/TEMP/student-discipline-demo.sqlite D:/TEMP/student-discipline-credentials.json
```

This script refuses non-SQLite/non-testing targets and non-prefixed filenames.
It replaces only that disposable synthetic database; never point it at real
university files. Run Laravel locally with `APP_ENV=testing`, the same explicit
SQLite path, an ephemeral local APP_KEY and a frontend API base/proxy targeting
that local server. Never publish the test-session helper or synthetic credentials.

When separately authorized, release the backend controller/support/resource
changes and rebuilt frontend assets together. There is **no new migration, SQL
package, permission registration or production data command**. Existing
disciplinary tables must already be installed through the university's approved
process; this feature fails closed with 503 if they are missing. Do not run the
old documentary migrations as part of this interface release.
