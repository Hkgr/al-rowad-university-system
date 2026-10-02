# University Email: safe automatic creation reconciliation

Base: fetched `origin/develop` **648f7f248ef02d88a9d2114f4fbfe49e4a574a46**, after merged PR152. Branch: `codex/university-email-safe-auto-reconciliation`. This is a separate corrective PR, not a rewrite of authorization, provisioning, deletion, or automatic receipts.

## Reproduced cause

The existing `reconcile()` rejected every operation updated within 60 seconds before reading Mailcow. `checkCreation()` swallowed the controlled in-progress error and returned ordinary verify state. The UI therefore presented an unresolved-error message during an ordinary safety grace interval.

Before changing production code, two real HTTP/middleware tests failed against isolated synthetic SQLite and only upstream Mailcow faked. The early positive-read regression specifically expected `created` but got `draft`, despite a matching remote mailbox after one lost create response. The waiting-state regression found no reconciliation metadata. This reproduces code behavior, **not a live production diagnosis or production-data verification**.

## Server state and proof boundary

The existing state response now adds:

```json
{
  "reconciliation": {
    "status": "waiting",
    "retry_after_seconds": 60,
    "ready_at": "2026-10-02T01:00:00Z",
    "operation_id": "the-current-operation-uuid"
  }
}
```

`ready_at` is computed from the persisted operation's `updated_at + 60 seconds`; reading state does not update this timestamp. `waiting` means inside grace, `ready` means a check is due or no pending operation remains, and `unresolved` is returned after an unsuccessful post-grace creation check. Metadata does not grant write authority.

Only an **uncertain create with a persisted write start** may be read early. Confirmation requires the existing operation marker plus exact address/domain, 50 MiB quota, active mailbox, and forced password change. A missing or mismatched mailbox is not proof of failed creation: the operation remains uncertain, active/creation slots remain reserved, and no failure/cancellation or credential issuance occurs. Confirmation uses the existing short locked transaction, rechecking generation/revision/cycle and authorization; the remote GET remains outside transactions. An active `in_progress` worker retains the existing grace policy. No early account-action/delete policy is added.

Automatic `creation-check` also refuses to abandon a pre-write `preflight`: during grace it returns waiting and afterward unresolved. The legacy explicit reconciliation/cancellation mechanisms are preserved, but bounded automatic creation polling never cancels a worker, releases its unused slots, or starts another account operation.

**Read-only recovery means zero remote writes.** Successful proof may still make the canonical local confirmation and audit writes; it is not a zero-local-write claim. Negative reads make no operation/slot/audit change. No request sleeps or holds a PHP worker/DB lock for the grace period.

## Frontend flow

The current `UniversityEmailPage`, `UniversityEmailMailboxDialog`, `UniversityEmailDialog`, `MinistryUi`, `UniversityEmailReceipt`, `emailReceiptPdf`, and PR152 orchestration are the design/behavior references. Existing Cairo, RTL, controls, spacing, colors, and document design are reused; the direct correction follows the current-design guidance, not a new Superdesign draft.

1. Opening/recovering an uncertain started create performs one immediate `POST creation-check` (only Mailcow GET internally) to allow positive early confirmation. Live preflight/in-progress workers wait for their server deadline instead.
2. If still pending, the dialog shows a spinner and «تم إرسال طلب إنشاء البريد، ويجري التحقق من النتيجة…». It hides ordinary retry/cancel/technical-detail controls during waiting/checking; initial loading no longer flashes an unresolved notice.
3. A controller bound to the student/current operation schedules a check after the server delay. It permits at most **four scheduled checks**, with subsequent minimum backoffs **3, 5 and 10 seconds**; a longer server delay takes precedence. This is separate from the initial one-shot check. Only the existing `creation-check` endpoint is called; no create/reset/cancel/delete/execute callback exists in the poller.
4. A confirmed response transitions to mailbox management, refreshes its verified read snapshot and the parent row. A different operation is not adopted by the old controller. Unmount/student/authorization-context changes cancel the timer; already-started late reads are ignored. Normal creation/reset success with RAM credentials still uses PR152 automatically.
5. After the bounded unresolved budget, show «تعذر التأكد تلقائيًا من نتيجة العملية حتى الآن. لم يتم إرسال طلب إنشاء جديد حفاظًا على الحساب.» and «إعادة التحقق», with technical details collapsed. Manual checking can explicitly start a new bounded read cycle; it never retries a remote write.

