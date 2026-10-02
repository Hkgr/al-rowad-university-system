# University Email: central access, simplified management and safe deletion

Base: `origin/develop` `3066cd1cce7c07e26ec47ec6163ac3f6da5c1593`, after merged PR149 and PR150. Branch: `codex/university-email-central-access-and-ux`. This increment supersedes older email-only DataScope restrictions and the no-delete UI; it does not change academic access or the central super-admin bypass.

## Authority, search and UI

An active actual `technical_team` operator needs assigned `technical_portal.access`, `university_email.view` and each operation's own permission. Within email only, the operator can target all existing non-soft-deleted students, without creating academic DataScope. Other portals retain existing scope checks. Active actual super-admin retains the existing bypass without a manufactured technical role, email assignment or scope.

Deletion requires independent `university_email.delete`; manage/provision/reset do not suffice. The explicit `university-email:enable-permissions --phase3` command provisions five account-management permissions plus preceding permissions for the existing technical role. The migration creates no permission, user, role or scope.

The server ANDs normalized tokens across number, first/last/father names using bound parameters and deterministic number/ID pagination. Empty search remains valid. Local bounded page lookup and correlated operation checks introduce no per-row remote calls or N+1. Status filtering and totals share a fixed projection; JSON booleans were exercised on both SQLite and MariaDB.

| Label | Local definition |
| --- | --- |
| لم يُنشأ | No root, or draft without an unresolved current-cycle operation. |
| فعال | Created root, last successful snapshot exists/active, no unresolved current-cycle operation. |
| موقوف | Created root, last successful snapshot exists/inactive, no unresolved current-cycle operation. |
| محذوف | Confirmed explicit removal, no unresolved operation; prior address remains muted. |
| يحتاج تحقق | Unresolved operation, unknown snapshot or missing expected mailbox. |
| غير متاح | Email schema absent, not proof of no mailbox. |

Status is explicitly presented as last local state, not a fresh Mailcow assertion. Confirmed-account modal opening refreshes information. Missing-schema filtering returns controlled 503; unfiltered list reads show unavailable.

Design references: existing `StudentsPage`, `UniversityEmailPage`, `DataTable`, `FilterBar`, `MinistryUi`, and existing grade confirmation dialog. Shared table/filter/header/notice controls are reused. An email-only scrollable native dialog uses current Cairo/RTL/colors/radii/control sizes and a fixed footer; no shared/global component changes. Direct current-design implementation was explicitly authorized; no Superdesign draft was generated. Screenshot comparison remains unexecuted.

Normal creation has brief identity, English name, address preview and one create/retry action. Safe retry wraps existing cancellation/preparation with audit and proof invalidation. Uncertainty offers checking only, never another automatic create. Confirmed accounts show an action grid, explicit reason/confirmation, RAM-only credentials and independent download. Delete has a separate warning/number/reason screen. Deleted accounts offer explicit new creation, not reset/activate/suspend. Verified legacy linking remains permission-gated with signed preview and unchecked explicit ownership attestation; names/numbers are not ownership proof.

## API and durable lifecycle

Existing APIs remain. Additions are POSTs under `/api/v1/technical/university-email/students/{student}`:

| Suffix | Input / authority |
| --- | --- |
| `recreate` | Name, exact deleted revision, confirmation; provision + manage + receipt. |
| `reset-password-now` | Revision, reason, confirmation; reset-password + receipt; canonical password workflow. |
| `account-action` | Revision, reason, confirmation; kind-specific suspend/activate/link authority; link also signed preview/address/attestation. |
| `delete-mailbox` | Revision, exact student-number confirmation, reason, confirmation; delete permission. |

Unknown input is rejected; responses are private/no-store. Student → email root → operation remains the lock order. Remote calls are outside DB transactions. UUID, generation, active/creation slots, operator reauthorization, write-start marker and safe audit remain authoritative.

