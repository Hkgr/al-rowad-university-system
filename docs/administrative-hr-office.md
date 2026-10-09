# مكتب الموارد البشرية — 711

Implementation branch: `codex/administrative-hr-office`, based on merged PR #159 / develop `3920da76ee1a142296f5eb5f58ce390373787cf4`. No deployment, production DB connection, dump import, or live personnel data was used. The reference `alrowad_uni_rust10-9.sql` was read for schema and role/reference definitions only.

## Architecture and audited sources

The canonical person is still `employees` (signed INT `employee_id`). Existing five employee types, statuses, `faculty_members` (unique employee), `employee_positions`, `employee_unit_assignments`, accounts, dean governance, dual-VP teaching assignments and exceptional opening are preserved. An HR relationship's position describes employment; it does **not** appoint a dean, grant a role, or assign teaching. Old incomplete classification does not lock any old operation.

Existing payroll identities use unsigned BIGINT, have their own unique text employee number and independent financial body, and originally had no HR link. `payroll_bodies.name` is not a reliable classification code. No mapping by name, body label or numeric ID is inferred. An optional restrictive/unique `payroll_employees.employee_id` links verified identities; neither the financial name/body nor stored amounts are rewritten. HR body at linking is an attribution snapshot, not a command to change financial classification. Payroll remains one current working sheet. The separately authorized disbursement/receipt ledger below records actual events explicitly; it does not turn sheet values into payments or change the salary formulas.

References inspected: `AdministrativeFacultyService`, `AdministrativeDeanService`, `TeachingAssignmentWorkflow`, `DataScopeService`, `AdministrativeGovernance`, `OwnerPortal`, `PayrollSheetService`, `PayrollConfigService`, `OwnerPayrollController` and existing personnel/payroll migrations. No grade, student, curriculum or teaching lifecycle code is changed.

