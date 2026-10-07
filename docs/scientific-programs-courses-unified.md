# Scientific Programs and Courses

The Scientific VP workspace prepares several course changes and all six graduation requirements before one final save. A successful changed save preserves the curriculum of existing students, then approves and explicitly selects the complete new plan for future students within the same transaction. Opening the page, adding a local preparation row, or changing a local requirement input creates no academic records.

## Scope and existing services

Base: `origin/develop` at `ffcda5db2b3fb70bb208342067d0e14763b447a1`, including merged PR154. The work started from a clean tree. The supplied `alrowad_uni_rust10-7.sql` was a read-only file reference, never imported or queried against production. Its academic rows include legacy programs without saved plan versions, including empty curricula and programs missing requirements. No live production-data verification is claimed.

`AcademicPlanWorkflow` remains the owner of fixation, copy, requirement and membership validation, approval and default selection. `AcademicPlanContext`, admission/registration writers and existing database triggers remain the owners of student assignment and catalog lock compatibility. No GradeService, GPA, graduation formula, admission policy, registration workflow, schema, SQL package, migration or permission definition is introduced.

The small `AcademicRequirementService` change short-circuits equal plan references before probing whether the schema is installed. Unequal references still use the identical installed-schema mismatch guard. This removes a metadata query per curriculum row; it does not alter academic classification.

## Interface and preserved links

The single sidebar and home entry is **البرامج والمواد**, at `/vp/scientific/programs-courses`. It offers scoped college and department choices. Only an actually unambiguous registered general department with a `-GEN` code is abbreviated. A unique program is opened only when the server proves uniqueness across the real parent, not just the visible scoped subset. Missing contexts are reported, not created.

The main table groups the advisory study year and semester and displays course name and code, official hours, classification, type and activity. Search, year and classification filters and pagination affect presentation, not enrollment eligibility. Setup supports existing materials and multiple new materials; new material definitions remain in memory until final save. Graduation requirements remain independent of available elective-course hours, with NULL/missing distinguished from explicit zero.

Additional programs are explicitly selected with their own college/department controls. Each has an independent course allocation and requirements form. A shared new course origin is created once and may be referenced by several selected programs. University/college classification never implies distribution to every program. A newly created program with a saved draft but no current fixed/default plan offers an explicit authorized source choice, including when added to a multi-program setup; no draft is guessed or automatically assigned to students.

The material guide remains independent, with existing course usage locks and permitted text corrections. Program identity, archive/delete and explicit historical-plan operations remain available in the existing interface through program settings (`advanced=1`). Old `/vp/scientific/courses`, `/vp/scientific/programs`, `/vp/scientific/programs/:programId` routes retain their existing access policies. Named program/version links retain that context; a fixed historical version is read-only rather than silently replaced by another draft. Legacy `tab=requirements` links open program settings.

A final confirmation names the selected programs and actual course/requirement differences, and says **تُطبّق على الطلاب الجدد فقط**. Router blocking and beforeunload protect preparations and pending writes. Read responses remain request-key bound through the existing abort/sequence hook. Refreshes do not replace the original preparation baseline. A write with an unknown result keeps its payload and UUID; result inspection or an explicit replay of that same request is available. Writes are never retried automatically or silently rebased to a new revision.

Requirement activity is shown beside each requirement budget. An existing inactive group stays inactive when another course changes; activation requires an explicit choice. The confirmation and history display activity before/after even when required hours remain identical. Missing definitions stay identified as missing during read-only display; new definitions are identified as planned creation during preparation. Both the unified workspace and the existing draft requirements form retain inactive groups and their stored budgets.

Design references: `ScientificProgramsPage.jsx`, `ScientificCoursesPage.jsx`, `CatalogControls.jsx`, `StudentsPage.jsx`, and the existing `DashboardLayout.jsx` shell. The feature reuses the existing table, controls, dialog, notices and pagination, Cairo, RTL, palette and spacing. No global CSS, font, shared design component or PDF generator changed. Current visual acceptance and desktop/mobile screenshot comparisons remain unexecuted because browser bootstrap failed; source inspection and SSR are not substitutes.

