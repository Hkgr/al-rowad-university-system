# University email Phase 1 setup and verification

This feature prepares local student email drafts in the Technical Office. It does not create Mailcow mailboxes, generate passwords, deliver credentials, or link pre-existing mailboxes. It does not change personal student email, student academic data, or university login accounts. GET requests never create drafts.

## Sources and authorization

The implementation was based on develop `9fd9f61a0b6a79e5912af8f6b1027238311992ef`. The actual `Student`, `User`, `Permission`, `SystemModule`, `UserActivityLog`, `DataScopeService` and account-administration services were inspected. The repository schema defines `students.student_id` and `users.user_id` as signed INT, and the RBAC module table is `system_modules`.

All four APIs require an active account, an effective `technical_team` role and **assigned** `technical_portal.access`. Virtual super-admin permissions are not enough. A multi-role account must meet the same assigned technical requirements.

| API under `/api/v1/technical/university-email` | Additional assigned permissions | Scope |
| --- | --- | --- |
| GET `students` | `university_email.view` | Existing explicit academic student scopes |
| GET `students/{student}` | `university_email.view` | Same scoped student query |
| PUT `students/{student}/draft` | `university_email.view`, `university_email.manage` | Rechecked after locking student |
| GET `connection` | `university_email.check_connection` | Fixed student domain only, throttled |

The student query reuses `DataScopeService::scopeManualGradeStudents`, which is an existing explicit-scope student projection: university/PRES, college, department, program or section. It supplies neither virtual super-admin scope nor faculty-assignment authority. No new scope is automatically assigned. Existing operators need a valid assigned scope using the current account-management process; without one, search returns no students and direct student access is denied. Student login accounts are not required for search.

### Search regression repair in PR 146

The remote branch was fetched and checked against reviewed HEAD `a2007db7d86d1b20519b3bcaf0cdf9d75e25861e`; there were no later commits or existing worktree edits. Before the fix, two separate HTTP regressions reproduced 422 for `q=` and `q=%20%20%20` through the actual Laravel middleware. `TrimStrings` and `ConvertEmptyStringsToNull` normalized blank search to null, but the controller required a string. The server now accepts `sometimes|nullable|string|max:120`; the client omits a blank query. Missing, empty and whitespace-only search list only the operator's scoped students. Arrays, overlong strings, unknown keys and invalid pagination remain rejected.

Search adds `email_schema_ready` and a limited `email_preparation` object to each row: availability, saved address, provisioning state and handover state. One bounded local query loads email summaries for the paginated student IDs. No student-specific Mailcow requests occur. A ready table with no draft displays «لم تُجهّز»; a saved draft displays «مسودة محفوظة» with its saved address, separately from delivery. These are local records, not verification of a Mailcow mailbox. When the migration is absent, search still works but explicitly reports unavailable preparation data, not an invented unprepared state. Detail/save retain the controlled schema-not-ready 503.

The confirmed save response contains the same summary and updates only that student's visible row, preserving other rows and pagination. A failed or uncertain save does not optimistically update the summary or retry. This repair adds no migration, permission, provisioning, password or printing functionality.

The permission-provisioning command creates/maps only the three new permission codes to the existing active `technical_team` role in the active `users_permissions` module. It does not grant portal access, create roles/users/scopes, grant account-management permissions, or change any other mappings. Inactive/conflicting definitions fail without partial changes. Run it only as an explicit deployment step, not via a full seeder.

## Draft storage and conflicts

Migration `2026_10_01_000000_create_student_university_emails.php` adds one independent table with restrictive signed student/operator FKs, unique student and email-address constraints, a monotonic `revision`, and separate provisioning and handover states. Its `up()` does not alter/backfill existing student/user tables. Its `down()` refuses to drop populated storage.

Each save locks the student then its email draft, validates the submitted revision (`0` for initial creation), derives the address from the locked student number, and commits the draft and one safe `UserActivityLog` record atomically. Only IDs and revision are recorded; no secret, password, raw Mailcow body, name or address is in the description. A no-op does not increment revision or write an audit record. Returning to an earlier name still advances revision and invalidates stale editors.

Only trimmed/lowercase ASCII English letters are accepted for the name. Composite names are joined, such as `abdulrahman`. The server constructs `ahmad.r24011002@alrowaduni.edu.sy` from `Ahmad` and stored `R24011002`, validates the 64-byte local-part and 254-byte address limits, and rejects unknown payload fields (including client-supplied number/domain/quota/status). It never truncates values. Reading after a student update does not regenerate an existing address.

