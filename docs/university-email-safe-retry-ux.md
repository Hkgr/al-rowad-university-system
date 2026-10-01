# University email safe retry and status recovery

This corrective branch starts from develop `2253652efd306d944984c17c98018fcfbeb92a0d`, which contains merged PR #149. It simplifies previous creation attempts without changing authorization, email schema, remote transport or PDF generation.

## User flow

The server adds a small `creation` description to the existing provisioning response. A safe previous creation with no write authority shows «إعادة المحاولة», alongside the English name and email preview. The explicit click cancels that exact previous attempt and prepares its replacement within one transaction, then invokes canonical execution after commit. Cancellation history, audit and generation invalidation are preserved. A stale click cannot consume a newer attempt.

An uncertain or in-progress creation is checked automatically when the dialog opens. The check performs remote reads only and retains the existing worker grace period. Confirmation switches directly to «إدارة البريد» and «البريد موجود وتم تأكيده»; it does not recover an unknown password. An unresolved outcome shows the simple warning and «التحقق مرة أخرى». Only that exceptional flow exposes optional «تفاصيل تقنية». Normal mailbox management hides the operation history while retaining explicit management actions.

**An absent mailbox after `write_started_at` is not proof that the old worker cannot still create it.** Such an attempt remains unresolved and cannot be cancelled or replaced. This deliberately preserves the existing safety boundary; no second remote POST or mailbox deletion is issued.

## Backend boundaries

`POST technical/university-email/students/{student}/retry-create` accepts the English name, confirmation and exact previous operation ID/generation. It reuses canonical cancellation, name validation, password preparation and execution. `POST .../creation-check` accepts no business input and reuses canonical reconciliation. Existing responses and endpoints remain compatible; GET performs no writes. The creation disposition uses a separate bounded query rather than assuming that the displayed 50-row history is complete.

No authorization code changed. Super-admin still works without an additional role, assigned permission or DataScope. Ordinary technical users still need their actual role, assigned email permissions and actual student scope. Creation/retry still requires manage, provision and receipt authority and the existing disabled-by-default Mailcow configuration checks.

## Design references

The UI reuses `UniversityEmailPage.jsx`, `UniversityEmailMailboxDialog.jsx`, `UniversityEmailProvisioning.jsx`, `ManualGradeDialog.jsx` and `MinistryUi.jsx`. Cairo, RTL, controls, styling and the PDF generator are unchanged. Direct implementation follows the user's approval; no separate design draft was generated.

## Executed verification

| Check | Result and scope |
| --- | --- |
| Targeted PHPUnit | 118 passing tests across the retry, super-admin creation and email Phase 1–3 classes; real HTTP/middleware, isolated SQLite, synthetic identities and upstream-only fake Mailcow |
| New retry regressions | Prepared, real failed/conflict pre-write attempts, preflight invalidation, old credentials, stale generation, started-write denial, atomic rollback, read-only confirmation, unresolved/grace/remote-failure states, admin without extra grants and ordinary technical restrictions |
| Node | 43 passing tests across six email/technical suites; pure request orchestration and static UI/access contracts, not browser rendering |
| Production frontend build | Passed; existing large-chunk warning remains |
| Changed-file ESLint | Passed |
| PHP source contracts | Five passed; static checks only |
| PHP syntax, Composer validation and locked platform requirements | Passed |
| Git whitespace check | Passed |

The authorized browser could not start: `codex/sandbox-state-meta: missing field sandboxPolicy`. No alternate browser-control workaround was used. React connected to Laravel, rendered desktop/mobile comparison, clipboard and actual PDF visual acceptance were **not executed** in this round. The expected isolated MariaDB listener on localhost port 3397 was absent; multi-connection concurrency was not executed. SQLite assertions and deterministic interleavings do not prove production lock scheduling. No CI result is assumed.

## Deployment and remaining acceptance

After review and merge, deploy compatible backend code and the new frontend build. No additional database installation is required beyond the existing email prerequisites. Do not enable production provisioning or modify real Mailcow credentials as part of this change. On an isolated Laravel/database with fake Mailcow, verify prepared/failed/conflict retry, uncertain confirmation and unresolved recovery, name correction, PDF/secret cleanup, permission loss and both screen sizes. Repeat competing retry/execute/cancel cases with independent MariaDB connections before concurrency acceptance.

NO NEW MIGRATION. NO NEW SQL. NO PRODUCTION CHANGES. NO LIVE MAILCOW WRITES. No dependencies were installed.
