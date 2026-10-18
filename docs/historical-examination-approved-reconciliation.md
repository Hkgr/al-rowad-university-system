# Approved historical examination reconciliation

This increment starts from `39dece16e71cee586d8bac9b8cd87d2f84bf8557` on `develop`, after PRs 177 and 178. It adds the explicit v4 source/review contract. It does not reinterpret issued artifacts, execute source SQL/VBA, import real marks, or deploy anything. The previous live preview is historical evidence, not an authorization or a target count.

## Authority and source map

| Input | Authority and use |
| --- | --- |
| `PROMPT.md`, `README_AR.md` | Scope, operational boundaries and acceptance requirements; exact hashes pinned by `ExamApprovedAuthority` |
| `approved-decisions.json` | Explicit selected records, credit decisions, final-absence evidence, deferrals and the separately assigned supplementary sitting year |
| `identity-and-period-controls.json` | Previously approved person/source ownership and period controls; these do not authorize a transfer |
| `verified-source-findings.json` | Technical findings and source controls, not blanket write permission |
| `course-identity-review.json` | Provisional, program-qualified subject evidence; every target and actual relationship is revalidated, never selected by an old numeric ID |
| `source-manifest.json` | Exact examination bytes; a renamed file is acceptable only with the same SHA-256 |
| Supplied SQL | Read-only snapshot data parsed by `CompletionSnapshot`; never imported or executed |

All seven handoff documents are read and hashed. Their values remain in the private bundle, separate from original source cells. The source hashes used for the delivered offline audit are:

| Source | SHA-256 |
| --- | --- |
| exam1 | `38460d1f3ba6573d3df859787ae9c54076c87e684b568631a634ad2617854b73` |
| exam2 | `7be23fb22a748eb9b613987e64fa4dfd40086ade5a9fea5a2d046e0a42805fce` |
| SQL analysis snapshot | `3b2da3b6d7ef1dbb075ce81ee69a76693ca78938527b3d90730b05f89d1c1f33` |

The replacement exam1 file matches the pinned original. No mismatching copy was substituted. The v4 bundle file hash is `4053a7f87291e7e1f104b89c9e9f75a15dcec97bead486061757f22e36b01fae`. The bundle contains source facts, not a live review. Projection counts can change with code without changing those source bytes. Preserve older bundles/reviews/receipts. Existing v3 reconstruction remains supported. Already rejected v1/v2 contracts remain rejected, not silently upgraded.

The supplied snapshot has 54 audit INSERT statements and no `student_completion.exam_start`, `exam_action`, `exam_result`, `exam_correction` or `exam_catalog_result` receipt-code occurrences. This is a read-only text inspection of that export, not proof of today's production state. Inspect durable authorized status before any future operation or resume.

## Implemented semantics

Person ownership and college-qualified program identity are resolved before source outcomes compete. An unresolved person never wins a number-only comparison. Actual registration/year/semester wins over advisory inference. Numeric IDs in an older inventory are assertions, not selectors on another database. Exact typography normalization is allowed; fuzzy merging and course cloning are not.

One intact highest qualified source record is selected per real attempt. Its coursework/final/provenance stay together. Distinct actual periods remain distinct. Deferred same-attempt evidence cannot be evaded by choosing a lower alternate. The source K, Q and L remain unchanged. A folded supplementary total needs an actual independently owned exam cell; `L-J` is not an observed examination. exam2's assigned 2025–2026 sitting year does not date a semester or change the parent registration year. exam1 supplementary chronology remains unestablished.

T is the source exam-threshold condition, not a course pass. `20+17=37` remains failed under the official scale. F and Z remain controlled. Source final absence keeps numeric coursework/total and NULL examination; it does not manufacture zero or I. Ordinary all-dash incomplete work remains ungraded, without a formal lifecycle event. Existing real detailed components, zero marks, maxima, completed work and workflow states are never replaced by guessed aggregate allocations.