## Authorization and API

All four endpoints are within `/api/v1/vice-presidency/scientific/program-management`, with the existing authenticated API middleware and domain guard.

| Endpoint | Authority and behavior |
| --- | --- |
| `GET workspace` | Existing Scientific program view authority and actual academic scope; a named program, optional version, q/year/classification and pagination; no locks or writes |
| `POST plan-changes` | Existing plan manage, approve and assign permissions together, plus all program/course scopes; new origins also require existing course-create authority |
| `GET plan-changes/{requestId}` | Current save permissions, same originating operator, and current scope for every stored target; not-found is not proof of rollback |
| `GET {program}/history` | Existing view authority and actual program scope; paginated immutable plan events and applicable catalog audits; other target labels are filtered to the reader's scope |

For ordinary users the existing guard requires an active Scientific VP role, assigned effective permissions and actual scope. The existing central super_admin bypass is unchanged; no permission, role mapping or scope is created. Related older authorization tests were aligned with that already-present bypass while retaining denial of Dean/Administrative VP identities.

`plan-changes` accepts `request_id` (UUID), the opaque decimal-string catalog `revision`, `confirmed`, `new_courses`, and explicit `targets`. Each target contains its program and named/null legacy source version, the complete proposed memberships, and six requirement inputs plus explicit graduation total. Unknown/nested fields, duplicates and unreferenced new-course keys are rejected. Limits are 50 programs, 50 new origins and 200 memberships per program; paginated reads permit at most 100 rows per page. No selection is truncated.

Each requirement group also accepts optional boolean `is_active`. Omission inherits the actual locked source/group state, including false; it never means activation of an existing group. A newly created definition retains the canonical creation default, explicitly presented by the forms. Nonboolean values are rejected. The same optional activity field is supported by the existing `PUT {program}/versions/{version}/requirements` endpoint without changing its authority. The current approval rule still requires six active, complete, officially validated groups; no exception for an inactive zero-hour group was introduced.

Result fields are `request_id`, `changed`, `scope=new_students_only`, changed target program/version IDs, created-course key/ID mappings and the committed revision. Full before/after values live in the existing LONGTEXT plan event; the existing TEXT activity-log result receipt stores the compact outcome, content digest, actor and all targets. This avoids duplicating large curricula in a bounded audit column.

The compatibility distribution endpoint now requires explicit `academic_program_ids`; callers omitting them receive 422 rather than an implicit institution-wide expansion. The normal course form does not invoke that old automatic-distribution UI. No privilege or fixed-plan protection is weakened.

## Atomicity and history

Authorization precedes the catalog lock and is rechecked inside it. Under that first lock, a matching stored request result is resolved **before** revision comparison. The digest matches content only; the monotonically incremented database epoch remains the revision, detecting edits followed by restoration. Same UUID with different content returns a controlled conflict. An inaccessible stored operation is not returned.

For a new changed request, all affected legacy sources are fixed first through the existing workflow. Original requirement/membership/mapping IDs, NULLs, missing groups, stored graduation hours and calculation policy are retained. All existing students, including soft-deleted students, are assigned to that reference; running academic records retain their real context. Completion applies only to the new copy. Approval and explicit future-student default selection occur before commit. Any origin, validation, audit or approval failure rolls back fixation, assignments, new origins, memberships, approval and outcome receipt together. Other existing writers already enter the same catalog-first transaction boundary.

An unchanged save produces `changed=false` and an operational result receipt only. It creates neither a fixed reference nor a version nor an academic change event. Missing legacy groups represented by unchanged NULL placeholders and inactive definitions remain untouched. Invalid changed curricula still fail canonical validation; they are not automatically repaired.

New workspace events contain immutable before/after membership and requirement values, labels, selected targets and new origin definitions. Existing catalog text corrections now record their before/after values; Unicode is stored without unnecessary escaping and an oversized TEXT audit is rejected atomically instead of truncated. Historical events without those fields show **غير مسجلة**. The history reader supports both the existing nested catalog audit envelope and direct distribution records. Operational no-op receipts do not appear as academic changes.

