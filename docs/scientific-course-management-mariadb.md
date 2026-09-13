# PR #132: isolated MariaDB verification and deadlock correction

## Scope and tested source

Reviewed base: `c2330f98d89b96393eeaa0bf40f8171b0294bb73` on
`codex/scientific-vp-course-management`. Both remote branch and develop were fetched;
the feature remote had no concurrent changes. The corrective commit containing this
report changes one production service only: `AcademicCatalogTransaction`.
The run records the base HEAD and SHA-256 of the actual tested service in
`tested-code.json`; the commit/PR head identifies the complete corrective tree.

No production database, hospital database, application `.env` connection, Windows
database service, deployment, application dependency, UI design, permission or
academic data was changed. No SQL package fix was needed.

## Real engine and isolation

- The read-only **header** of `alrowad_uni_rust13-9.sql` documents
  `10.11.18-MariaDB`. No dump statements/data were executed or imported.
- Service inspection found stopped WAMP MariaDB 11.4.9 and MySQL 8.4.7 services.
  Existing MySQL processes belonged to the hospital task on port 13306: untouched.
  Docker/Podman executables were absent. The initial sandbox service enumeration
  was denied; authorized read-only enumeration resolved this instead of assuming
  no local options existed.
- Downloaded the official portable **MariaDB 10.11.18 winx64** archive:
  [official archive](https://archive.mariadb.org/mariadb-10.11.18/winx64-packages/).
  SHA-256 matched its official `sha256sums.txt`:
  `1e36bb0834718e56fd53e150978b55429e5da921a97c07c16b41086c0c61099c`.
  Portable initialization follows the
  [MariaDB Windows ZIP documentation](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-windows-zip-packages).
- Two new disposable instances were used on **127.0.0.1:23362 and :23363**,
  each with a new OS-temp `pr132-catalog-{UUID}/data` directory. No service was
  registered. The repeatable complete run used 23363.
- Each isolated server contains the package's exact schema name
  `alrowad_uni_rust`. **No schema-name or SQL-logic substitution** was performed.
  Only the client's `DELIMITER` directive is parsed before submitting the original
  SQL statements, stopping on the first exception.
- The `catalog_test` account has explicitly enumerated privileges on that schema
  only, including installer DDL/routine privileges; no global/other-schema grants.
  The temporary root account is used only for initial database/account provisioning
  and observation of this instance's lock/process metadata, not Laravel writes.
- Before package statements, test scenarios, worker connections and each browser
  request, the guard checks the literal loopback configuration, actual engine
  version, hostname, port, datadir, selected database and random task marker.
  Wrong port/version/hostname/marker tests fail closed. Bootstrap checks the new
  task datadir/server before creating its previously absent database/marker.
  Laravel's environment path is redirected to the disposable directory with a
  non-existent test-only environment filename; it does not read the project `.env`.
- Default engine isolation was REPEATABLE READ; read-fence scenarios explicitly
  ran both REPEATABLE READ and READ COMMITTED. Only session settings were used for
  isolation/lock timeout. No global server setting was changed to pass a test.

The fixture is intentionally **synthetic and minimal**, with signed keys,
restrictive relationships and required unique indexes. It derives the required
column inventory from preflight and adds explicit scenario columns/empty dependent
tables. It is not a full production-schema clone and does not prove production
schema compatibility beyond the checked contract.

## SQL package: executed

| Check | Actual result |
| --- | --- |
| Original `00_preflight.sql` on fresh synthetic schema | `OVERALL / READY` |
| First install deliberately interrupted after `sc_cat_courses_u` | `is_ready=0` |
| Direct protected course write during interruption | `academic_catalog_schema_not_ready`, no write |
| Application write boundary during interruption | controlled schema-not-ready failure |
| Resume original `01_apply.sql` | `OVERALL / APPLIED` |
| Original `02_verify.sql` | `OVERALL / PASS` |
| Reapply completed package, then verify | `APPLIED`, `PASS` |
| Before/after academic-table snapshot hash | identical |
| Installed bodies compared with source (whitespace normalized only) | 57 triggers + 5 routines match |

The five routines include the installer guard, touch procedure and three history
functions. Behavior checks exercise these real installed routines/triggers; no
SQLite trigger analogue participates. JSON result sets are retained under the
isolated run directory. The existing package remains unchanged; deployment still
requires a maintenance window, `00_preflight` → `01_apply` (stop on first error)
→ `02_verify`, and successful verification before resuming traffic.

## Concurrency and legacy paths: executed

`verify-catalog.php` records **24 passing scenarios**. It uses independent PHP/PDO
connections. Parent A owns its transaction/lock; worker B acknowledges entry; the
monitor observes B at its blocked locking statement (InnoDB lock metadata where
available, exact connection/process statement otherwise); only then A commits.
Bounded polling observes barriers—it does not assume ordering from random sleeps.

| Scenario / order | Actual outcome |
| --- | --- |
| Course edit before first student; reverse order | edit-first succeeds; student-first rejects academic edit; student persists |
| Curriculum edit before first student; reverse order | same serialization; losing curriculum edit absent |
| Curriculum before first ordinary offering; reverse order | first writer commits; history-first rejects later curriculum edit |
| Curriculum before first supplementary offering; reverse order | same behavior with actual supplementary offering trigger |
| Offering changes to destination program before curriculum edit | destination curriculum edit rejected |
| Two independent creates using the same course code | one row; controlled validation loser |
| Opposite prerequisite edges | canonical acyclic check rejects loser; exactly one edge remains |
| Multiwrite course + audit followed by invalid relationship | entire operation rolls back |
| Lock timeout | controlled 409-equivalent conflict; no write/retry |
| Forced row-first legacy writer vs catalog-first writer deadlock | exactly one winner; victim and its provisional audit roll back |
| List/detail read while another connection commits, under both isolation levels | stale response rejected; writer commits while read remains open |
| Ordinary reads holding control-row write lock? | disproved by that concurrent writer completing inside read callback |
| Old Course CRUD ABA | editor's old revision rejected even after original value restored |
| Old Course/AcademicProgram/ProgramCourse/CourseDepartment/CoursePrerequisite HTTP | allowed operations succeed; used academic/relation operations reject |
| Direct academic/relation mutations on used records | real trigger rejects; text correction remains allowed |
| Canonical offering service create / stale create and destination proof | fresh CLOSED create succeeds; stale proofs reject |
| Real generic offering HTTP POST and identity PUT | succeed through current controller/services with real triggers |

These tests validate specific schedules, not exhaustive interleavings or sustained
production throughput. Supplementary first-use coverage targets its persistence
trigger, not an end-to-end supplementary examination lifecycle.

## Proven defect and focused repair

**Before:** the forced nested deadlock threw `Illuminate\Database\DeadlockException`
with SQL/connection details. Laravel wraps nested transaction deadlocks in this
PDO-derived class, which is **not** a `QueryException`; the existing catch missed it.

**After:** `AcademicCatalogTransaction` catches both types and maps the wrapper to
the existing `academic_catalog_stale` controlled conflict. No retry was added, no
lock order changed and no history rule relaxed. The identical real deadlock test
passes, including the losing transaction's provisional audit rollback. A separate
SQLite PHPUnit exception-mapping test verifies 409, safe message, rollback and one
attempt; that simulation is not presented as InnoDB evidence.

The other early failures were harness issues (scenario ID reuse, observing only
InnoDB metadata while MariaDB waited in optimizer statistics, and an incomplete
  empty faculty fixture, and browser selectors assuming the desired option was
  on lookup page one after repeated fixture runs). They were corrected in test tooling, not in application
authorization or SQL protection.

## Browser and additional checks

- **5 recorded browser → real Laravel → MariaDB scenarios passed**: correct used
  course name with locked academic fields; independent course creation; explicit
  link to unused program without budget mutation; unlink/relink without duplicate
  course/budget change; real server 409 retains the draft for explicit inspection.
- Built React ran in an isolated Chrome profile. Catalog responses/writes were
  forwarded to real Laravel, not mocked. The shell identity and Sanctum test login
  were synthetic; production login and operator provisioning were not exercised.
- Desktop/mobile screenshots were captured; used-course desktop and conflict mobile
  captures were visually inspected. This follow-up does not claim a new design
  acceptance audit. The in-app browser bridge failed; the existing standalone CDP
  test driver was used. Its misleading hardcoded `Laravel/SQLite` output label was
  replaced with an engine-neutral fixture label.
- **42 PHPUnit tests / 298 assertions passed** (SQLite behavioral/HTTP regression
  suites), **22 Node tests passed** (static/pure logic), scientific SQL/application
  source contract passed, PHP syntax and PowerShell parser passed.
- Vite production build passed (816 modules; existing large-bundle warning).
  ESLint for the changed browser driver passed; Composer validate/platform checks
  passed; `git diff --check` passed. No dependencies were installed.
- Full repository lint and unrelated PHPUnit suites were not rerun in this focused
  follow-up. Earlier unrelated lint/contract failures remain documented in the
  previous report; no assertions or lint rules were suppressed.

Machine-readable outcomes: [MariaDB results](scientific-course-management-mariadb-results.json).
Captured fixture screens: [used-course desktop](images/scientific-course-management/mariadb/used-course-desktop.png)
and [retained conflict draft on mobile](images/scientific-course-management/mariadb/conflict-mobile.png).

## Reproduce (Windows, existing PHP/PDO MySQL + backend/vendor + Node/Chrome)

1. Download/verify/extract the official 10.11.18 ZIP into a temporary directory.
   Do not point the following script at any existing data directory or service.

```powershell
& backend/tests/mariadb/start-catalog.ps1 -MariaDbHome '<temporary extracted MariaDB directory>' -Port 23363
# Copy only the generated path printed by the script; never commit connection.json.
$env:SCIENTIFIC_MARIADB_CONFIG = '<printed new temp path>/connection.json'
php backend/tests/mariadb/catalog.php init
php backend/tests/mariadb/catalog.php package-test
php backend/tests/mariadb/verify-catalog.php
```

2. Inspect the exit codes (PowerShell native stderr handling can itself mark a
   command failed; inspect `$LASTEXITCODE`, the JSON results and stderr separately).
   Package/test failure is not a PASS. `init` refuses an existing database.
   Optional `CATALOG_SCENARIO` limits named tests for investigation; unset it for
   complete verification. The tools write `verification.json`, `tested-code.json`,
   SQL result sets and worker diagnostics only to temporary test locations.

3. For browser checks, leave that instance running. Start
   `php -S 127.0.0.1:8185 backend/tests/mariadb/browser-server.php` with the same
   config variable; build/preview frontend on port 4173. Start a new isolated
   Chrome profile with remote debugging 9225 and a fresh `about:blank` tab.
   Set `CATALOG_DEBUG_URL=http://127.0.0.1:9225`,
   `CATALOG_LARAVEL_URL=http://127.0.0.1:8185`, and `CATALOG_TEST_OUTPUT` to a new
   temporary artifact directory, then run
   `node frontend/tests/browser/scientific-course-management.mjs`.
   Do not run mutating CLI scenarios concurrently with browser fixture assertions.

4. Run `php backend/tests/mariadb/catalog.php shutdown` with that instance's config:
   it verifies the marker/actual server again before stopping that server only.
   Stop the test PHP/preview processes.
   Do not stop or delete another service/data directory. Temporary synthetic data
   and credentials must remain outside Git; retained evidence contains no real PII.

## Remaining boundaries

No production execution, deployment rehearsal against a full restored schema,
production account provisioning, replication/binlog configuration matrix or load
test was performed. The tested version matches the supplied dump header, not a
live production connection. Adding curriculum membership to a program with student
history remains deliberately unavailable; standalone course creation and permitted
name/description corrections remain available. No academic-plan version workflow
was introduced. PR #132 remains open and unmerged; these checks are not a claim of
unconditional production/merge readiness.
