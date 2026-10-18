# Scientific academic entity pages

The Scientific VP manages the registered colleges/institutes, departments, programs and courses without leaving the existing office dashboard. The single sidebar entry remains **البرامج والمواد**. Program identity appears first, followed immediately by the advisory curriculum; **إضافة مادة** is the primary action. Several materials and all six requirements can still be staged before one atomic publication for new students only.

Initial base: `origin/develop` at `4822574029178f5e36823f0e25cab2a783e5e059`, including merged PR155. Before delivery, the unpublished local commit was rebased cleanly onto the newer `origin/develop` at `ba7d1a2b0bcd40d3718dc10bcaceebd36c07e849`, preserving merged PR156's owner portal unchanged. Branch: `codex/scientific-academic-entity-pages`; [PR157](https://github.com/Hkgr/al-rowad-university-system/pull/157). No existing work was replaced or force-pushed. No production connection, deployment, new SQL package, migration, dependency-manifest/lock change or permission grant is introduced by this PR. The follow-up installs only the existing locked frontend dependencies in a separate disposable worktree, as requested.

## Pages and actions

All routes below use the existing Scientific VP `DashboardLayout`, Cairo, RTL, tables, filters, controls, dialogs, notices and pagination. These are real React routes backed by scoped Laravel services, not the draft's illustrative transition sections.

| Local route below `/vp/scientific/programs-courses` | Implemented behavior |
| --- | --- |
| `/` and `/colleges`, `/colleges/:entityId` | Search/status/pagination; stored college/institute identity, registered organizational relation, scoped departments, local identity edit and add department |
| `/departments`, `/departments/:entityId` | College/status/search filters; actual parent link, scoped program list, local edit/add program; multiple programs are displayed, never guessed |
| `/programs`, `/programs/:entityId` | College/department/status/search/pagination; name/code/parents/degree/duration/stored graduation budget; curriculum grouped by study year with its advisory semesters; search/year/semester/classification filters; course detail links and compact native row action menus |
| `/courses`, `/courses/:entityId` | Existing catalog filters/counts; actual hours/activity/prerequisites/description, program classifications and allocations, local parent/program/material links, canonical permitted corrections/deletion, explicit program selection for curriculum linking |

There is no fabricated institute type: the database has the existing `colleges` entity. A registered institute is shown under its stored name rather than inferred or created from an organizational drawing.

Program **متطلبات التخرج**, **تعديل البيانات** and **سجل التغييرات** are secondary actions. Requirements/history appear on demand, not as repeated always-open forms or new plan-version pages. Existing fixation/copy/approval/default/transfer/archive/delete operations remain explicit local actions through `ProgramForms`, `ProgramDecision` and the canonical services. Fixed historical plans remain read-only; a material membership link includes its exact saved version instead of choosing another draft.

Course creation in the guide remains independent of program membership. Linking an existing course starts with an explicitly selected program; the program's preparation allows further explicitly selected targets with independent allocations. A shared new source retains one key/origin. Choosing university/college classification does not distribute it to unselected programs.

Old `/vp/scientific/courses`, `/vp/scientific/programs`, `/vp/scientific/programs/:programId` and `?program=…&version=…` links resolve locally. Authorization is checked before a legacy query is interpreted: a catalog-only reader opens the catalog, while an authorized program reader retains the exact requested program, plan and tab even alongside a catalog hint. The old program aliases are redirect-only and accept the existing workspace access union; actual program detail/list pages still require `PROGRAM_ACCESS`, and course pages still require `CATALOG_ACCESS`. No backend authority is changed. `advanced=1` no longer returns the user to the old interface. Direct links and refresh bind to the actual entity ID; list search, selected labels, filters and page are kept in URL state and the validated local return link.

## PR157 routing and conflict recovery

The reviewed page interpreted `program` before testing authority, so a catalog-only or denied identity could be redirected toward a protected program. Executed memory-router regressions reproduced that ordering defect. The corrected branch denies unauthorized identities first and only preserves a program/plan for a program reader. The legacy aliases and guide access metadata use the same redirect policy without weakening the destination guards.