| Path | What a successful operation means |
| --- | --- |
| Exact equivalent | No writes to marks, results, timestamps or approvals |
| Canonical missing result | Saved component draft and audited applicable hours; normal submission/approval still required |
| Aggregate / ordinary ungraded / absence work | Durable private staging only; no automatic I, result adoption or approval |
| Official correction | Separate read-only review and explicit authorized one-registration adoption; complete before/after evidence, reason, source authority, receipt and unchanged announcement/global approvals |
| Catalog credits | Separately authorized scientific publication; copies the fixed source plan, validates the entire new plan, approves/defaults it for future admissions; no existing student transfer |

Adopted results are protected against ordinary manual/legacy writes, recalculation, unsupported registration transitions and component reconfiguration. Finalization verifies and retains the recorded outcome, skips only that student's recalculation, and completes other students normally. All formal I states, W/Z and supplementary ownership retain their dedicated boundaries. Historical snapshots/decisions remain immutable; current official metrics use the effective outcome only when officially eligible.

## Applicable credits and catalog publication

The previous schema has no per-attempt or per-version membership hours. A JSON import note would not change active GPA/SQL readers. One bounded package therefore adds append-only, indexed credit evidence and outcome/work evidence. PHP and SQL use the same precedence:

`recorded attempt credits → pinned registration membership credits → current shared-origin fallback`.