### Lost initial credentials

The UI now consumes the server's explicit `credential_state.status`; it never infers an interrupted creation from a null credential reference. `available` identifies a confirmed current-cycle credential reference, **not a retrievable password** (only confirmed RAM credentials can be printed). `none` is the ordinary no-recovery-notice state, including normal suspend/activate, linked mailboxes, unresolved/wrong-marker operations, and deleted roots.

`lost_after_reconciliation` requires a confirmed, started **create** referenced by the current root and lifecycle, a null credential reference, and the existing durable `university_email.creation_reconciled` audit with matching operation UUID, root ID, student ID, revision and generation. The query does not depend on the 50-row UI operation history. Missing/mismatched/malformed audit evidence returns `none`, not a guessed recovery story. A **confirmed** reset in this lifecycle permanently supersedes the initial-loss wording even after maintenance clears its credential reference; a failed pre-write reset does not. Evidence from a deleted/recreated cycle cannot leak into the current one. These are bounded read queries over existing tables; there is no new schema or audit write during description.

Local confirmation following a lost create response does **not** recover the password or populate `credential_operation_id`. The UI does not fabricate credentials or request an initial receipt. It explains «تم إنشاء البريد وتأكيده، لكن تعذر استعادة كلمة المرور الأولية بسبب انقطاع الاستجابة.» and offers **«إصدار كلمة مرور جديدة»** through the existing authorized password-reset confirmation/reason flow. This is an explicit user action, not automatic recovery. Its new confirmed RAM password then triggers PR152's automatic receipt/PDF attempt. Receipt failure still preserves confirmed credentials and retries receipt/PDF only.

## Initial implementation verification — 2026-10-02 (prior head)

- Targeted **seven Laravel test files: 182 tests / 3307 assertions passed**. Synthetic isolated SQLite and real HTTP middleware/services, upstream Mailcow faked and stray requests prohibited. The new class adds **seven scenario methods** (its inherited phase2 tests also execute): early positive confirmation without credentials or a second POST; early absence and later presence; wrong marker/address/domain/quota/activity/password policy; stable waiting deadline/post-grace unresolved state; no active-worker read or automatic preflight cancellation; explicit reset/new receipt after reconciliation; generation changed during remote read blocks old proof.
- The existing recent-worker regression now explicitly uses **in_progress**, checking grace and post-grace unavailable read separately; its no-remote-read/no-extra-write assertions remain. The new uncertain-create test owns the changed early-read behavior rather than silently weakening the old guard assertion.
- **330 dependency-free Node tests passed**, including **19 new reconciliation tests**; **83 email-focused tests passed**. Production timer/transport helper with injected scheduling and responses proves bounded delays, one-shot early checking, no write endpoint, confirmation, exhaustion, transport failures, timer cancellation, authorization denial/loss and stale responses. Component integration and explicit-reset/auto-receipt separation are source wiring contracts, **not React interaction or browser acceptance**.
- Six existing PHP email contracts, three changed PHP syntax checks, changed production frontend ESLint, frontend production build, Composer validation/platform checks and `git diff --check` passed. Build retains the existing >500kB chunk warning.
- Existing receipt React SSR check passed initial/reset 24/64-character passwords with long synthetic Arabic/name/address variants. This verifies SSR output, not PDF layout, browser saving or timer interaction.

## PR153 review corrections and current verification — 2026-10-02

Fetched the existing branch twice, including before delivery; the prior/reviewed head was `f5c35662ae64a20f52a4a56db442979cc61ca036`, with no concurrent remote commits. The same branch and PR153 are retained.

### P2: expired server delay

The added regression failed before the correction: after the initial scheduled 60-second delay and a transport error, the next delay was 60 seconds instead of 3. Each check now accepts a server delay only from its fresh valid response; the catch path explicitly supplies `null` to the scheduler. Four scheduled transport failures therefore produce exactly **60 → 3 → 5 → 10 seconds**, then manual fallback. Fresh subsequent responses can still supply their own delay. 401/403 stop polling immediately. No create/reset/cancel/delete/execute endpoint is available in this controller.

### P2: evidence-based initial-loss notice

