# University email Phase 3: linked-account management

Base: develop `077ce64ab334f2e8a8c86898cbc960b881a336e6`, containing merged PR147. Phase 3 extends the existing Technical Office screen and durable operations. No production database, live Mailcow, real student data or deployment was used. Deployment and incident handling: [unified operations runbook](university-email-operations.md).

## Authority and API

Every operation requires an active account, actual effective `technical_team`, assigned `technical_portal.access` and `university_email.view`, and actual student DataScope. Virtual super-admin permission or another portal role is insufficient. Authorization is rechecked under student → local email → operation locks.

All additions are POSTs under `/api/v1/technical/university-email/students/{student}/provisioning/`. Unknown input is rejected; responses are private/no-store.

| Suffix | Additional permission | Input/meaning |
| --- | --- | --- |
| `refresh-account` | view | Empty input; explicit remote lookup and safe local last-success snapshot. |
| `reset-password` | `university_email.reset_password` + `university_email.issue_receipt` | `revision`, nonblank `reason`; generates an ephemeral secret hidden until execution confirms. |
| `preview-link` | `university_email.link_existing` | Lowercase `email_address` in exact domain; read-only signed preview. |
| `prepare-account` | `university_email.suspend`, `.activate` or `.link_existing` according to kind | `kind`, `revision`, `reason`, `confirmed`; link additionally requires address, `preview_proof`, `ownership_confirmed`. |
| `execute-account` | Persisted operation's permission | `operation_id`, current `generation`, `confirmed`; only suspend/activate/link. |

General reset uses the existing password/proof execute endpoint. Existing cancellation/reconciliation require the persisted kind's permission; original Phase 2 reconciliation still requires provisioning authority. Receipt issuance retains `university_email.issue_receipt` and adds `receipt_purpose=password_reset`, never a password in metadata. No delivery, bulk-create, mailbox-delete or background-retry endpoint exists.

The four new permissions are explicitly provisioned by `university-email:enable-permissions --phase3` for the existing active technical role/module only, together with previous-phase definitions. It creates no user, role or scope. Review legitimate operator assignments separately. UI predicates mirror server authority; hiding buttons is not authorization.

## Account state and legacy linking

`remote_snapshot` contains existence, active state, quota/usage in bytes and percent. Missing usage is unavailable, not zero. `remote_checked_at` is the last successful lookup. Failed connectivity/auth/invalid responses preserve preceding successful information and return `check.status=unavailable`. A successful lookup with no matching box returns missing, distinct from failure or suspension. A refresh rejects a snapshot changed by a concurrent operation while its remote read ran.

Search and state GETs remain local, with bounded selected-student operation history (50 rows). No per-student remote search lookup or browser-to-Mailcow connection is introduced. Explicit refresh/preparation/execution uses the existing fixed-domain list lookup; the write key must have verified full domain visibility. Raw lists/responses are not exposed or persisted. Tags are retained only in the operation's allowlisted revalidation snapshot to verify their preservation, never in API projections or audit descriptions.

Linking first requires a saved local draft using existing draft-management authority; its generated address is not ownership evidence. The linking dialog accepts a verified legacy address. Signed preview binds operator/student and mailbox identity, active state, quota, forced-change flag and sorted tags; traffic usage is excluded. Other students' reserved addresses and any existing application marker are rejected. The operator must independently verify ownership and record a reference/reason; matching names/numbers do not prove it.

Preparation reserves the address through the existing unique email constraint and marks `pending_link`, not confirmed ownership. No remote tag is added before attestation and explicit execution. Execution rechecks the preview and sends **tags only**, preserving existing tags. Password, quota, active and forced-change state stay unchanged. Confirmation marks `linked` and saves the ownership-operation pointer. Pre-write cancellation restores the saved draft address, increases revision and invalidates the old worker without deleting history. Confirmed linked addresses are readable through the existing student self endpoint, never secrets.

Tags are evidence within the trusted Mailcow administrator boundary, not immutable proof against a malicious administrator. Cross-system conflicting changes require investigation, not automatic adoption.

## Shared operations, audit and secrets