Deletion recovery previously refreshed the read without resetting both state owners: the refreshed `CourseDetails` could lose its rejection state while the page guard stayed blocked. Explicitly selecting **تجاهل تعديلاتي وفتح البيانات الحالية** now adopts the inspected current revision, resets the page guard and remounts `CourseDetails` together. Its deterministic epoch changes only for that explicit recovery, not for ordinary responses. Incidental/loading reads keep the existing details mounted; while a draft/write/conflict is active they do not silently adopt a new baseline. Recovery never sends another delete. A later deliberate edit/save or delete uses the reviewed revision, and ordinary navigation works again.

`tests/components/scientific-academic-entities.mjs` executes real ReactDOM, hooks, events and memory-router transitions against synthetic API responses. Four cases failed on the reviewed source before the fix (catalog query routing, legacy alias routing, denied query routing and the stuck guard). All eight cases pass after the fix, including explicit recovery, no duplicate deletion, updated-revision save, cancelled dirty navigation followed by save, and an incidental baseline refresh retaining the conflict. `tests/support/no-layout-dom.mjs` is a small in-memory React host, not a browser: it has no layout, pixels or accessibility tree. These are component behavior tests, **not** desktop/mobile visual acceptance or live Laravel integration. No test framework or dependency is added.

## Academic safety and write boundaries

`ScientificPlanChangeService`, `AcademicPlanWorkflow`, `AcademicPlanContext`, grade/GPA/requirements/graduation rules and their persisted history are unchanged. Final preparation uses the existing `plan-changes` coordinator: catalog-first lock, actor/scope authorization, receipt lookup before revision comparison, actual-change detection, fixation of existing students' reference, complete new-plan validation, approval/default selection and immutable before/after events in one transaction. No-op, rollback and lost-response UUID/result-inspection behavior remain canonical.

Existing inactive requirement groups stay inactive unless explicitly changed. Their activation appears in confirmation/history even with identical hours. NULL/missing and explicit zero remain distinct; stored graduation budgets are not inferred from courses or replaced by another plan's non-null value. Refreshing a dirty preparation retains its original source, revision and proposed values. A newer server snapshot blocks publication pending explicit review/discard; it is not an automatic rebase. Cancelling one uncommitted course editor discards only that editor after confirmation, not other staged materials.

The shared `AcademicStructureEntityService` reuses current college/department form rules, models, resource authorization, actual DataScope, restrictive relationships and resource audit. Both the old generic CRUD controllers and the new office endpoints use it. The existing catalog control lock is first; a committed structural change explicitly increments its reliable epoch because the existing SQL package has no college/department epoch triggers. Unchanged saves do not increment it or create audit. This closes the HTTP-writer gap without adding a schema revision or SQL trigger. Selected parents/units are scoped before validation can disclose an inaccessible record and revalidated locked. Used parent/organizational relations cannot be moved; text corrections remain available. Deletion remains blocked by known use and restrictive FKs.

Generic CRUD keeps its existing resource permission, rather than requiring a Scientific VP role. Its legacy payloads remain supported; the new Scientific endpoints require a revision. Both participate in the same lock/epoch. No new role grant or generic super-admin policy is introduced.

## Authorization and API

The new endpoints are below `/api/v1/vice-presidency/scientific/program-management/entities`:

| API | Existing authority and scope |
| --- | --- |
| `GET colleges`, `GET colleges/{id}`, `GET departments`, `GET departments/{id}` | Active actual Scientific VP, assigned office access plus `academic_structure.view`, actual academic scope; centralized super-admin bypass unchanged |
| `POST/PATCH/DELETE` those resources | Additionally assigned `academic_structure.manage`; actual ownership of the parent/entity, locked recheck, revision; delete requires explicit confirmation |
| `GET organizational-units` | Scoped safe options. Without existing `organizational_structure.view`, only units already attached to accessible academic entities are listed; this does not grant general organizational-tree read |
| Existing program/catalog/workspace/plan-change/history APIs | Their existing role/operation permissions, source checks and scopes remain mandatory; no new alternative publication endpoint |