Approved visual direction: [HR office draft v2](https://p.superdesign.dev/draft/e3f2bfe9-ad03-486c-b84e-8958fa09b2fa). Actual components reused: `DashboardLayout`, `AdministrativeNavigation`, `AdministrativeHome`, `AdministrativeFacultyPage`, `GovernanceUi`, `DataTable`, `FilterBar`, `OwnerPayroll` and its financial dialogs. Cairo/RTL/global tokens are unchanged. Design approval is **not** browser acceptance.

## Data and lifecycle

| Store | Meaning |
|---|---|
| `hr_staffing_needs` | Open/closed need, educational college or administrative unit, root revision |
| `hr_staffing_need_items` | Independent position, additional quantity, education/specialization/skills/experience/notes |
| `hr_candidates` | Internal candidate, exact need item, optional explicit existing employee, revision |
| `hr_interviews` / `hr_interview_participants` | Multiple dated interviews and recorded interviewers; edits preserve before/after attribution in events |
| `hr_relationship_requests` | Draft/submitted/returned/approved/rejected; target + nullable current slot uniqueness, review/version/context/materialization |
| `hr_employment_relationships` | Immutable original contractual dates, predecessor, operational supersession date, approved-request or legacy-classification provenance |
| `hr_workforce_events` | Append-only decisions, versions and before/after changes; no API update/delete |

No employee, faculty profile, login or payroll is created by a need, candidate, interview or draft/submitted request. Approval atomically reuses the explicit existing employee or creates one using an operator-provided unique employee number/type. For an accepted educational candidate it ensures one canonical faculty profile, not a teaching assignment. No account or financial profile is automatic.

Temporary contracts have a manually confirmed start and **inclusive** end: `start + 3 calendar months (without overflow) - 1 day`. For example October 1–December 31. Continuous contracts have no end and may be full/part; employment has no end and must be full. Dates are never inferred from account creation/approval. Renew/convert select an explicit predecessor; the original end remains intact while `superseded_from` defines operational replacement. The service rejects overlapping employment intervals. Future approvals do not replace a currently effective relation until their start.

Worker lists/details, relationship-request defaults and payroll linking use the same server-derived `current_body`: a recorded relationship is current only when its start has arrived, its inclusive end has not passed, and its operational supersession has not started. Stored `employees.hr_body` remains a legacy attribution and is used only when there are no recorded relationships. Past/future relationships without a current one return no current body. A future educational/administrative conversion therefore does not change the current body on approval; its start makes it effective without rewriting original contracts or financial classification. Payroll linking snapshots this current body under the employee and relationship locks; existing financial bodies, amounts and earlier link snapshots are unchanged.

Need quantities are entered additional demand, not current-teacher deficits. Filled = distinct employees with effective approved/materialized relations tied to that item **and matching its specified position**. Position IDs are authoritative when present; text-only items require no position ID and matching trimmed job-title text. A relationship for a different job does not cover the item, including historical mismatches. Candidates/pending proposals never count. Candidate acceptance restores the item's position/title in the form and revalidates the identity under locks at preparation, submission and approval. Renewal/conversion retains the need link only when the recorded body/unit/position/job context remains the same. Existing workers need no historical need or interview.

Education, skills and experience are mandatory, nonblank strings for each need item on explicit save (maximum 4000 characters each). A truthful description such as «لا تُشترط خبرة» is valid. Existing nullable values remain readable as unspecified; neither reads nor this correction backfill invented requirements. Editing an incomplete historical need requires explicit completion of those fields. Candidate qualification fields keep their existing semantics.

Legacy completion accepts only an existing employee, explicit dates/body/type/mode/placement and reason. It cannot overwrite an already recorded relation or a pending direct relationship request; later issuance/renewal/conversion needs VP approval. The source is `legacy_classification`, with no historical reviewer invented. Accounts, appointments, teaching and money are untouched.

## Authorization

All HR writes authorize inside `HrOfficeService`; routes are under existing Sanctum + active-account middleware. Actual active `hr_officer` or `vice_president_administrative`, assigned effective action permission, and factual `DataScopeService` university/college organizational context are necessary. Role/scope/permission alone is insufficient. Administrative approval requires actual administrative VP **and university scope**. Existing central active `super_admin` authority is preserved, without manufacturing scopes.

Only direct factual college scopes authorize college workforce access; a department/program scope is not expanded into the entire college. Primary unit, effective recorded unit assignment, or effective explicit HR relationship establishes employee membership **within this HR module only**. Academic/teaching scope semantics are not altered.

| Permission | Purpose / grant |
|---|---|
| `administrative_hr.view` | Scoped office reads |
| `administrative_hr.recruitment.manage` | Needs, candidates, interviews, decline |
| `administrative_hr.classification.manage` | Explicit legacy completion |
| `administrative_hr.relationships.prepare` | Draft/edit/submit/resubmit |
| `administrative_hr.relationships.review` | Actual administrative VP approval/return/rejection only |
| `administrative_hr.payroll.access` | Separate university-wide accounting entry; actual finance officer / HR / administrative VP |
| `administrative_hr.payroll.link` | Explicit identity link, additionally requiring payroll access and employee-manage permission |
| `administrative_hr.relationships.correct` | Actual administrative VP + university scope; direct dated correction, immutable predecessor |
| `administrative_hr.relationships.cancel` | Actual administrative VP + university scope; stop effect with reason/date, no record deletion |
| `administrative_hr.workers.export` | Individual worker PDF within the same HR read scope; financial section additionally requires payroll read |
| `owner_payroll.payments.manage` | Separate assigned financial authority to record disbursement/receipt or void an erroneous record; no amount/formula editing grant |

Accounting reuses **all existing** `owner_payroll.*` action permissions, exact controller/services/data/formulas and React page. `owner_portal.access` is NOT granted to office roles. The owner route still requires its own actual owner-role grant pair. The owner provisioning command still grants owner permissions only to the owner role; its audit now permits deliberately mapped financial action codes on recorded office roles with their own assigned payroll entry permission. Other foreign owner mappings still fail. HR provisioning registers permissions and optionally grants core HR operations to existing HR/VP roles. Only the explicit `--grant-vp-payroll` option additionally grants the four existing payroll view/link/identity permissions below; it does not create accounts/roles/scopes or edit the global permission editor.

### Administrative VP payroll access

After deploying the compatible code, a server operator may explicitly run these commands from `backend` using the site's configured PHP CLI. **They have not been executed on production by this work.**

```sh
php artisan hr-office:provision-access --grant-vp-payroll
php artisan hr-office:provision-access --grant-vp-payroll --check
```

The first command idempotently adds exactly `administrative_hr.payroll.access`, `owner_payroll.view`, `administrative_hr.payroll.link` and `owner_payroll.employees.manage` to the **existing active** `vice_president_administrative` role. The check is read-only and reports `VP_GRANTS_READY` or `VP_GRANTS_MISSING` (missing HR definitions report `MISSING`). Existing active owner-module payroll permissions are prerequisites; the option does not silently create an owner module/role, assign accounts or grant a scope. A real university scope remains required at runtime. No amount editing, formula/configuration management, export, payment recording or owner-portal access is granted by this option. It neither removes nor changes pre-existing legitimate grants. The employee-management permission is the existing financial-identity permission needed for canonical identity linking; this command never modifies financial records or values.

The separately authorized additions use **different explicit options**:

```sh
php artisan hr-office:provision-access --grant-vp-actions
php artisan hr-office:provision-access --grant-vp-payments
php artisan hr-office:provision-access --grant-vp-payroll --grant-vp-actions --grant-vp-payments --check
```

`--grant-vp-actions` adds only correction, cancellation and individual worker export to the existing VP role. `--grant-vp-payments` adds only `owner_payroll.payments.manage`; payroll entry/view remain separate prerequisites. Core `--grant-hr`/`--grant-vp` do not implicitly grant these new rights. Register the new financial permission through the existing explicit `owner-portal:provision-access` command first; that command deliberately registers/grants owner permissions to the owner role as before. No other role receives financial or HR powers automatically. The same payment service is behind the owner and accounting APIs, but their route guards remain separate.

## Routes and UX

`/vp/administrative/hr`: six sections, scoped/paginated search and body/college filters, multi-position needs, candidate interviews, proposed relationship preparation, return/resubmit and VP decisions, employee history and explicit payroll linking. Existing faculty/dean/teaching/exception paths remain intact. The new office entry uses a separate guarded route group; it does not grant the VP's other pages.

`/vp/administrative/payroll` and `/payroll/link`: the same owner financial page, API adapter supplied by context (not global mutable routing), exact financial permissions and explicit identity selection. Office 721 remains under directorate 72; no fictional unit is inserted from the chart.

API prefix `/api/v1/vice-presidency/administrative/hr`: options; workers/needs/candidates/requests/relationships/classification lists; worker/candidate/request details; needs and candidate POST/PATCH; interview POST/PATCH; decline; request POST/PATCH/submit/decide; classification; payroll lookup/personnel lookup/link. Read responses are private/no-store. Pagination defaults 15/max 100. Candidate interviewers and financial identities use bounded searchable paginated lookups, not all-person loading. Worker-list relations/faculty and need filled counts are loaded in batches. Detail event display is the latest 100, while all events remain stored.

Drafts and pending writes block SPA navigation; active section selection is a no-op. Results are bound to request context, and obsolete responses are ignored even when abort cannot stop them. 403 clears unauthorized detail; identity change remounts/clears sensitive workspace state. 409 and ambiguous writes retain the form and lock retry until explicit review; no automatic write replay. Successful save clears only its editor. Reusing the payroll page preserves its established save/conflict controller.

The refreshed HR tabs use the approved HR draft's restrained active underline/background, RTL horizontal scrolling and manual keyboard activation: arrow/Home/End keys move focus, while Enter/Space uses the existing guarded transition. Tables still use the unchanged shared `DataTable`/`FilterBar`. Need totals have separate required/filled/remaining columns; each total is the sum of its existing item values, and the detail dialog shows individual counts and expandable requirements. Names and placements come from existing scoped records with bounded joins/batch queries, not per-row requests. Current recorded positions are displayed without treating an appointment as an employment relationship. Arabic badges, readable dates and bounded long-text cells are local to the HR feature. References remain `AdministrativeFacultyPage.jsx`, `GovernanceUi.jsx`, the existing shared tables and `OwnerPayroll.jsx`; Cairo/global/shell styles are not changed.

Authorized worker details distinguish linked, unlinked and unavailable payroll-link state; unavailable schema is not reported as an unlinked person. Payroll view is checked separately from identity-link permission. After a successful manual link, the office presents an explicit opening action. `payroll_employee_id` is a positive, validated **financial identity ID**, applied by the existing sheet query service, so the page, totals and exports refer to the exact linked record. The current page can clear that filter to show the full sheet. HR and accounting navigation remains inside the administrative shell (72 → 721), and the original owner page/guard remain available.

## Individual worker, direct correction and disbursement records

The user explicitly expanded the initial interface-only scope: direct authorized correction/cancellation preserving history, and a new ledger of actual disbursements followed by explicit receipt documentation. This adds **one migration**, `2026_10_09_180000_add_hr_relationship_actions_and_payroll_payments.php`, rather than inventing past payments from the old sheet. No independent SQL file or production SQL was executed.

`/vp/administrative/hr/workers/{employee}` opens each actual worker in the existing administrative shell, regardless of educational/administrative body or archived status, subject to the existing HR role/read permission and factual employee scope. Table names and detail links open that route. The page shows identity, dated relationships, recorded appointments/affiliations, financial link and audit, with explicit actions and the existing draft/write/navigation guards. Payments are visible only with actual payroll read authority; college-only HR readers do not receive financial rows. Export requires `administrative_hr.workers.export` plus the ordinary scoped worker read; it re-fetches on the server and includes financial history only if payroll reading is authorized. A4 RTL PDF reuses the existing TCPDF/Cairo preparation and footer, the official logo and escaped text. It includes **all** recorded payment rows through bounded ID traversal, not only the visible page. No signature, stamp, payment order or bank verification is fabricated.

### Relationship actions

Non-materialized draft/returned/submitted requests can be explicitly cancelled by an authorized preparer, with reason/confirmation; the request, proposal, versions, reviews and events are retained, and its current slot is released. An approved request is never deleted, reopened or rewritten by this action.

Direct correction/cancellation requires the dedicated action permission, actual administrative VP and factual university scope (existing central administrator authority is unchanged). Locks follow need/item, employee, relationship and existing reference order; a current pending employee/candidate request blocks the action. A monotonic relationship revision trigger detects changes by old and new writers. Effects must be dated **today or later**; ended/superseded/cancelled relationships are read-only. Original start/end, request approval and historical records remain intact. Cancellation records `cancelled_from`/actor/time, affecting current-body and need coverage only when the date arrives. Correction creates an `audited_correction` successor and clips the predecessor operationally; an approval-origin link records lineage only, not approval of the new terms or invented historical approval. Legacy origin never becomes approved implicitly. Need links survive only for the same validated body/unit/position identity. Employee context revision advances, but accounts, faculty profiles, teaching assignments, appointments, payroll classification and sheet values do not change.

### Disbursement and receipt

| Data | Rule |
|---|---|
| `payroll_payments` | Restrictive financial/personnel/user FKs; UUID + immutable payload hash, revision, exact integer hundredths, `SYP`, explicit `YYYY-MM` period/date/reference/reason and identity snapshot |
| `payroll_payment_events` | Append-only before/after actor/time events; no delete API |
| `paid` | Operator explicitly records an already-performed positive disbursement; receipt is **not** inferred |
| `received` | Separate confirmation, actual receipt date and evidence reference; no synthesized signature or remote bank verification |
| `voided` | Explicit reason/confirmation invalidates an erroneous record's inclusion, preserving amount, former receipt and all events; **not** evidence of a refund |

Money uses the existing strict `PayrollInput` amount parser (ASCII decimal text, at most two decimals, positive, existing technical amount bound) and integer hundredths for storage/totals. Nothing is copied automatically from current sheet amounts. No bank or remote payout occurs. A month is a manually selected salary reference, not a new monthly payroll calculation/closing workflow. Historical real payments may be entered explicitly with evidence; no historical rows or dates are guessed.

The source is the verified financial → personnel identity link, not a submitted name. Writes lock financial employee → personnel → payment, reauthorize and check revisions. A successful UUID replay with identical original content is resolved under the locks before revision validation; different content/actor is rejected. One active record per worker/actual reference is enforced through a nullable consumption slot; void history can retain the same reference while a corrected new record uses a new UUID. Never automatically replay an uncertain write. Receipt/void operations check the locked payment revision and identity. SQL summaries separately sum non-void disbursements and **received only** records; missing schema returns unavailable, not a zero/history claim. Existing original contract/approval and salary-calculation workflows are unchanged.

Read/write APIs: HR worker `file`/`pdf`, request `cancel`, relationship `action`; accounting and owner `employees/{financialEmployee}/payments` GET/POST and `payments/{payment}/receive|cancel` POST. These reuse one ledger service, with different existing office/owner guards. Reads never create relationships, payment rows, receipts or events.

### Additive installation and limitations

During maintenance of affected HR/financial writers and workers: take a verified backup; apply the **new migration only** after the existing HR migration; deploy compatible code; explicitly register the financial permission and assign the desired separate VP options above; verify factual scope and allowed/denied accounts, then resume traffic. Do not run old code against populated new cancellation/payment semantics. The migration preserves existing rows, uses signed legacy INT and unsigned business/payroll BIGINT FKs, adds no historical backfill, checks parent engines and refuses destructive rollback. A partial relationship-column installation fails closed for operator review.

```sh
php artisan migrate --path=database/migrations/2026_10_09_180000_add_hr_relationship_actions_and_payroll_payments.php --force
php artisan owner-portal:provision-access
php artisan hr-office:provision-access --grant-vp-payroll
php artisan hr-office:provision-access --grant-vp-actions --grant-vp-payments
php artisan hr-office:provision-access --grant-vp-payroll --grant-vp-actions --grant-vp-payments --check
```

These are **operator instructions, not executed production commands**. No tests were added/modified or run for this extension, as requested. Migration up/reapplication, MySQL/MariaDB locks/races, Laravel HTTP integration, receipt/correction runtime flows, React/browser rendering, desktop/mobile comparison and PDF generation/opening remain **unexecuted**. Source inspection found both the installed TCPDF `vendor/tecnickcom/tcpdf/fonts/helvetica.php` and its storage cache absent locally; runtime PDF may fail with controlled `hr_pdf_not_ready` until the locked dependency/font deployment is repaired. Preserve the official `frontend/public/logo.png`, bundled Cairo TTFs and writable font cache. Syntax, changed-source lint and build results below are static/build evidence, not financial or visual acceptance.

## Concurrency and migration

The original HR schema uses `2026_10_09_100000_create_administrative_hr_office.php`; this extension adds the separate relationship-action/payment migration documented above. Signed restrictive legacy FKs and unsigned business/payroll IDs are intentionally different. InnoDB parent tables are required; new HR tables explicitly use InnoDB. No destructive recreation, legacy classification backfill or guessed payroll link. Reapplication preserves records. Rollback is explicitly refused to protect history; restore a verified backup under maintenance instead.

Employee `hr_revision` is advanced by a DB trigger for **every legacy UPDATE**, not a value fingerprint, so change-and-restore is stale. Need-root/candidate revisions advance with item/interview mutations. Requests save submitted context and require its unchanged revisions under locks for approval. Pending requests use a server-derived `candidate:<id>` / `employee:<id>` key and nullable unique current slot.

HR lock order: need → need item → candidate (where applicable) → employee → request → referenced placement/relationship rows. Employee serialization owns overlapping/current relations. Payroll linking starts with the financial employee then HR employee, consistent with existing payroll metadata writers; HR approvals never lock financial rows. Old faculty paths are preserved; HR does not lock/mutate an existing faculty row after taking an employee lock. All approval/materialization/audit writes roll back together on failure. No automatic transaction replay is introduced.

## Focused corrections to PR 160

The three corrections above change only `HrOfficeService.php`, `HrForms.jsx`, `HrOfficePage.jsx` and this guide. No schema/migration, permission, payroll calculation, account, faculty/teaching or historical contract changes are included in this correction. Existing authorization and atomic request/materialization locks remain in place; mismatched acceptance fails before materialization.

PHP syntax, changed-file ESLint, frontend production build and `git diff --check` passed for this correction. The build retains the existing large-chunk warning. No tests were added or modified, as requested. PHPUnit, Node/component/integration/concurrency suites and browser/desktop/mobile visual checks were **not rerun for this correction**; the earlier results below are not evidence for the corrected head. Existing synthetic need fixtures may require explicit qualification descriptions before those scenarios can exercise the stricter save validation. No fixture was silently filled or weakened. Frontend repository instructions request rendered reference comparisons; those remain unexecuted, and visual acceptance is not claimed. No production migration, permission synchronization, merge or deployment was performed.

## HR interface and VP payroll access verification

Branch `codex/hr-office-ux-and-vp-payroll-access` starts from develop `5cee62a8520abe51500aa65b4e1dd273d2134682` after PR 160 merged. PHP syntax passed for the **12 changed/new PHP files**; changed-file ESLint passed for **11 JS/JSX files**, and the current frontend build passed after correcting the initial local lint findings. The existing large-chunk warning remains. `git diff --check` passed. No tests were added/modified or run, no dependencies were installed, and no browser/desktop/mobile comparison was executed in this round. The repository's rendered-comparison requirement remains unverified; source/approved-draft inspection and a production build do not prove visual acceptance. Neither migration nor permission commands were executed. The expanded, explicitly authorized cancellation/correction and payment/receipt implementation is separate from the earlier interface/access-only changes; no salary formula, current sheet amount, teaching assignment, grade or student workflow was modified.

## Initial implementation verification at fb22490 (2026-10-09)

- `npm ci` from unchanged lock: PASS; no dependency/version changes.
- Targeted Laravel/SQLite suites (HR, administrative governance, payroll values/config/calculation/migration, administrative teaching review): **65 tests PASS**. The expanded run including the complete owner access suite is **79 passed / 80 tests, 1344 assertions**, with the pre-existing vendor PDF failure described below. No runtime assertion was skipped or removed.
- HR HTTP suite includes role/permission/actual scope denial, HR cannot approve, finance/owner separation, multi-item needs, multiple interviews, return/resubmit/reject/decline, one-time approval, pending isolation, legacy preservation, ABA stale detection, conversion history, unique pending request, failed identity rollback, financial identity conflict and bounded query count.
- Isolated MariaDB **11.4.9**, `127.0.0.1:3307`: signed-parent synthetic fixture, migration up/reapplication PASS, including comparisons of pre-existing personnel, payroll identity and financial values; two independent-process/connections classification race PASS (one relation + one audit); simultaneous approval race PASS (one employee/relation/materialization event).
- A third independent-connection regression under REPEATABLE READ proves a candidate cannot be edited from an old snapshot after submission while waiting for the need lock. Tests synchronize immediately before native `FOR UPDATE` and observe only the owned test connection's active query before release. Deadlock/lock-timeout errors are controlled conflicts, never automatic transaction replay.
- Actual ReactDOM events + **real HTTP Laravel / isolated MariaDB**, no fetch interception: multi-item need save and reopening, cancelled navigation followed by save, and unauthorized read/role rendering PASS. Host has **no layout or browser**, so this does not prove visual behavior, responsive rendering or complete end-to-end acceptance.
- Related pure Node suites: **51 PASS**; existing administrative ReactDOM suite **7 PASS**, live HTTP component suite **3 PASS**, including immediate sensitive-form cleanup after actual Laravel denies a disabled synthetic actor.
- Build PASS, existing large-chunk warning retained. Changed-file eslint, PHP syntax, Composer validate/platform, source contracts and diff checks are recorded in the PR.
- Full-repository eslint still reports **90 errors / 16 warnings** in existing unrelated files; all changed source files pass targeted eslint. These baseline errors are not suppressed or repaired in this feature.
- Broader owner suite initially had the obsolete payroll-isolation contract (updated only to allow this explicit bridge) and a **pre-existing PDF failure**: installed vendor lacks `tecnickcom/tcpdf/fonts/helvetica.php`. The same PDF failure was reproduced using develop's original test from a detached verification worktree; the PDF service/file is unchanged. No historical runtime assertion was removed/skipped to hide it.
- Browser bootstrap fails before navigation: `codex/sandbox-state-meta: missing field sandboxPolicy`. No 390/768/1440 screenshots, visual/accessibility acceptance or actual browser+Laravel workflow were executed. No alternate browser/safety workaround was used. Before release, run those scenarios in a permitted browser and compare the named reference components.
- MariaDB 11.4 was tested, not the production engine/version or MySQL. Broader races with faculty/dean writers, payroll linking vs edits, and renew/convert were not all exercised on independent connections. Existing service tests do not prove those production interleavings.
- Synthetic test databases/artifacts stay local under explicitly named `codex_hr_office_test_*`; no attached dump was imported. Full Plesk deployment/production data verification remains unexecuted.

## Reproduce isolated integration (not production commands)

From backend, with a local test MariaDB and an **unused** isolated database name:

```powershell
$env:HR_TEST_DATABASE = 'codex_hr_office_test_unique_run'
$env:HR_TEST_PORT = '3307'
php tests/Support/hr_office_mariadb.php setup
php tests/Support/hr_office_mariadb.php concurrency
php -S 127.0.0.1:18171 -t public tests/Support/hr_office_http.php
```

In another frontend terminal: `node --test tests/components/hr-office-live.mjs`. The fixture refuses any non-empty DB and accepts only the named test prefix on localhost; it issues an ignored synthetic test token, never a real credential. Do not use production credentials, schema, dump, or accounts. No automatic teardown destroys test history.

## Plesk release checklist — NOT executed

Take a verified backup and enter maintenance for affected writes/workers. Verify existing personnel/payroll parents are signed/unsigned as above and InnoDB. Use the site's configured PHP CLI, in the backend directory:

```sh
composer dump-autoload --optimize
php artisan migrate --path=database/migrations/2026_10_09_100000_create_administrative_hr_office.php --force
php artisan migrate:status
php artisan hr-office:provision-access --grant-hr --grant-vp
php artisan hr-office:provision-access --check
php artisan owner-portal:provision-access --check
php artisan optimize:clear
```

Build frontend from its unchanged lock in the release/build environment: `npm ci` then `npm run build`. Deploy the complete `frontend/dist` including current Cairo assets using the existing site's release structure. This PR does not prescribe a guessed Plesk path/PHP binary or apply these commands remotely.

Manually assign legitimate active roles and factual scopes through existing account tools. Financial access is separate: explicitly assign payroll entry + required existing `owner_payroll.*` actions to a designated university-scoped finance/VP operator; optionally add link permission. Do not map `owner_portal.*` to another role. Existing workers appear unclassified until office confirmation; record their real dates/body/type/mode/placement without invented historical approval. Verify each payroll identity/number before linking; do not merge, recategorize financial bodies, or populate amounts automatically.

Before resuming traffic: check allowed/denied HR and finance identities, old owner page, existing teacher/dean/teaching paths, one full synthetic approval and explicit financial link, return/resubmit, schema readiness, Cairo/RTL desktop/mobile and export. Complete the currently blocked visual checks and resolve the environment's missing vendor PDF font before claiming release acceptance. No merge/deploy is performed by this work.