All six kinds share the existing per-email `active_slot`, cancellation generation and lock order with Phase 2. No new history/business table exists. Network calls remain outside database transactions. Before the single remote POST, a short transaction rechecks authority/revision/generation/state, records `write_started_at`, invalidates old credentials and writes a safe intended-change audit. Suspend/activate send only `active`; linking sends only preserved tags plus marker. General reset preserves active/quota and forces next password change.

Audits include actor, student/record/operation IDs, address, kind, reason, generation/revision, result and safe previous/current state. No password, proof, API key, tags, remote body or PDF content is logged. Lost replies or local confirmation failure after write authority leave durable uncertainty; no automatic POST retry or compensating DELETE occurs. Cancelled/stale operations cannot resume.

After the existing 60-second guard, explicit reconciliation reads remote state and confirms locally only. An uncertain general reset's marker proves ownership/intent, **not which password won**. Reconciliation never grants that unknown password/PDF; credential pointer stays null. Investigate outstanding remote work before a separately authorized new reset, which must confirm a new known password. Original Phase 2 uncertain-reset manual review is unchanged.

General reset is distinct from Phase 2 limited initial reissue: linked accounts may retain arbitrary valid quota/inactive state. Initial reissue still requires the original active 50 MiB forced-change created mailbox. Both preserve RAM-only secrets and receipt permission. Proposed general-reset credentials are hidden until their exact operation confirms. Opening another action immediately clears preceding credentials/PDF. Pending operations block old receipt issuance. Existing finish/navigation/student-switch/authorization-loss cleanup remains; it has not been visually verified in this environment. External body/APM/session recording must be disabled by deployment operators.

## UI, PDF and official contract

Design references: existing `UniversityEmailPage`, `UniversityEmailProvisioning`, `AccountsPermissionsPage`, `MinistryUi` sections/notices/info grids and `ManualGradeDialog`. Existing Cairo/RTL/buttons/spacing/shell are retained. No global design change was introduced. Dialogs identify student/address/action and require reason/confirmation; legacy attestation is unchecked by default.

The current A4 receipt is reused with a reset title and exact LTR wrapping for long addresses and 24/64-character passwords. Explicit PDF download does not change `handover_status` or prove delivery. SSR is not PDF visual acceptance.