Department/program scope may read an ancestor but does not thereby own that college or create sibling departments. Program-only scope does not create sibling programs or edit its department. The server returns capabilities and readable lock reasons; unavailable creation controls are not offered. Out-of-scope target/relationship IDs are rejected. Search/status/pagination are server-bound and allowlisted; new lists use deterministic IDs as tie-breakers and bounded eager reads, not per-row queries.

Student, professor, Dean, Administrative VP and ministry roles do not gain this portal from permissions alone. The existing centralized super-admin bypass works without an extra Scientific role or scope. Existing non-Scientific generic CRUD authority is tested separately and not redirected through the Scientific guard. There are no grade, registration, admission, supplementary, graduation, PDF, email or account-management mutations in this feature.

## Design review and browser limitation

Reference sources: `frontend/src/features/scientific-courses/ScientificCoursesPage.jsx`, `frontend/src/features/scientific-programs/ScientificProgramsPage.jsx`, `frontend/src/features/student-affairs/pages/StudentsPage.jsx`, `CatalogControls.jsx`, `DataTable.jsx`, `FilterBar.jsx`, and `DashboardLayout.jsx` with its current header/footer. Global CSS, fonts, colors, shared chrome and PDF generators were not changed.

The user approved the direction, not complete visual acceptance. [Review draft](https://p.superdesign.dev/draft/cf35cd95-8779-4283-9ea7-59ef3e11e168) version 3 now embeds program content as literal HTML inside the existing shell instead of depending on a custom component slot. The stored document was refetched and checked for curriculum content and both exact official-logo positions. This repairs the identified slot dependency at source level; it does **not** establish that the published preview or application renders correctly in a browser. Draft transition sketches are not used as application pages.

The permitted in-app browser failed before any tab interaction with `codex/sandbox-state-meta: missing field sandboxPolicy`. No alternate browser, browser-safety workaround or fabricated screenshot was used. **There are no new desktop/390px screenshots. React-to-Laravel/MariaDB interaction, visual comparison, keyboard/modal behavior and mobile acceptance remain unexecuted.** React SSR and static contracts are not substitutes.

## Executed verification

The initial verification used pre-existing dependencies and MariaDB binaries. For this follow-up, `npm ci --no-audit --no-fund` successfully installed the current develop lock in a fresh isolated worktree. It reported one cleanup EPERM warning for an optional WASI directory but exited successfully; the native Windows build succeeded. The primary worktree's dependencies, package files and owner-portal code were not replaced or edited.

| Check | Actual evidence |
| --- | --- |
| Targeted Laravel HTTP/SQLite | **96 tests passed, 1161 assertions**: Scientific entities plus existing workspace, plan workflow, program/catalog management, distribution and legacy-display suites. Covers read-only GET, scoped parents/children/units, unauthenticated/inactive/no-scope denial, actual roles/permissions/scope, central super-admin, missing manage, ancestor read versus ownership, multiple programs, actual local CRUD, inactive parents, no-op/validation, reliable generic-writer ABA, restrictive deletion and bounded query counts |
| Follow-up Laravel HTTP/SQLite | Scientific entities and workspace rerun: **36 passed, 548 assertions**. This follow-up changes no PHP/backend files. The entity source contract also passes |
| MariaDB 10.11.18 | Prior feature verification passed on a fresh guarded loopback instance with synthetic rows. Two independent Scientific editors were observed in InnoDB `LOCK WAIT`: exactly one same-preview save committed, one returned `academic_catalog_stale`, one audit persisted. Generic CRUD versus Scientific preview shared the first lock; the stale edit rolled back. Change/reversion was detected. Existing PR155 races/replay, one origin/plan/receipt, admission pinning, inactive-group rollback and explicit future-plan activation were rerun successfully. Not rerun for the frontend-only follow-up; not React/live-browser evidence |
| Node | **407 passed**, rerun in the isolated locked environment; pure routing/access/return/filter-state/year-grouping/preparation/diff tests and explicitly labelled static navigation/draft/replay wiring. No browser interaction claim |
| React component behavior | **8 passed** in both the original and isolated locked environment. Actual ReactDOM/memory-router events with the no-layout host and synthetic API; cases and reproduction are described above |
| React SSR with synthetic props | Passed curriculum/empty state, editor, requirements and history; extended checks assert one year heading with two term sections and native action-menu markup. Not a rendered screenshot or live API test |
| PHP syntax and Composer | All changed/new PHP syntax; `composer validate --no-check-publish`; `composer check-platform-reqs --lock` passed |
| Dependency-free PHP source contracts | 39/40 passed, including the new entity boundary contract. Historical `academic_calendar_schema_compatibility_repair_contract.php` still fails its old assertion that the later Phase 3 policy service must be absent; it was not changed |
| Scoped ESLint | Passed, including after rebase: all modified feature files, App and guide files |
| Frontend build | **Passed in the isolated worktree after locked `npm ci`**, including the unchanged owner portal and datagrid: 953 modules transformed; Cairo WOFF2 assets emitted. The earlier missing-datagrid failure is resolved by preparing the declared environment, not by changing package files, externalizing imports or editing owner code. Existing >500kB chunk/plugin-time warnings remain |
| Full repository ESLint | Still reports 90 errors/16 warnings in unrelated unchanged files; no new feature errors were hidden or rules disabled |
| Diff | `git diff --check` passed |
| Browser, live React integration and screenshots | Unexecuted due to the browser bootstrap restriction above. MariaDB domain-service evidence is not browser or full HTTP integration evidence |

The seven targeted Laravel files were executed separately after rebase and all passed. A broader discovery invocation with `--filter` could not reach them because the unchanged `AcademicCalendarPhase5OccurrenceResponseTest::result()` overrides PHPUnit's final `TestCase::result()` method. This unrelated discovery failure was not repaired or hidden; the full Laravel suite is not claimed to pass.

The MariaDB runner creates a new random temporary datadir, validates engine/host/port/datadir/database/marker, applies the **existing** catalog/plan packages only to that isolated synthetic fixture, and stops only its verified instance. Production `.env`, services, SQL dumps and university data are never used. Fixture columns were expanded to match the production paths exercised, including `ip_address` required by the existing generic audit; an initial fixture deficiency was corrected and the complete check rerun, without weakening the audit assertion.

Reproduction: from `backend`, run the seven named targeted feature suites and the entity source contract. With an already available compatible MariaDB binary, run `tests/mariadb/start-workspace.ps1 -BinaryDirectory <existing-binary-directory>`; never point it at an existing datadir. In a fresh isolated checkout, run `npm ci --no-audit --no-fund` from `frontend`, then `node --test tests/*.test.mjs`, `node --test tests/components/scientific-academic-entities.mjs`, `node tests/browser/scientific-workspace-render.mjs`, `npm run build`, and ESLint over the changed paths. The component harness replaces fetch with synthetic responses and never contacts the production API.

## Remaining acceptance and release

Restore the permitted browser, then use an isolated Laravel/MariaDB fixture with the real authentication/lookup schema and synthetic Scientific VP and unauthorized accounts. The current minimal domain fixture is not a complete browser-login environment. Explicitly set the local API configuration; never use the production fallback or intercept application endpoints with canned responses.

At desktop and 390px, capture the current reference pages and the new lists/details. Exercise college → department → every available program → course, direct URLs/refresh/back with list state, scoped parent editing and local creates; empty/incomplete multi-course preparation; two explicitly selected programs with independent allocation; inactive requirement confirmation/history; fixed historical links; dirty sidebar/back navigation and cancellation; uncertain save/result inspection; authorization cleanup and denied identities. Confirm no navigation or action exits to `/academic-structure/*`.

This PR requires **no additional SQL or migration**. The already-installed PR155 catalog/plan protections and already-assigned operation permissions must be ready; the office management fails closed when they are unavailable. The current declared dependency lock has now been installed and built successfully in isolation, including PR156's datagrid. After review, normal integration requires compatible backend code and rebuilt frontend assets, with local smoke checks before resuming affected writes. No merge, deployment, production SQL or production-data acceptance is performed here. PR157 stays OPEN and unmerged; **visual and live React/Laravel/MariaDB end-to-end acceptance remain pending**, with no new desktop/390px screenshots.