`requirements_saved` now also records actual requirement snapshots, including activity, for the existing draft-edit path. History can render these requirement-only snapshots and does not describe a draft save as approval/default selection. Workspace publication remains marked separately as `new_students_only`. No historical event or fixed reference is rewritten.

## Requirement activity review correction

The follow-up started from the fetched PR155 head `90b4a1daee007f5e7437715226404ef60ed8170b` on the same branch, with no concurrent commits or local changes to replace. The previous coordinator masked activity differences during comparison, while the shared `saveRequirements()` assigned true to every group when activity was omitted. Consequently, an unrelated course edit could activate an inactive group in the new plan without being represented as a user choice.

The real HTTP regression failed before the fix: a program with an inactive department-elective zero-hour group and an unrelated university-course change returned 200 instead of the required 422. It now fails canonical approval with 422 and proves complete rollback of fixation, assignments, origin creation, memberships, groups, events and the result receipt. Additional HTTP tests prove that a state-only explicit activation with the same hours is a real published delta, preserves the original group ID/state/hours and existing student's assignment, and appears in before/after history. The existing requirements endpoint preserves omitted activity and audits explicit activation. Explicit deactivation still fails final approval and leaves the approved plan intact.

The isolated MariaDB fixture seeds the inactive group before introducing student history. All catalog/plan triggers remain enabled; an initial fixture attempt to change that used legacy group was correctly rejected by `academic_catalog_history_locked`. No protection was disabled to construct the test.

## Executed verification

Checks used existing dependencies only; nothing was installed.

| Check | Actual result |
| --- | --- |
| Targeted Laravel HTTP/SQLite suites | 80 tests passed, 1029 assertions: workspace, plan workflow, program/catalog management, explicit distribution, legacy display |
| Workspace behavior | No-write/eager bounded reads; multi-course empty setup; nonempty incomplete source and unchanged NULL placeholders; archived student assignment; official repeated-attempt transcript/CGPA/progress/graduation and curriculum-eligibility parity; real before/after history; selected-program allocation; rollback; stale/ABA; request replay; scoped/actor-owned result; no-op before/after publication |
| MariaDB 10.11.18 independent processes | Passed on a newly initialized guarded loopback instance: omitted activity on an unrelated course change fails canonical approval and rolls back; explicit activation only affects the new plan; two contending same-request saves return one result/origin/new plan/receipt; two distinct proposals permit one commit and one stale response; initial fixation/admission and later publish/admission races preserve old assignment and atomically assign new students; concurrent catalog edit rejects stale save; actual history projection returns immutable differences. Domain-service execution, not browser integration |
| React SSR with synthetic props | Passed curriculum/empty state, preparation editor, inactive requirement selector, state-only differences with equal hours, requirement-only draft history, nested-origin history and unavailable historical details; not browser interaction/layout or live integration |
| Node tests | 355 dependency-free tests passed; includes existing auth, route, program/catalog tests, preparation/diff/access/uncertainty and explicit activity preservation/serialization contracts |
| Changed PHP syntax | Passed for all changed/new PHP files |
| Source contracts | 38 of 39 passed, including new workspace and existing academic-program/catalog and Phase 1–6 boundaries; one historical calendar repair contract fails its old requirement that the later policy service must be absent |
| Composer | `validate --no-check-publish` and `check-platform-reqs --lock` passed |
| Frontend build | Passed, with the existing large-chunk warning |
| Changed frontend ESLint | Passed |
| Repository-wide ESLint | Still fails: 90 errors and 16 warnings. JSON lint output identifies zero errors/warnings in all modified production frontend files; unrelated files were not modified to hide failures |
| Broader historical PHPUnit probes | Earlier PR155 probes: Phase 3 grade-boundary expectation differs from current canonical GPA (3.5 versus expected 4); Phase 4 weekday provider lacks its argument; Phase 5 discovery fatals because private `seed()` overrides public TestCase `seed()`. Not rerun or claimed passed in this activity follow-up |
| Browser and screenshots | Retried in this follow-up via the permitted in-app browser setup: `codex/sandbox-state-meta: missing field sandboxPolicy` still prevents execution before any tab/server interaction. No alternate browser, mocked screenshot or bypass was used. No new screenshots exist; visual acceptance remains incomplete |