Official source was reviewed at commit `ca07d8d3331849ae294179aedce95c8126d3050f`: [OpenAPI](https://github.com/mailcow/mailcow-dockerized/blob/ca07d8d3331849ae294179aedce95c8126d3050f/data/web/api/openapi.yaml) and [mailbox implementation](https://github.com/mailcow/mailcow-dockerized/blob/ca07d8d3331849ae294179aedce95c8126d3050f/data/web/inc/functions.mailbox.inc.php). `edit/mailbox` accepts `items` plus selected `attr` fields: `active`, `tags`, or password/password2/force_pw_update. Getter fields `active_int`, `quota`, `quota_used` supply activation and byte usage; display uses binary MiB. TLS verification, disabled redirects and single-attempt transport remain. This is not verification of the university's installed version/API-key visibility/first-login behavior; both gates remain false by default.

## Executed verification and limitations

- Targeted Phase 1–3, technical account administration and SQL-contract PHPUnit: **95 tests, 1,463 assertions passed**, real Laravel HTTP middleware, isolated synthetic SQLite and upstream-only Mailcow fake. Coverage includes separate authorities, scope, usage/missing/offline distinctions, ownership/attestation, stale preview, shared active slot, cancellation, general reset/receipt, exact remote property updates, local audit rollback, delayed refresh versus mutation and no-write GETs.
- Isolated MariaDB **11.4.9**, new private data directory on `127.0.0.1:3397`: **9 scenarios passed**, independent PHP processes/connections. Includes duplicate execution (one POST), cancellation before/after write authority, Phase 2 versus account operation, old receipt versus general reset, real trigger-induced local confirmation failure followed by read-only recovery, and two students reserving one legacy address (one controlled winner). That last race reproduced a raw deadlock path; unique/deadlock/lock-timeout failures now become a controlled address conflict without retry. Six expected fake remote POSTs total; fake artifacts never store credentials. This is not proof of production configuration or all possible lock interleavings.
- All **267 dependency-free Node tests passed** (pure/source checks, not browser integration). Actual React SSR preserves initial/reset receipt strings at 24/64 lengths plus long Arabic name/address/issuer/time. SSR does not prove layout, fonts or PDF generation.
- Production Vite build, modified-source ESLint, changed-PHP syntax and diff checks passed; existing large-chunk warning remains. Composer validation/platform checks passed. Full frontend ESLint still reports **90 errors/16 warnings** in unchanged existing files; no unrelated fixes/suppressions were added.
- A separately served real Laravel application passed the Phase 3 HTTP smoke: cancel/correct/create, 64-character credentials, status usage, general reset and old receipt rejection, suspension/activation and unauthorized denial. SQLite was isolated; only upstream Mailcow was faked. This is not React browser execution.
- Dependency-free PHP contracts: **33/35 passed**. The two unchanged historical failures are `academic_calendar_schema_compatibility_repair_contract.php` (expects an already-existing policy service to be absent) and `supplementary_exam_end_to_end_hardening_contract.php` (historical change-boundary rejects any migration). Email contracts passed; those historical assertions were not weakened.

The supported in-app browser failed before opening a tab: `codex/sandbox-state-meta: missing field sandboxPolicy`. No alternate Chrome mechanism was used to bypass it. **React → Laravel browser interaction, desktop/mobile screenshots, actual PDF download/opening, Arabic/logo/clipping inspection and logout/student-switch cleanup remain unexecuted.** Live Mailcow/production was not tested. Code verification is separate from visual/live acceptance.

### Prepared isolated manual check

1. Create a fresh private temporary directory for each length. Set `UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR` to it and `UNIVERSITY_EMAIL_PHASE3_FIXTURE=1`. From backend run `php vendor/bin/phpunit tests/Feature/UniversityEmailPhase2Test.php --filter test_export_isolated_phase2_browser_fixture_when_requested`. This exports synthetic SQLite and private identities/tokens with Phase 3 schema/permissions; never publish `identities.json`.
2. In a dedicated shell set `APP_ENV=testing`, `DB_CONNECTION=sqlite`, empty `DB_URL`, `DB_DATABASE=<directory>/email.sqlite`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `LOG_CHANNEL=null`, the same fixture directory and `UNIVERSITY_EMAIL_PHASE2_PASSWORD_LENGTH=24` (repeat fresh at 64). With no cached production config, serve `php -S 127.0.0.1:8099 tests/Support/university_email_phase2_router.php`. It refuses non-temporary/non-testing databases and mocks upstream Mailcow only. Build/preview React with `VITE_API_BASE_URL=http://127.0.0.1:8099/api`; authenticate only the exported synthetic identity.
3. Save draft → generate/cancel → correct English name/save new revision → explicitly create one box. Check no preconfirmation PDF, usage/last-success, suspend/activate with reason, general reset and old-PDF denial. Download initial and reset receipts. Reopen/switch student/finish/logout independently: old credentials must disappear and remain unrecoverable. Test unauthorized 403.
4. For legacy linking, seed only the temporary fake's `remote-mailboxes.json` with a synthetic untagged mailbox matching the getter fields. Save second student's draft; preview, attest ownership with reference, then link. Compare quota/active/forced-change state and tags: only the application tag may change. Check pre-write cancellation and later authorized management. Never seed or adopt a production mailbox.
5. Open actual downloaded files in a PDF viewer. Verify A4, Arabic shaping/logo, long name/address, every password character in LTR order including punctuation/wrapped ends, instructions/warning/issuer/time and all edges. Inspect desktop 1440×1000 and mobile 390×844. Keep artifacts private/redacted; record visual results separately from automated assertions.
6. Stop helpers and rebuild with the normal production API configuration. The existing Phase 2 browser helper can assist only in an independently approved browser environment; DOM/page-size checks do not prove Phase 3 visual acceptance.

`frontend/tests/browser/university-email-phase3-http.mjs` provides real Laravel HTTP smoke coverage against a **fresh** guarded fixture, not React/browser verification. `backend/tests/Support/university_email_phase2_concurrency.php` supports opt-in Phase 3 on isolated localhost MariaDB, refuses an existing database and never reads application connection credentials.