The [official Mailcow OpenAPI](https://github.com/mailcow/mailcow-dockerized/blob/master/data/web/api/openapi.yaml) specifies mailbox removal as POST `/api/v1/delete/mailbox` with an address array. The adapter sends one address once, never transport-retries. Success alone is insufficient: an authoritative domain-list GET must prove absence. Timeout/lost reply or local-confirmation failure after write authority leaves `uncertain`; further execute is stale. Read-only reconciliation after the existing grace period can confirm absence locally, never send another removal. This is explicit requested deletion, not create-failure compensation.

Confirmed removal changes the root to `deleted`, records timestamp/operation issuer, clears cycle references and increases revision. No root, operation, receipt or audit is deleted. Recreation locks the same UNIQUE student root, rejects unresolved work, releases only a previous confirmed create reservation, increases revision and establishes a monotonic `lifecycle_revision` boundary. It goes through draft/new prepared UUID to confirmed create. Historical outcomes are retained. Current creation/credential pointers reference the current confirmed cycle only. Execute/cancel/reconcile/receipt reject older cycles and proofs.

Explicit model fillable columns prevent Laravel's cached old table-column inventory silently dropping additive lifecycle fields in a process that previously read the old schema. The combined HTTP suite exercises pre-/post-migration model use. Deployment must still stop affected workers.

## Receipt and secrets

The document officer always comes from the current credential operation's `issued_by_user_id` through `UserIdentityService::documentGenerator()`, including that reset's issuer. A different authorized downloader can request nonsecret metadata; receipt/audit downloader identity stays separate. No stored password can be recovered.

Passwords remain response/browser RAM only. Finish/student switch/authority loss clears sensitive state. Lost responses trigger canonical checking, not replay. PDF failure preserves confirmed RAM credentials for download retry, not another creation. There is no delivery confirmation or handover change.

A4 portrait uses existing Cairo/logo, student/college/program, server reference/time/officer, exact LTR address/password, instructions, secrecy warning and blank manual signatures/seal. No fabricated signature/stamp. Fonts and images are awaited; overflow is rejected before capture, not clipped or silently shrunk. SSR verifies 24/64-character strings and long-name/address content, not pixel layout.

## Schema and deployment (not executed on production)

One additive migration: `backend/database/migrations/2026_10_02_000000_add_university_email_deletion_lifecycle.php`.

- Preserves preceding kind/provisioning ENUM values, appending `delete` / `deleted` on MySQL/MariaDB via `DB::statement` inside migration.
- Adds monotonic `lifecycle_revision`, nullable deletion timestamp and signed INT restrictive actor FK matching `users.user_id`.
- No new business table or record deletion; old migrations unchanged. `down()` refuses unsafe history rollback.
- Without new schema, deletion/recreation return controlled `university_email_deletion_schema_not_ready` (503); old reads remain available.

Maintenance order: back up; stop affected writes/workers; use normal Laravel migrations and verify old row counts/ENUMs/FKs; activate compatible code/restart workers; explicitly run `php artisan university-email:enable-permissions --phase3`; review legitimate assignments; verify email health, central email-only access, lifecycle and receipt rendering, and absence of secrets in logs/APM; resume. Deploy the frontend build including Cairo/logo assets. No manual production SQL is provided. Rollback refusal requires assessed forward recovery/backup, never history deletion.

## Executed verification — 2026-10-02

| Check | Result |
| --- | --- |
| Targeted Phase1/Phase2/Phase3/SuperAdminEmailCreate/CreationRetry/Lifecycle Laravel files | 151 tests passed, 2713 assertions. Real HTTP middleware, isolated synthetic SQLite, upstream-only fake. Includes central access/generic academic denial, permissions, retries, ownership, confirmation, pre/post-write timeout and cancellation, stale cycle, same-root history, nonsecret issuer metadata, reset and link. |
| Isolated MariaDB 11.4.9, 127.0.0.1:3397, independent PHP processes/connections | 16 scenarios passed; nine intentional fake remote writes. Duplicate execute, cancel/execute, reset/receipt, address reservation, delete versus reset/create/cancel, concurrent recreation, old worker and remote-success/local-audit-failure recovery. Read recovery adds no remote write. |
| Migration on populated isolated MariaDB | Roots/operations/receipts/audit preserved with every previous kind/status and both previous root states; ENUMs retained; rollback rejected. Also executed local state filtering. Synthetic schema, not production dump or certification of every database version. |
| `university-email-lifecycle-http.mjs`, live localhost Laravel | Passed blank searches, draft/cancel+retry/name correction, old execute denial, controls/reset/receipt, deletion/same-root recreation, stale receipts, no delivery and unauthorized 403. Isolated SQLite + Mailcow fake, no application API interception. NOT React integration. |
| All dependency-free Node tests | 292 passed; email subset 45. Pure logic/source verification, not browser behavior. |
| PHP email source contracts | Six passed; static only. |
| Changed PHP syntax, Composer validation/platform requirements, diff check | Passed. |
| Changed-file ESLint / production build | Passed; existing >500kB main bundle warning remains. |
| Actual React SSR receipt | Initial/reset, 24/64, long Arabic/address, program/officer/time, logo reference, signature/seal labels and secrecy passed. NOT PDF download/layout. |

### Unexecuted verification / manual acceptance

The in-app browser failed before initialization: `codex/sandbox-state-meta: missing field sandboxPolicy`. No alternative browser/sandbox bypass was used. **React connected to Laravel at 1440/390, screenshots, actual PDF download/opening, rendered Arabic/font/overflow inspection, navigation/sensitive cleanup in a browser remain unexecuted.** Build/SSR/static success is not full or visual acceptance.

Whole-suite PHPUnit discovery is blocked by pre-existing `AcademicCalendarPhase5OccurrenceResponseTest.php:94`, overriding final `PHPUnit\Framework\TestCase::result()`. It was not changed/hidden; the six explicit email files executed successfully. No live Mailcow, production data/schema or production verification occurred. MariaDB 11.4.9 evidence does not establish behavior on every deployed version/configuration.

Manual check in an isolated environment: export a fresh synthetic Phase3+lifecycle fixture using the existing opt-in fixture test; run the guarded Laravel router with isolated SQLite and upstream fake; point local React at it. At 1440/390 verify search/clear/pagination, safe retry/name correction, controls/reset, delete number/reason, same-root recreation, unresolved checking, unauthorized loss/student switching. Generate distinct 24/64-character receipts with long Arabic names/addresses; open both, inspect A4/glyphs/logo/exact LTR characters/instructions/issuer/server time/blank signatures/seal. Verify no receipt before confirmation, no handover write, no credentials after finish/switch. Do not use canned application responses, production or live Mailcow.

## File scope

Backend: access/permission command, email-only scope helper, two email controllers, email model, three email services/adapter, API routes, one new migration. Tests: five existing email suites updated only for approved scope changes; one new lifecycle suite; six source contracts; guarded upstream-fake router and independent MariaDB runner.

Frontend: delete predicate, email page/table, email-only dialog/mailbox flow, query/creation/account helpers, receipt/PDF, email Node contracts, SSR and real-HTTP smoke. Shared/global components, academic pages, account bypass, old migrations and dependencies are untouched. Documentation adds this guide and historical/runbook pointers.

### Exact changed paths

- `backend/app/Console/Commands/EnableUniversityEmailPermissions.php`
- `backend/app/Http/Controllers/Api/UniversityEmailController.php`
- `backend/app/Http/Controllers/Api/UniversityEmailProvisioningController.php`
- `backend/app/Models/StudentUniversityEmail.php`
- `backend/app/Services/DataScopeService.php`
- `backend/app/Services/MailcowProvisioningClient.php`
- `backend/app/Services/UniversityEmailProvisioningService.php`
- `backend/app/Services/UniversityEmailService.php`
- `backend/app/Support/UniversityEmailAccess.php`
- `backend/database/migrations/2026_10_02_000000_add_university_email_deletion_lifecycle.php`
- `backend/routes/api.php`
- `backend/tests/Contracts/super_admin_email_create_contract.php`
- `backend/tests/Contracts/university_email_creation_retry_contract.php`
- `backend/tests/Contracts/university_email_lifecycle_contract.php`
- `backend/tests/Contracts/university_email_phase3_contract.php`
- `backend/tests/Feature/SuperAdminEmailCreateTest.php`
- `backend/tests/Feature/UniversityEmailCreationRetryTest.php`
- `backend/tests/Feature/UniversityEmailLifecycleTest.php`
- `backend/tests/Feature/UniversityEmailPhase1Test.php`
- `backend/tests/Feature/UniversityEmailPhase2Test.php`
- `backend/tests/Feature/UniversityEmailPhase3Test.php`
- `backend/tests/Support/university_email_phase2_concurrency.php`
- `backend/tests/Support/university_email_phase2_router.php`
- `docs/university-email-central-access-and-ux.md`
- `docs/university-email-operations.md`
- `docs/university-email-phase3.md`
- `frontend/src/features/auth/auth.js`
- `frontend/src/features/technical-portal/components/UniversityEmailDialog.jsx`
- `frontend/src/features/technical-portal/components/UniversityEmailMailboxDialog.jsx`
- `frontend/src/features/technical-portal/components/UniversityEmailReceipt.jsx`
- `frontend/src/features/technical-portal/lib/emailAccount.js`
- `frontend/src/features/technical-portal/lib/emailCreation.js`
- `frontend/src/features/technical-portal/lib/emailReceiptPdf.js`
- `frontend/src/features/technical-portal/lib/universityEmail.js`
- `frontend/src/features/technical-portal/pages/UniversityEmailPage.jsx`
- `frontend/tests/browser/university-email-lifecycle-http.mjs`
- `frontend/tests/browser/university-email-receipt-render.mjs`
- `frontend/tests/superAdminEmailCreate.test.mjs`
- `frontend/tests/universityEmailCreationRetry.test.mjs`
- `frontend/tests/universityEmailLifecycle.test.mjs`
- `frontend/tests/universityEmailPhase2.test.mjs`
- `frontend/tests/universityEmailPhase3.test.mjs`

**One justified new migration. NO STANDALONE SQL. NO PRODUCTION CHANGES. NO LIVE MAILCOW WRITES. NO LOCAL HISTORY DELETION. No merge or deployment.**
