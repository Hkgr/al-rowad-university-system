# Legacy curriculum as the first academic plan

## Base and cause

PR #134 was already merged. This correction starts from `a74db73d87e14b4b6dec17a8245ad461eb43f77a` on `develop` and preserves its versioning, authorization, history guards and deployment requirements.

The program detail previously returned saved versions only, and the UI loaded requirements and memberships only after selecting a saved version ID. Existing program-scoped rows with a null version ID were therefore invisible, not absent. In addition, a configuration error in the calculated requirement pools hid the underlying stored definitions.

Visual references: `frontend/src/features/scientific-courses/ScientificCoursesPage.jsx`, `frontend/src/features/scientific-courses/CatalogControls.jsx`, the existing `ScientificProgramsPage.jsx`, and `frontend/src/components/layout/DashboardLayout.jsx`. Their existing controls, typography, shell and colors are retained; no shared styling was changed.

## Read and fixation contracts

- `current_plan` is an additive, read-only projection when a program has **no** saved versions. It reads its actual groups, membership IDs, mappings, classifications and stored graduation total. Its virtual label is «الإصدار الأول — الخطة الحالية», with a null version ID and no approval provenance. No GET creates data or pauses admissions.
- Programs with saved versions retain those versions and numbers; the virtual projection is null. Links from course management carry the exact membership's version ID, or the current legacy context, and open the membership tab. No alternate draft is chosen.
- All six requirement categories render stored definitions and exact linked courses, including inactive definitions, explicit zero, null budgets and missing groups. Calculated pools can be unavailable without hiding source data. Graduation budgets are not reconstructed from course hours. Shared canonical configuration validation reports missing settings without repairing them.
- «تثبيت الخطة الحالية» uses the existing preview and transition endpoints. The preview shows groups, courses, counts, operation references and admission impact. Confirmation rechecks the existing monotonic revision under the common catalog lock. Starting initialization, fixation, archived/current student assignments, operation-context pinning and audit are one transaction.
- Fixation preserves IDs and classifications and records version 1 with `transitional` status and legacy calculation semantics. It neither fabricates approval nor sets a default. New admissions remain paused until a separate approved version is explicitly assigned. Copying this fixed version creates version 2 through the existing action.
- Repeated confirmation is safely rejected with 409 (stale revision or already-fixed state); it creates no duplicate version, assignment or history. Reads never initiate fixation. A failed audit also rolls back starting initialization.

## Executed verification (local synthetic data only)

- Targeted Laravel/SQLite behavior suites: legacy display/fixation, academic-plan workflow, scientific program/course management, course distribution, application contract and supplementary materialization. These include official progress/transcript/GPA/graduation parity, archived students, incomplete/empty programs, original membership IDs, duplicate rejection, stale previews and rollback.
- Independent connections on a marker-guarded disposable MariaDB **10.11.18**: a curriculum edit followed by restoration invalidates fixation; a new student invalidates fixation; fixation wins against a waiting student insert, which is rejected until initialization is complete. Lock waits were observed and exactly one version with complete assignments was checked.
- The historical fixture helper emulates pre-installation rows on this disposable instance only. It temporarily removes the new-program **insert** guard, restores its exact original DDL in `finally`, and only then runs behavior/concurrency/browser tests. Runtime history guards stay installed; a preliminary attempt to edit an already-used group's budget was correctly rejected, so the permitted curriculum-edit race uses an unused legacy program.
- Chrome running the production React build against real loopback Laravel and MariaDB, with a synthetic authenticated identity/transport bridge: real API data (not mocked report payloads), all three tabs before fixation, exact data after fixation, incomplete and empty programs, precise saved-version links, desktop 1440px and mobile 390px screenshots. The scenario makes nine UI API calls and one confirmed write, with no runtime exceptions. Screenshots were inspected against the existing course-management style; mobile captures wait for the existing sidebar animation.
- One intermediate browser run timed out while lock-race tests shared the same database. Tests were rerun sequentially and passed. Review also removed a nested snapshot from the legacy projection: the existing outer detail snapshot owns consistency, so ordinary reads do not enter the transaction helper's writer-lock branch.
- Dependency-free Node suite, PHP source contract, changed-file PHP syntax, scoped ESLint, production build, Composer validation/platform requirements and `git diff --check` were executed. See the PR for final counts. Build retains the existing >500 kB chunk warning.

## Limits and deployment

No production database or dump was inspected, changed or executed. The browser authentication is synthetic; this is local Laravel/MariaDB integration, not verification of live login, production data or deployment. It is not a claim that every application workflow or every browser was tested. The in-app browser bridge was unavailable (`sandboxPolicy missing`); existing local Chrome/CDP was used instead.

**No additional SQL is needed** beyond the academic-plan schema and guards already delivered with PR #134. No schema, migration, seeder, permission, dependency, grade rule or PDF changes are included. Deploy the matching backend and rebuilt frontend together after review. If the PR #134 schema has not yet been installed, follow its documented maintenance/install/verify/code-switch procedure; this correction does not authorize applying SQL on production or running old code against new constraints.

## Reproduction on a disposable test instance

With the existing guarded MariaDB configuration and academic-plan package already installed, run `backend/tests/mariadb/legacy-plan-fixture.php`. Pass its `unused` program ID to `verify-academic-plans.php --legacy-current ID`. Start the existing loopback Laravel test bridge and Vite production preview. Set `PROGRAM_LEGACY_IDS` to the helper's `complete`, `incomplete`, and `empty` IDs and `PROGRAM_TEST_OUTPUT` to a disposable screenshot directory, then run `node tests/browser/legacy-program-plans.mjs` from `frontend`. Generate fresh synthetic legacy fixtures before repeating the mutating scenario.