All Phase 1 saves remain `draft` / `not_delivered` with 50 MiB; `created` / `delivered` records cannot be edited here. The UI retains its proposal and original revision after a conflict or lost write response, blocks another save until an explicit server review, and requires an explicit discard/rebase choice. It never automatically retries a write. SPA navigation, student changes and unload are guarded while drafts or writes are pending. Losing authorization clears sensitive state.

## Mailcow read contract

The adapter performs only `GET /api/v1/get/domain/{configured-domain}`, with `X-API-Key`, HTTPS/TLS verification, a 5-second connection timeout, a 15-second total timeout and redirects disabled. Server URL/path cannot be supplied by the client. Configured URLs containing user information, query/fragment or an extra path are rejected.

The official [Mailcow OpenAPI](https://github.com/mailcow/mailcow-dockerized/blob/master/data/web/api/openapi.yaml) documents the domain read endpoint and API-key authentication. The [domain implementation](https://github.com/mailcow/mailcow-dockerized/blob/master/data/web/inc/functions.mailbox.inc.php) supplies `domain_name`, `active_int`, `mboxes_in_domain` and `max_num_mboxes_for_domain`. Those fields are validated, projected and counted; other returned fields are discarded. Mailcow quota inputs use MiB, so the local policy is 50 × 1,048,576 bytes. It is not the domain's reported default quota.

Controlled codes distinguish missing/invalid connection configuration, 401/403 authentication failure, connection/timeout failure, invalid response (including an HTTP 200 error payload), and missing domain. Raw upstream response/exception text is never returned or logged by this feature. CLI output is equally sanitized. These checks do not probe per-student address availability and do not associate existing mailboxes. Search and local save make no Mailcow request.

The previously reported 103 mailboxes / 200 limit is an operator observation, not a verified deployment value. The feature reads the live limit when explicitly requested and does not assume it was raised. It never changes a domain or existing quotas.

## Plesk activation

These are deployment instructions only; no production migration or connection check was executed during development.

1. Back up the database, verify the signed keys and existing active technical role/module, and deploy the reviewed code and built Vite assets through the normal release process. Avoid applying unrelated pending migrations.

   Build release assets with the normal production API configuration (`npm run build`), not the localhost API setting used for isolated browser tests. The local test build is not a deployment artifact.
2. From the backend directory, run the **specific** migration:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_01_000000_create_student_university_emails.php --force
   php artisan university-email:enable-permissions
   ```

3. Set these server-only variables using the Plesk PHP environment or private backend `.env`. Never add the key to a `VITE_*` variable, browser storage, screenshots, logs or source control:

   ```dotenv
   MAILCOW_BASE_URL=https://mail.alrowaduni.edu.sy
   MAILCOW_API_KEY=
   MAILCOW_STUDENT_DOMAIN=alrowaduni.edu.sy
   MAILCOW_STUDENT_QUOTA_MB=50
   ```

   Fill the key privately on the server using a least-privilege Mailcow read key. This guide deliberately leaves it empty. The application reads `config()` so cached configuration is supported.

4. Allow the **outbound IP of the Plesk university server** in Mailcow's API-key access list. Verify TLS/DNS/connectivity; do not disable TLS or follow redirects to solve configuration failures.
5. Refresh cached configuration and check the domain read:

   ```sh
   php artisan config:cache
   php artisan university-email:check
   ```

6. Reauthenticate the technical operator so the browser receives current permissions; verify its existing data scope. Test a synthetic student draft, read it back, and verify local audit/status and unchanged personal/login data. Do not attempt mailbox creation.

Changing application config does not modify Mailcow's domain/default/existing quotas. Before Phase 2, independently verify the current mailbox limit, write-key authority, available quota, idempotent provisioning, existing-mailbox reconciliation policy, secure password handling and signed handover. No Phase 2 workflow is supplied here. Rollback must preserve saved drafts: never force a populated-table drop or use a broad migration rollback.

## UI references and executed verification

The page reuses the current Technical Office shell and navigation, `AccountsPermissionsPage` table/search/actions, `TechnicalHome` header styling, shared `DataTable`/`FilterBar`, the current `MinistryUi` header/sections/notices, and the current `ManualGradeDialog` for guarded navigation. Cairo, RTL, sizes, borders, colors and responsive behavior are retained; no global design file was changed. Reference and new-page desktop/mobile screenshots were compared using synthetic data.

PHPUnit uses isolated synthetic SQLite fixtures and `Http::fake` with stray requests forbidden, not production data or a real Mailcow key. Node tests cover pure logic/static wiring. Both browser checks below were executed with a production React build: the existing synthetic-response regression and a new **real React-to-Laravel** regression using an exported isolated synthetic SQLite fixture and real Sanctum tokens. The latter does not intercept application APIs. It covers first open, Arabic/name and number searches, whitespace edits, clear/search pagination, save and immediate row summary update, reopen, unauthorized API/UI access, and a stale-save 409, at desktop 1440 and mobile 390 widths. Screenshots were inspected against the named current-page references. The built-in browser connection was unavailable, so the existing installed Chrome and CDP were used; no dependencies were installed.

The live local browser made exactly two explicit PUT saves. A read-only check of its isolated database found one draft at revision 2 and exactly two safe audit records (created revision 1, updated revision 2); the rejected stale/unauthorized operations left no additional audit or draft. This is sequential SQLite integration evidence, **not** concurrent-save/InnoDB evidence. No local MySQL/MariaDB client or listening service on port 3306 was available, so independent-connection concurrency remains unexecuted. Live Mailcow and production/Plesk verification also remain unexecuted.

The full repository lint has existing errors outside the changed files; changed-file lint is reported separately. Two historical source contracts are not green: the calendar schema-repair contract still requires the now-existing Phase 3 policy service to be absent, and the supplementary hardening contract forbids **any** migration in the entire PR diff. The latter is incompatible with this task's explicitly requested additive email migration. Neither contract was weakened or changed.

`php artisan test --filter=...` cannot complete repository-wide discovery because the existing `AcademicCalendarPhase5OccurrenceResponseTest::result()` overrides a final PHPUnit method. The relevant files were instead executed directly with `php vendor/bin/phpunit`; no historical test was changed or skipped inside those files.

| Executed check | Result |
| --- | --- |
| Targeted PHPUnit: email behavior/contract, account administration, technical portal/activity SQL contracts | 60 passed, 919 assertions; actual HTTP middleware included |
| All independent frontend Node tests | 255 passed |
| PHP source contracts | 31 of 33 passed; two historical/context limitations explained above |
| PHP files changed by this repair `php -l` | 3 passed |
| All changed JS/JSX/MJS files ESLint | Passed with no errors or warnings |
| Full `npm run lint` | Failed: 90 errors, 16 warnings in unchanged files |
| Vite production build | Passed; the existing large application bundle remains |
| Built React in clean headless Chrome with synthetic API, desktop 1440 and mobile 390 | Passed: view/save, cancelled sidebar navigation followed by save, conflict retains proposal, explicit server review, lost write response without retry, Mailcow unavailable while local save remains available; screenshots compared with reference |
| Built React connected to real local Laravel with isolated synthetic SQLite, desktop 1440 and mobile 390 | Passed: search/clear/pagination/save/reopen/row-summary/unauthorized scenarios above; 66 real API responses, two explicit UI saves; no static API interception |
| Composer validation and locked platform requirements | Passed |
| `git diff --check` | Passed |
| Live Mailcow, MariaDB multi-connection concurrency, Plesk deployment | Not executed |

### Reproduce the isolated Laravel browser check

Use an existing local PHP/Node/Chrome installation and a newly created directory beneath the system temp directory. Set `UNIVERSITY_EMAIL_BROWSER_DIR` to that directory, then run the email PHPUnit file with filter `test_export_isolated_synthetic_browser_fixture_when_explicitly_requested`. The test requires the testing in-memory SQLite connection and exports synthetic `email.sqlite` and `identities.json` only beneath temp. The identity file contains local test tokens: keep it private and never commit it or use production credentials.

Start Laravel on `127.0.0.1:8099` with explicit process-only `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=<temp>/email.sqlite`, empty `DB_URL`, array cache/session, a synthetic application key and **empty** `MAILCOW_API_KEY`. Build React with process-only `VITE_API_BASE_URL=http://127.0.0.1:8099/api`, serve its preview on `localhost:5173`, and start clean headless Chrome with its own temp profile and debug port 9244. Run `node tests/browser/university-email-live.mjs` from frontend with the same fixture-directory variable. The script blocks external HTTPS requests and uses real application HTTP requests. Restore the normal release API configuration when building deployment assets; never deploy this localhost test build. Stop the local helpers afterward.