The old UI predicate misclassified normal maintenance because suspend/activate intentionally clears `credential_operation_id`. The backend helper described above now owns the semantic decision; the UI checks only that semantic state plus usable RAM credentials. New HTTP assertions distinguish normal creation/maintenance, actual reconciliation, wrong marker/context, linked mailbox, successful versus failed reset, and deletion/recreation. The strict audit regression changes each proof field, tests malformed/missing evidence, and confirms that an operation outside the bounded UI history still has the correct semantic state. It also verifies that description adds no audit or remote writes. Before the backend correction, the normal-create regression failed because the semantic field was absent; this is HTTP contract evidence, not a browser-rendering claim.

### Checks executed on the correction

- Seven targeted Laravel files (`UniversityEmailPhase1Test`, `UniversityEmailPhase2Test`, `UniversityEmailPhase3Test`, `SuperAdminEmailCreateTest`, `UniversityEmailCreationRetryTest`, `UniversityEmailLifecycleTest`, `UniversityEmailReconciliationTest`): **186 tests / 3462 assertions passed** using real HTTP middleware/services, isolated synthetic SQLite and only fake Mailcow.
- All dependency-free frontend Node suites: **335 passed**, including **24 reconciliation tests**. Production helper behavior is executed with injected transport/timers; dialog wiring is a static contract, not React/browser interaction.
- Six PHP email source contracts, PHP syntax for all three changed PHP files, Composer validation/platform checks, changed production frontend ESLint and production build passed. An initial lint finding on a redundant variable initialization was corrected and the changed-file lint rerun successfully; no lint rule was disabled. The existing >500kB build chunk warning remains.
- Existing React SSR receipt check passed initial/reset receipts with 24/64-character passwords and long synthetic Arabic names/addresses. SSR is not downloaded-PDF visual verification.
- Full diff/file review and `git diff --check` passed. No dependency installation.

### Visual P1 and review-thread disposition

Reread `frontend/AGENTS.md` and retried the permitted in-app browser on this correction. Bootstrap again failed before browser execution with `codex/sandbox-state-meta: missing field sandboxPolicy`. **Visual P1 remains unverified due tooling environment.** There are no 1440px/390px waiting/unresolved/confirmed-lost/normal screenshots or rendered comparisons to `UniversityEmailDialog`, `UniversityEmailPage`, `MinistryUi` and `StudentsPage`; source reuse is not visual acceptance. No independent browser/CDP workaround was attempted.

The delay and credential-evidence P2 threads are fixed and may be resolved after delivery review; the visual P1 thread receives the actual blocker/evidence and stays **unresolved**. No claim of complete visual acceptance or merge readiness is made.

## Limits and remaining acceptance

The permitted in-app browser bootstrap failed before execution with `codex/sandbox-state-meta: missing field sandboxPolicy`; no independent browser/CDP bypass was used. Actual React timer interaction, desktop/mobile screenshots, React connected to live isolated Laravel, real receipt download/opening and visual PDF inspection are **unexecuted**. No MariaDB multi-connection test was run for this increment: deterministic SQLite generation interleaving is not production lock/concurrency proof. Whole PHPUnit discovery's previously recorded unrelated final-method override failure is not claimed resolved; only the seven named email files were run. No dependencies were installed.

When the permitted browser works, use only synthetic students, isolated Laravel/test DB and fake Mailcow at 1440px and 390px. Simulate a create that persists remotely then loses its response: verify waiting (not failure), automatic transition, one external create, no old-password display or initial PDF. Verify an absent mailbox stays protected through bounded checks/manual fallback, timer cancellation on closing/switching/authority loss, and an explicit new-password action followed by automatic PDF. Repeat normal confirmed create/reset success and receipt-only failure retry. These are remaining browser acceptance steps, not passed checks.

## Delivery and deployment boundaries

**NO NEW MIGRATION. NO SQL. NO PRODUCTION CHANGES. NO LIVE MAILCOW WRITES.** Existing role/permission/scope checks, central technical access, super-admin bypass, durable UUID/generation/write start, current lifecycle references, deletion, no remote call in DB transaction, no compensation and RAM-only secrets are preserved. No account or history is deleted. There is no schema/permission step: deploy reviewed compatible backend and frontend together through the normal process; do not execute SQL or enable live creation as part of this verification. This task does not merge or deploy the PR.