The MariaDB fixture installs the **existing** catalog/plan packages only into a fresh test datadir, seeds synthetic data and explicitly sets test-only readiness. It is not a production clone or a claim that production readiness is complete. Its guard verifies actual engine, port, datadir, database and random marker before each step; the runner shuts down only its verified disposable instance. Generated fixture artifacts are retained in the task-specific temporary directory. Existing services and production `.env` are never used.

Reproduction commands from `backend`: run the six named feature suites, `php tests/Contracts/scientific_program_workspace_contract.php`, and the existing academic-program/catalog contracts. On a machine with the existing matching MariaDB binary, run `tests/mariadb/start-workspace.ps1 -BinaryDirectory <existing-mariadb-bin>`; do not point it at an existing datadir. From `frontend`: `node --test tests/*.test.mjs`, `node tests/browser/scientific-workspace-render.mjs`, `npm run build`, and ESLint over the changed files. `git diff --check` passes. Composer commands above use the lockfile without installation.

## Release and remaining acceptance

This change needs **no new SQL or migration**. It requires the already-installed catalog and academic-plan schemas/triggers and previously assigned permissions to be ready. If absent, the server fails closed; this feature does not install them during GET or choose an unapproved default. Deploy compatible backend code and rebuilt frontend assets using the normal release process, without applying any new schema package for this PR.

Before visual acceptance, restore the permitted browser environment and use isolated Laravel/MariaDB with synthetic data. At desktop and 390px widths, compare the named reference components, exercise empty/incomplete preparation with several materials and six requirements, allocate one shared material independently to two selected programs, cancel navigation with drafts, save once, lose/reconcile a response and replay the same UUID, inspect scoped history, and verify old and future-student assignments. Also inspect authorization loss and explicit historical-version links. No screenshots, browser-state behavior or React-to-Laravel end-to-end acceptance are claimed by the SSR/static tests.

The following actual-browser acceptance checks remain **unexecuted**, not replaced by the HTTP, SQL or SSR evidence:

| Requested scenario | Desktop and 390px acceptance and screenshots |
| --- | --- |
| Reference comparison with existing programs/catalog, controls/table/dialog and Cairo/RTL | Blocked before browser connection; no visual comparison or screenshots |
| Empty program and several staged materials before final save | Blocked; server tests cover the domain operation only |
| Shared material restricted to selected programs, independently allocated | Blocked; HTTP/Node coverage is not React interaction proof |
| Inactive group, explicit activation and equal-hour confirmation/history | Blocked; HTTP/MariaDB and SSR verify data/markup, not browser layout/events |
| History after real publication | Blocked; actual MariaDB history query is verified separately |
| Lost save response, retained preparation, scoped outcome lookup and explicit same-request replay | Blocked; server replay/races and static wiring do not prove browser recovery |

For manual acceptance after the permitted browser is restored: extend the guarded disposable MariaDB **test fixture** with the real authentication and lookup schema needed by the portal, synthetic password/token data and empty/shared-program cases. The current minimal domain fixture is not claimed ready for a complete browser login. Explicitly configure a Laravel test instance for that isolated database (never the project production `.env`), plus a Vite build served with the real local API. Authenticate the synthetic Scientific VP account through Laravel; do not intercept application API calls with canned responses. Run each row above at both widths, capture the reference and feature screens, check that an inactive group's unchanged state fails approval without writes, then explicitly activate it and inspect its unchanged hours in confirmation/history and its old-source state in the database. A backend/domain-service run alone does not satisfy this matrix.

No production connection, deployment, production SQL, automatic student transfer, live university test data or change to official grading/PDF behavior was used. The requested PR remains for review, not merged or deployed.
