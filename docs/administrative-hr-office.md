# مكتب الموارد البشرية — 711

Implementation branch: `codex/administrative-hr-office`, based on merged PR #159 / develop `3920da76ee1a142296f5eb5f58ce390373787cf4`. No deployment, production DB connection, dump import, or live personnel data was used. The reference `alrowad_uni_rust10-9.sql` was read for schema and role/reference definitions only.

## Architecture and audited sources

The canonical person is still `employees` (signed INT `employee_id`). Existing five employee types, statuses, `faculty_members` (unique employee), `employee_positions`, `employee_unit_assignments`, accounts, dean governance, dual-VP teaching assignments and exceptional opening are preserved. An HR relationship's position describes employment; it does **not** appoint a dean, grant a role, or assign teaching. Old incomplete classification does not lock any old operation.

Existing payroll identities use unsigned BIGINT, have their own unique text employee number and independent financial body, and originally had no HR link. `payroll_bodies.name` is not a reliable classification code. No mapping by name, body label or numeric ID is inferred. An optional restrictive/unique `payroll_employees.employee_id` links verified identities; neither the financial name/body nor stored amounts are rewritten. HR body at linking is an attribution snapshot, not a command to change financial classification. Payroll remains one current working sheet, not a new monthly/historical financial workflow.

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

Accounting reuses **all existing** `owner_payroll.*` action permissions, exact controller/services/data/formulas and React page. `owner_portal.access` is NOT granted to office roles. The owner route still requires its own actual owner-role grant pair. The owner provisioning command still grants owner permissions only to the owner role; its audit now permits deliberately mapped financial action codes on recorded office roles with their own assigned payroll entry permission. Other foreign owner mappings still fail. HR provisioning registers permissions and optionally grants core HR operations to existing HR/VP roles; it never grants financial permissions, creates accounts/roles/scopes, or edits the global permission editor.

## Routes and UX

`/vp/administrative/hr`: six sections, scoped/paginated search and body/college filters, multi-position needs, candidate interviews, proposed relationship preparation, return/resubmit and VP decisions, employee history and explicit payroll linking. Existing faculty/dean/teaching/exception paths remain intact. The new office entry uses a separate guarded route group; it does not grant the VP's other pages.

`/vp/administrative/payroll` and `/payroll/link`: the same owner financial page, API adapter supplied by context (not global mutable routing), exact financial permissions and explicit identity selection. Office 721 remains under directorate 72; no fictional unit is inserted from the chart.

API prefix `/api/v1/vice-presidency/administrative/hr`: options; workers/needs/candidates/requests/relationships/classification lists; worker/candidate/request details; needs and candidate POST/PATCH; interview POST/PATCH; decline; request POST/PATCH/submit/decide; classification; payroll lookup/personnel lookup/link. Read responses are private/no-store. Pagination defaults 15/max 100. Candidate interviewers and financial identities use bounded searchable paginated lookups, not all-person loading. Worker-list relations/faculty and need filled counts are loaded in batches. Detail event display is the latest 100, while all events remain stored.

Drafts and pending writes block SPA navigation; active section selection is a no-op. Results are bound to request context, and obsolete responses are ignored even when abort cannot stop them. 403 clears unauthorized detail; identity change remounts/clears sensitive workspace state. 409 and ambiguous writes retain the form and lock retry until explicit review; no automatic write replay. Successful save clears only its editor. Reusing the payroll page preserves its established save/conflict controller.

## Concurrency and migration

One additive migration: `2026_10_09_100000_create_administrative_hr_office.php`. Signed restrictive legacy FKs and unsigned business/payroll IDs are intentionally different. InnoDB parent tables are required; new HR tables explicitly use InnoDB. No destructive recreation, legacy classification backfill or guessed payroll link. Reapplication preserves records. Rollback is explicitly refused to protect history; restore a verified backup under maintenance instead.

Employee `hr_revision` is advanced by a DB trigger for **every legacy UPDATE**, not a value fingerprint, so change-and-restore is stale. Need-root/candidate revisions advance with item/interview mutations. Requests save submitted context and require its unchanged revisions under locks for approval. Pending requests use a server-derived `candidate:<id>` / `employee:<id>` key and nullable unique current slot.

HR lock order: need → need item → candidate (where applicable) → employee → request → referenced placement/relationship rows. Employee serialization owns overlapping/current relations. Payroll linking starts with the financial employee then HR employee, consistent with existing payroll metadata writers; HR approvals never lock financial rows. Old faculty paths are preserved; HR does not lock/mutate an existing faculty row after taking an employee lock. All approval/materialization/audit writes roll back together on failure. No automatic transaction replay is introduced.

## Focused corrections to PR 160

The three corrections above change only `HrOfficeService.php`, `HrForms.jsx`, `HrOfficePage.jsx` and this guide. No schema/migration, permission, payroll calculation, account, faculty/teaching or historical contract changes are included in this correction. Existing authorization and atomic request/materialization locks remain in place; mismatched acceptance fails before materialization.

PHP syntax, changed-file ESLint, frontend production build and `git diff --check` passed for this correction. The build retains the existing large-chunk warning. No tests were added or modified, as requested. PHPUnit, Node/component/integration/concurrency suites and browser/desktop/mobile visual checks were **not rerun for this correction**; the earlier results below are not evidence for the corrected head. Existing synthetic need fixtures may require explicit qualification descriptions before those scenarios can exercise the stricter save validation. No fixture was silently filled or weakened. Frontend repository instructions request rendered reference comparisons; those remain unexecuted, and visual acceptance is not claimed. No production migration, permission synchronization, merge or deployment was performed.

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