The fallback is explicitly labeled as current-origin, not invented historical proof. GPA, transcript inputs (including the shared PDF builder's transcript payload), earned hours, requirement budgets, registration workload/choices and current executive/ministry readers use this contract. Bulk attachments avoid a new per-registration credit query. Used course origins are not rewritten. Canonical plan copies retain contextual overrides; student selection uses the actual assignment, not the current default. Proposal plan displays project the explicitly selected/default plan, not every historical version. An unpinned old proposal item has only its truthful origin fallback; no historical version is guessed.

| Qualified program | Approved change | Independent budget / total |
| --- | --- | --- |
| `RU2-ES-TS` | ES421: 2→3 | College mandatory 62→63; 141→142 |
| `RU1-CDI-ESI` | ESI112 and ESI114: 2→3 each | College mandatory 58→60; 74→76 |
| `RU2-FBER-CE` | FES311: 3→2, Civil only | Actual college-elective group remains 6; total remains 165 |

The Civil course is elective in the supplied relationships. Reducing a mandatory budget or the program total would be incorrect. Architecture and shared origins remain untouched. Target subjects, activity, membership, group and old hours are checked against source and current state. A context-ready preview does not bypass canonical whole-plan approval. Any canonical failure rolls back fixation, copies, assignments, credits, events, approval and defaulting together. A legacy context already sealed by historical adoption is blocked for separate review; do catalog publication first. This path does not transfer students.

## Schema and maintenance order

Manual package: `backend/database/sql/historical-exam-approved-reconciliation/`:

1. `00_preflight.sql`: inspect existing owned objects and prerequisite key definitions.
2. `01_apply.sql`: guarded/resumable installation, not an academic-data apply.
3. `02_verify.sql`: exact readiness contract.
4. `03_rollback.sql`: refuses removal when any real evidence exists.

Tables: `historical_exam_work_items`, `registration_credit_evidence`, `plan_course_credit_evidence`, `historical_exam_outcomes`. No migration or new permission. Source/target key types include BIGINT plan-event IDs. Checks cover JSON, kinds, positive revisions and bounded hours. Index uniqueness and column order, nullability/capacity, FK mappings/rules and trigger target/event/timing/protective body are checked. Unknown definitions are rejected, not overwritten. Inserts advance the installed catalog revision; updates/deletes of evidence are prohibited. Membership credit inserts require an unfixed draft. A compatible partial installation and reapplication work. Partial/incompatible runtime schema returns controlled 503, not an empty-data claim.

**Future operator deployment only:** back up schema/data and immutable private artifacts; enter maintenance and stop affected writes/queue workers; run preflight, apply and verify on the intended database; run the compatible code and smoke checks before restarting workers/traffic. Do not run old code on the new constraints without proving compatibility. No commands below were run on production. This delivery does not authorize production application.

Use the existing persistent writable cache/shared atomic lock store and worker configuration from the academic/catalog and Incomplete runbooks. Do not use per-process array cache for production coordination. The existing Incomplete scheduler/expiry command is unchanged. No automatic mark-import or correction job is installed.

## Read-only first operating procedure

Commands run from `backend/`. Replace paths and actor IDs with private, authorized values; output filenames must be NEW. `CompletionFiles` refuses overwrite. Actor must be active, authorized and scoped to every person/course/offering/program; status is operation-owner scoped. Catalog publication additionally requires the existing scientific plan/edit/approve/assign authority. An Exam Affairs role alone cannot publish a plan.

```powershell
php artisan historical-exams:prepare `
  --snapshot="<private>/snapshot.sql" --snapshot-hash="<actual-snapshot-sha256>" `
  --exam1="<private>/exam1-original.xlsm" --exam1-hash="38460d1f3ba6573d3df859787ae9c54076c87e684b568631a634ad2617854b73" `
  --exam2="<private>/exam2.xlsm" --exam2-hash="7be23fb22a748eb9b613987e64fa4dfd40086ade5a9fea5a2d046e0a42805fce" `
  --handoff="<private>/approved-handoff" --output="<private>/approved-v4-new.json"

# Optional separate scientific catalog review; choose targets explicitly.
php artisan historical-exams:preview --actor=<scientific-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --catalog-credits --program-code=RU2-ES-TS --program-code=RU1-CDI-ESI --program-code=RU2-FBER-CE `
  --output="<private>/catalog-preview-new.json"

# Fresh examination live review AFTER any separately reviewed catalog publication.
php artisan historical-exams:preview --actor=<exam-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --output="<private>/exam-preview-new.json"
```

Preparation does not connect to the database. Its companion `.snapshot-report.json` is explicitly offline and cannot authorize apply. A live preview writes a separate `.review.json`; use that review's actual hash and revision, never values from an earlier production preview. Exit 2 means PARTIAL/BLOCKED, not a successful full academic import. Inspect every blocker and the separate stage/draft counts.

Only after later explicit human review/authorization, persist a new UUID and the exact intended payload outside the repository. The following are templates, **not executed real-data operations**:

```powershell
php artisan historical-exams:apply --actor=<exam-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --review="<private>/exam-preview-new.json.review.json" --review-hash=<reviewed-hash> `
  --expected-revision=<reviewed-revision> --operation-id=<persisted-new-uuid> `
  --output="<private>/exam-result-new.json"

php artisan historical-exams:status --actor=<same-actor> --operation-id=<same-uuid> --output="<private>/status-new.json"
php artisan historical-exams:verify --actor=<same-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --operation-id=<same-uuid> --output="<private>/verification-new.json"
```

Catalog publication uses the same `apply/status/verify` commands with `--catalog-credits` and its **own** reviewed file/hash/revision and persisted UUID. It is one atomic publication of at most the three explicit approved programs. There is no partial publication to invent a resume for; exact completed replay is supported.

After a lost response: inspect durable status and verify first. If a known interrupted draft/staging operation has no final receipt, committed stages are intact and the live revision still matches the known owned state, explicit `--resume` uses the original bundle, review, UUID and content, with a new output filename. Do not add `--resume` to a final PARTIAL result to change its intent. New sources/decisions or revised intent require a new bundle/live review/UUID. Matching receipts are checked after authorization and catalog-first locking, before rejecting a stale original revision. A changed payload with the same UUID conflicts. No automatic retry.

For one staged, supported correction:

```powershell
php artisan historical-exams:review-correction --actor=<exam-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --work-item=<scoped-work-uuid> --output="<private>/correction-preview-new.json"

php artisan historical-exams:adopt-correction --actor=<same-actor> `
  --bundle="<private>/approved-v4-new.json" --bundle-hash=<actual-file-hash> `
  --review="<private>/correction-preview-new.json.review.json" --review-hash=<reviewed-hash> `
  --expected-revision=<reviewed-revision> --operation-id=<separately-persisted-new-uuid> `
  --reason="<reviewed-source-and-correction-reason>" --confirmed `
  --output="<private>/correction-result-new.json"
```

An official result remains unchanged during staging/review. Missing activities, absent real registration context, formal lifecycle ownership or inconsistent evidence can still reject adoption. Staging is not completion of a correction. Do not use offering proposals, a second registration, direct SQL or an ordinary grade save as a substitute. Parent-operation verification explains exact authorized before→after adoption chains without changing the original receipt or historical snapshots.

## Existing Exam Affairs workspace

`GET /api/v1/exams/historical-work` and `/historical-work/{uuid}` are read-only, independently scoped to person, course and optional offering/program. Filtering/pagination and period options are server-side; there is no per-row remote request. Private reasons/evidence are not added to student responses.

The existing `/exam-board/incomplete-results?view=imported` and `/vp/administrative/exams/incomplete-results?view=imported` switch between documented formal I and imported ordinary work. `work=<uuid>` opens scoped details and preserves local portal context. This is not a new automatic I workflow or a correction-approval button. References: `IncompleteResultsPage`'s existing filter/table/detail branches, existing manual-entry links, `DashboardLayout`, `DataTable`, `FilterBar`, current alerts/pagination/Cairo/RTL. The existing approved Incomplete draft was inspected as a reference; no new portal design was generated. Saved context/abort/late-read handling and authorization purge are retained.

## Full offline reconciliation

The private JSON and two-tab workbook account for **all 16,461 observations**, including six unnumbered observations. They retain original cells/hashes, complete source references, identity/period/selection evidence, proposed stage, every overlapping blocker and its concrete missing evidence. The fresh `reconciliation-v4-04.json` adds explicit per-blocker and per-observation resolution categories. The workbook preserves the existing review layout and adds filterable column R, **Required resolution**. No identifying rows are committed. Public aggregates are in [the de-identified summary](reports/historical-examination-approved-reconciliation.json).

| Exclusive operational disposition | Observations |
| --- | ---: |
| Canonical draft candidate, still pending ordinary approval | 1,854 |
| Stage only, not result adoption | 6,070 |
| No-op | 1,192 |
| Retained source history | 477 |
| Explicitly deferred | 367 |
| Blocked | 6,501 |
| **Total** | **16,461** |

There are 15,938 attempt review units, 821 candidate database students (747 independently resolved), 270 candidate course identities and 674 period contexts. The prior 15,885 live units are not comparable live readiness: qualified person/attempt grouping, resolved identities, distinct periods and immutable source selection are now explicit. The private comparison also records the previous offline v3 classification for each observation from the same pinned examination/snapshot bytes; it is not a repeat of the old live query.

The explicit deferral union is 367 (not a sum of overlapping categories): coursework over 40, equivalency symbols, the separately approved out-of-range final, transport and old deferred identities. Three further same-attempt observations cannot evade their shared deferral. The exact existing-equivalent control and seven F/system-passed controls were checked; none is silently routed to a numeric passing draft. The private register, not public identifying references, supplies every exact case.

Most remaining work needs real component/activity definitions or independent person/program/period evidence. Known aggregate evidence is staged, not hidden or allocated. Supplementary chronology remains specifically blocked where unproved. Local candidate staging counts do not assert write readiness, approval, a current production revision or complete adoption. No real results were applied.

Resolution categories distinguish **source evidence**, **academic decision**, **existing authorized workflow**, and **implementation fix**. Each blocked observation has at least one explicit category, alongside all of its original blocker codes and concrete evidence/action. Categories overlap: 13,340 observations require source evidence, 8,374 reference an existing workflow, and six blocker-bearing observations require a new scoped academic decision. Existing explicit deferrals remain separately recorded; these overlapping counts do not replace or add to the 6,501 exclusive blocked observations. No remaining source blocker was identified as an uncorrected implementation defect. This does not imply completed live/visual acceptance or authorize any new academic decision. The original v4 bundle and original files have not been regenerated or edited for this report-only enrichment.

## Verification record

Runtime: PHP **8.4.14**, PHPUnit **12.5.30**, Node **24.11.0**, Vite **8.0.16**. No dependencies installed or changed. MariaDB output is the actual `SELECT VERSION()` value **10.11.18-MariaDB**, REPEATABLE READ, on newly marked disposable servers. No real dump was imported.

| Check | Actual result |
| --- | --- |
| Historical v3/v4, catalog credits, plans, workspace and offering proposals | **239 test executions / 4,860 assertions passed**. Includes inherited/provider cases, not 239 distinct new scenarios |
| New focused approved/copy/publication/replay checks | 32 executions passed during focused verification; actual correction review/adopt commands and read-only HTTP work projection exercised |
| Node / ReactDOM/router recovery, imported work, shared transcript/PDF builder and manual-entry regressions | **80 passed** in the combined rerun (53 + 27); synthetic API/no-layout host, NOT live browser or actual PDF acceptance |
| Changed-file ESLint | Four frontend source files passed |
| Frontend production build | Passed, 989 modules; existing >500kB chunk warning remains. An earlier unconstrained attempt hit OS/paging pressure; bounded process settings completed successfully |
| PHP syntax | All 53 changed/new PHP files passed, including the dedicated supplementary fixture/runner |
| Composer | `validate --no-check-publish` and `check-platform-reqs` passed |
| SQL render contract | `php backend/tools/render-historical-exam-schema.php` passed |
| Existing full Incomplete MariaDB acceptance | `course-result-symbols.ps1 -Scenario all` passed on **10.11.18-MariaDB**, both fallback and installed catalog paths |
| Diff | `git diff --check` passed |
| Full private report | 16,461 unique observations, exclusive totals reconciled, all blocker codes explained, all three source hashes unchanged, zero real writes |
| Private workbook | All 16,461 references exported/reopened in order; typed zero/dash/NULL samples and summary formulas checked, no formula errors, both tabs/ranges rendered and inspected using artifact-tool. Native Excel was not used |

The approved MariaDB runner completed successfully: `tests/mariadb/approved-exam-reconciliation.ps1 -MariaDbHome <existing-10.11.18-directory>`. It installs/reapplies/verifies the four-table package, rejects populated incompatible datetime/type, text capacity, unique sequence, FK rule, CHECK and no-op trigger definitions without changing evidence, and refuses history-erasing rollback. It covers persisted v3/v4 command artifacts, independent same-UUID imports, actual manual/legacy writes, approval/finalization, registration/catalog mutations, I grant/resolution/expiry, credit changes, two correction adopters, exact lost-response replay/explicit resume, canonical Sports publication, retained old students/origins, parent verification and competing merged offering-proposal approval. The earlier FK-negative fixture needed separate MariaDB DROP/ADD statements, and an artificial expiry date exceeded a legacy TIMESTAMP range; both synthetic fixture issues were corrected before the successful full rerun.

The separate existing Incomplete acceptance suite also finished `OVERALL | PASS` during the initial implementation: real HTTP kernel/middleware, populated upgrade/reapplication, B+F/Z GPA 1.50, retained/missing Z marks, summer/calendar/deadline boundaries, completed-registration settlement, independent expiry/extension/resolution/finalization/manual-write/registration-transition races in **both** locking paths, exact incompatible-object rejection and batch=1 starvation progress. An earlier memory-pressured attempt did not observe both lock waiters; the later full serial rerun did and passed. This follow-up changes synthetic acceptance fixtures and report documentation, not those lifecycle writers or their schema.

### Dedicated supplementary/import acceptance completed

`tests/mariadb/supplementary-import-race.php` now executes the **actual** `SupplementaryExamMaterializationService::materializeOffering()` against the **actual** persisted-v4 `ExamCompletionService::apply()` on the same registration, with real authorization/scope and installed academic-plan/catalog revision triggers. The approved MariaDB runner includes it. Actual server: **10.11.18-MariaDB**, **REPEATABLE-READ**.

Both deterministic orders passed. A pipe barrier pauses the first service at its real uncommitted result/receipt write, after its catalog `FOR UPDATE`. A separate guarded observer must capture the contender's real `INNODB_TRX` IDs and corresponding `INNODB_LOCK_WAITS` edge on that catalog query before release. A polling delay alone never releases the barrier. The metadata polling interval permits InnoDB's cache to refresh. The evidence JSON retains connection/transaction IDs, query, actual boundary, before/after content hashes and materialization receipt.

- **Import first:** the reviewed attempt is already owned by the published supplementary roster. The importer accurately records a BLOCKED action in a PARTIAL operational receipt, with no mark, work-item, credit or result write. Only then can the dedicated materializer commit. Lost-response status and exact UUID replay return the original receipt even after the later revision. Verification deliberately reports changed preserved academic evidence after the independent supplementary outcome; it does not invent a historical correction chain or rewrite the original review.
- **Materializer first:** the importer reaches the held catalog lock and then rejects its now-stale review without an import start/final receipt or partial academic mutation. The supplementary service has one materialization and one audit event; explicit repeat execution validates the existing materialization without a second academic write.
- Both orders preserve exact unrelated registrations/results/components, retained practical marks, completed/approved component metadata, lifecycle rows/events, recorded attempt credits, plan assignments/versions, shared course origins, approvals and announcement timestamps. Only the dedicated theoretical/result transition is permitted and its outcome is checked against the existing calculator. The synthetic stale `coursework_total` remains retained, not double-added. Counts and durable before/after provenance prohibit duplicate results, registrations, work items or materialization/import receipts.

Initial fixture attempts exposed empty partial supplementary tables, missing synthetic status/approval columns and overlong auto-generated index names. These were corrected additively in the test fixture only. Initial observer attempts failed to capture a real engine wait and were not counted as acceptance. No production service, grading rule, lifecycle writer, permission or SQL package was changed to make the race pass.

### Inherited failures, reproduced on the same clean base

No historical tests were edited to hide these failures. The comparison worktree is the exact base `39dece16e71cee586d8bac9b8cd87d2f84bf8557` with the same locked dependencies.

- An affected seven-file run executed 180 tests: **155 passed, 5 failures, 20 errors**, identically on head and base. All 25 failures/errors are confined to the existing supplementary registration/partial-theory fixtures: MariaDB-specific runtime/FK readiness assumptions on SQLite, missing scope-mock expectations, and missing timestamp columns in the partial-theory marks fixture.
- The 24-test contract run has **20 passes, 4 failures**, identical on head/base. `SupplementaryExamMaterializationContractTest::{authority_permission_and_data_scope_are_independent_fail_closed_guards, one_offering_transaction_has_deterministic_lock_and_exact_source_provenance, grade_component_mutations_lock_existing_current_and_incoming_offerings}` assert older source strings/format/counts. `StudentRegistrationLifecycleContractTest::test_withdrawal_review_requires_actual_advisor_role_and_assigned_permission` expects `effectivePermissions()` instead of the existing central `hasPermission()` call.
- An earlier 92-test affected registration run had 87 passes, one failure and four errors, also reproduced on the clean base. `SemesterRegistrationEligibilityPhase3GradeBoundaryTest::test_highest_attempt_only_cgpa_semantics_are_reused_for_repeated_course` expects 4.0 where the existing official scale gives 3.5. `AcademicCalendarPhase4RegistrationRequestTest::{test_workspace_stays_readable_but_registration_open_is_false_when_calendar_is_closed, test_workspace_prefers_the_calendar_open_semester_but_keeps_a_closed_request_semester_selectable, test_submitted_request_remains_viewable_during_advisor_review_after_student_cutoff, test_workflow_closed_request_semester_stays_viewable_but_removal_is_unavailable}` lack `recommended_minimum_hours` in their fixture.
- `SemesterRegistrationModificationsPhase5BehaviorTest` cannot load: its private `seed()` conflicts with Laravel's public `TestCase::seed()`, on both base and head. Its assertions were not executed.

The supplementary registration failing methods are `test_phase4_full_runtime_contract_is_ready`, `test_phase4_ineligible_student_registration_is_rejected_without_row`, `test_phase4_wrong_source_is_rejected_without_registration`, `grading_journey_uses_public_services_from_assignment_through_publication`, and `public_grading_boundaries_reject_program_and_university_scope_gaps_atomically`. The errors are its inherited `test_phase4_missing_{offering,student,source,registered_actor,cancel_actor,event_actor,registration}_fk_fails`, `test_phase4_eligible_student_registration_persists_current_row_and_event`, `test_phase4_closed_window_blocks_cancellation`, `test_phase4_summer_fourth_registration_is_rejected_and_count_remains_three`, `test_phase4_cancellation_frees_summer_slot_and_preserves_history`, `test_phase4_window_open_persists_transition_and_audit_event`, `test_phase4_window_open_without_university_scope_is_rejected_atomically`, `test_phase4_window_close_persists_transition_and_audit_event`, `test_phase4_window_close_rejects_empty_current_roster_atomically`, `test_phase4_window_close_rejects_duplicate_target_within_current_roster_atomically`, `test_phase4_second_period_close_rejects_target_already_fixed_in_another_period_atomically`, and `review_approve_and_publish_fail_closed_when_latest_version_is_ambiguous`; plus `SupplementaryExamEligibilityPartialTheoryBehaviorTest::test_{first,second}_of_two_theoretical_marks_blocks_declaration`.

### Pending visual/live acceptance

The permitted browser inventory was retried again for this acceptance increment: `getState()` returned `{"apps":[],"browsers":[]}` and `createBrowserTab('iab', 'about:blank', {visible: true})` returned `Browser is not available: iab`. An earlier initialization also failed writing browser kernel assets. No workaround or alternate automation bypass was used. **React connected to a real isolated Laravel database in both Exam Affairs and administrative VP contexts, 390/768/1440px screenshots/reference comparison, and actual transcript PDF generation/download/opening remain unexecuted.** Filtering, server pagination, details/back/context, manual-entry capabilities, authorization loss and errors remain live-browser checks even though their synthetic component/HTTP contracts were exercised. The corrected/credit/absence PDF marks/hours/totals/Arabic/pagination acceptance remains pending. Private workbook images are spreadsheet QA, not application screenshots. Keep the PR Draft pending review and these release checks.

### Downloadable private review delivery

The PR body's **Downloadable review artifacts** section links an encrypted archive and a non-identifying inventory. These are review assets on an **unpublished draft GitHub release**, not a software release, deployment or production operation. A repository collaborator/owner must authenticate to view/download a draft release. There is no automatic expiry; the owner can remove the review assets after acceptance. Do not publish the draft release or upload its unencrypted payload.

The archive contains the unchanged immutable v4 bundle, complete snapshot projection, enriched 16,461-case JSON, full XLSX/row data, all eight original handoff files (including CHECKSUMS), the three exact pinned input files, reproduction helpers, actual verification logs/ledger and MariaDB concurrency evidence. The inventory records each filename, size and SHA-256 plus the exact final code SHA. Workbook QA images are labeled separately. No application screenshots or downloaded transcript PDF are included because those checks were unavailable; the actual browser failure and exact pending list are included instead.

Encryption uses OpenPGP **AES-256**, a random 256-bit key and iterated/salted SHA-512 S2K. The key is delivered directly to the requester separately, never in Git, the PR, the release or the archive. Archive entry hashes, authenticated decryption/ZIP round-trip and retrieval through GitHub are verified. Preserve private extraction permissions and never import the included university SQL into a test database.

```powershell
# Authenticated repository owner/collaborator; download only, no apply/deploy.
gh release download pr-179-review-artifacts-20261018 --repo Hkgr/al-rowad-university-system `
  --pattern '*.gpg' --pattern 'download-inventory.json' --dir '<new-private-review-directory>'
# Check the encrypted file SHA-256 against download-inventory.json first.
# Interactive secret entry keeps the separately delivered key out of shell history.
gpg --output '<new-private-review-directory>/review.zip' --decrypt '<downloaded-archive>.gpg'
```

Preserve all earlier artifacts and receipts. Review the archive's source pins and blockers. The immutable bundle embeds the original preparation paths; a different operating environment must use a **fresh** preparation with its actual local pinned paths, never hand-edit an issued bundle. Then run a fresh authorized **read-only live preview** to a new filename. Only a later separately reviewed application may use its actual current hash/revision and a newly persisted UUID. Neither this offline report nor installing the guarded schema applies academic data or proves live readiness.
