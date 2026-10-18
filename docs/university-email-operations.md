# University email: unified operations runbook (Phases 1–3)

Current increment: follow [central access, lifecycle migration and verification](university-email-central-access-and-ux.md) for central technical email targeting, deletion/recreation, issuer-based receipts, deployment order and actual verification limitations. It supersedes earlier email-only DataScope/no-deletion rules; academic DataScope is unchanged.

This guide is not authorization to deploy or write production. Feature/verification evidence: [Phase 1](university-email-phase1.md), [Phase 2](university-email-phase2.md), [Phase 3](university-email-phase3.md). Host/domain remain `https://mail.alrowaduni.edu.sy` / `alrowaduni.edu.sy`; new mailboxes are 50 MiB. Linked accounts retain existing quota. No bulk provisioning, delivery confirmation or personal-email/university-login mutation exists.

## Maintenance deployment order

1. Back up application/database; record installed Mailcow/PHP versions and permission mappings. Stop affected writes/workers. Preserve `APP_KEY` and history. Deploy reviewed backend/build through the existing Plesk application/document roots, never fixtures/test routers/secrets. No server routing redesign is needed.
2. Check `php artisan migrate:status`, then apply only required migrations in order, not unrelated pending migrations:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_01_000000_create_student_university_emails.php --force
   php artisan migrate --path=database/migrations/2026_10_01_000001_add_university_email_provisioning.php --force
   php artisan migrate --path=database/migrations/2026_10_01_000002_add_university_email_operation_cancellation.php --force
   php artisan migrate --path=database/migrations/2026_10_01_000003_add_university_email_account_management.php --force
   ```

   Recorded migrations are not rerun. Phase 3 adds fields/kinds to existing tables only: operation reason/safe before snapshot; linkage origin/last successful state/time. Verify restrictive FKs, unique creation/active slots, cancellation invariant and nullable credential pointer. A partial migration failure requires maintenance/investigation, not deletion of history. Missing Phase 3 schema blocks its actions while previous-phase reads remain available.
3. Explicitly run `php artisan university-email:enable-permissions --phase3` only after reviewing authorized operators. It adds Phase 1–3 definitions/mappings to the existing active technical role/module, not accounts/roles/scopes. Review each independent reset/suspend/activate/link authority; remove role mappings not legitimately authorized. Portal/view, actual DataScope and receipt permission for password issuance still apply.
4. Supply private server-only keys, never `VITE_*`, logs or source control; retain disabled gates:

   ```dotenv
   MAILCOW_BASE_URL=https://mail.alrowaduni.edu.sy
   MAILCOW_STUDENT_DOMAIN=alrowaduni.edu.sy
   MAILCOW_STUDENT_QUOTA_MB=50
   MAILCOW_API_KEY=
   MAILCOW_WRITE_API_KEY=
   MAILCOW_PROVISIONING_ENABLED=false
   MAILCOW_CONTRACT_VERIFIED=false
   MAILCOW_INITIAL_PASSWORD_LENGTH=24
   APP_DEBUG=false
   ```

   Phase 1 health uses the read key; mailbox management reads/reconciliation use the separate write-capable key even with writes disabled. Restrict its allowed IP to backend outbound IP and verify full domain visibility. Keep TLS verification enabled.
5. Using the deployed PHP executable/user in Plesk, run `php artisan config:clear` then `php artisan config:cache`; reload affected long-lived workers if any. Verify settings without printing keys. Build with the normal production API URL and ship Cairo/logo assets, never a localhost-test build. Disable route/HTTP body recording, APM/session replay/debug capture. No scheduled/queued provisioning write is needed.
6. Before releasing maintenance verify local search, strict authorization, schema readiness, disabled write controls, student self-only reads and no remote request per search row. Do not enable both gates before installed-contract/capacity sign-off.

## Installed contract and capacity

Use existing `university-email:check`/health UI to verify fixed host/domain, DNS/TLS, active domain, API visibility and remaining mailbox count. Separately inspect the installed Mailcow administration UI for mailbox-count limits, **aggregate domain/storage quota**, current usage and per-mailbox maximum. Mailbox count is not proof of storage capacity. Keep creation disabled if capacity is unavailable. Never force linked boxes to 50 MiB.

Verify exact API success codes, tag support/preservation, `active_int`, byte quota/usage, attr-only activation/link updates and `force_pw_update` against the deployed version and pinned official sources. Confirm forced change in the Mailcow account UI before SOGo/IMAP use; direct clients are not assumed to enforce it. Verify domain-list visibility before trusting a missing lookup. This implementation did not inspect live Mailcow.

## Later, separately authorized synthetic live canary

After separate approval only, use a designated synthetic student/mailbox and legitimate operator. Enable both gates/config cache; create one box explicitly. Check Arabic display name, 50 MiB quota, module tag, first-login password change and synthetic send/receive. Download/open 24/64-character receipts and inspect desktop/mobile layout; handover stays unchanged.

Then test separately authorized general reset, suspension/activation preserving password/quota/messages, old-PDF denial and a designated synthetic legacy link preserving all prior tags/state. Do not test with real students or adopt real boxes. Record version/actor/time and redacted outcomes. A mocked API test cannot substitute for this canary. Restore disabled gates on failure; never repeatedly POST an uncertain operation.

## Uncertainty and stopping writes

1. Set both gates false and refresh config cache if outcomes/auth/contract/capacity fail. Preserve evidence and `APP_KEY`; never clear write markers, delete operation rows or manufacture confirmation.
2. Inspect safe operation/audit IDs, generation/state/write-start. After 60 seconds use explicit authorized reconciliation: remote reads and local state confirmation only, available with the configured key while writes are disabled. Failed lookup preserves last-success information.
3. Prepared/failed/preflight/conflict with no write-start may be explicitly cancelled by its kind's authorized operator under locks. History stays, slots release, credentials/workers invalidate. Pending link cancellation restores prior draft address. Started/uncertain/confirmed operations cannot be cancelled this way.
4. Remote success/local failure must not cause another create or compensating DELETE. General-reset reconciliation never proves/recovers a lost password or grants a PDF; investigate outstanding work before a fresh authorized reset. Phase 2 uncertain-reset manual-review rule remains.
5. Preserve module tags. Unverifiable ownership, another student's address or administrator conflicts require authorized manual investigation; no automatic relink/reset or direct SQL repair is provided.

## History-preserving rollback

Disable writes/cache first. Record and selectively revoke Phase 3 mappings if necessary; do not blindly remove shared permissions. Preserve additive tables/columns/operations and existing Mailcow accounts/messages. Phase 3 `down()` refuses destructive rollback. Older application code is not automatically safe with new kinds/linked addresses: keep writes disabled and use a reviewed compatibility rollback. Never let old workers execute new operations or delete/reset boxes as rollback compensation. Retain backups/audit; downloaded-PDF confidentiality remains operational responsibility.

## Remaining acceptance

Isolated automated checks do not establish production sign-off. In-app browser is blocked by `missing field sandboxPolicy`; actual React → Laravel interaction, PDF download/opening, desktop/mobile visual checks and live first-login/send/receive remain pending. Use the Phase 3 guarded synthetic fixture/manual steps, then a separately authorized live canary. SSR is not PDF visual acceptance.
