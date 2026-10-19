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

## Archived offline reconciliation (v4-04; superseded below)

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

The archived v4-04 report mislabeled six observations as new academic decisions: three already deferred identity observations and three observations linked to deferred equivalency evidence. That interpretation is withdrawn. Their exact deferrals remain binding; no alternate can evade them. The aggregate representation and historical-context implementation gaps are addressed by the v5 increment below, not by requesting those decisions again. Preserve the archived report and hashes as review history, not current operating guidance.

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

## v5 implementation follow-up: historical assessment representation

This is an implementation increment, not another report-only reclassification. Source bundle **v4 remains byte-identical** (`4053a7f87291e7e1f104b89c9e9f75a15dcec97bead486061757f22e36b01fae`). New proposals use `historical-exam-completion.review.v5`. Issued v3 and v4 reviews, stored work, receipts and explicit resume/replay retain their original interpretation; a regression specifically reconstructs v4's folded-absence staging while v5 preserves its already equivalent official result. No original source, decision/profile hash, review or receipt is edited.

### Representation and adoption boundary

`HistoricalAggregateAssessment` distinguishes a genuinely undefined assessment from real required activities. Only an independently identified, registered/completed actual attempt with **no definitions and no component marks**, complete approved J/40 + K/60 + L evidence, a compatible canonical policy, and no conflicting lifecycle/supplementary/identity evidence can produce the new aggregate proposal. Policy minima still determine pass/fail; T is not a course pass. F and a missing final examination retain their separate existing handling. No weighting or retained `coursework_total` is added.

- An already approved, matching final/status is an academic **no-op**, including every result timestamp and existing legacy part value. Missing assessment detail is not permission to rewrite it.
- A missing/conflicting result becomes `historical_aggregate_review` in the **v5 projection**. Durable work uses the installed **`correction_review`** kind; the versioned JSON carries the representation. The unchanged package CHECK/trigger definitions remain enforced. No new SQL or migration is necessary.
- Existing correction review and explicit adoption run under the same authorization, catalog-first locks, immutable baseline, revision/UUID checks, exact replay, audit and preservation receipts. Staging does not adopt, approve or announce a result.
- Adopted JSON retains both sourced aggregates, original/reconciled values, row reference/hash, source decisions, authority hashes and accepted policy; the immutable work also retains the complete original source cells. No activities are fabricated. Legacy theoretical/practical/coursework totals are retained unchanged, not re-labelled. Only the explicitly reviewed final/status is corrected.
- Current PHP GPA, transcript, applicable-credit and SQL readers recognize the validated representation. SQL cents comparisons are numeric, including **100**, not lexicographic JSON-string comparisons. Existing offering approval remains mandatory. Ordinary/legacy recalculation cannot replace adoption; an undefined offering cannot bypass normal finalization's configuration checks. A later credit-only correction retains the adopted assessment evidence.
- Student/exam transcript/PDF share explicit coursework/final labels through the existing presentation helper. Unknown legacy parts display as missing instead of being mislabelled. Public summaries omit original cells, internal decisions/reasons/actors and hashes. The existing shared table/card/PDF layout is retained, not redesigned. References: `StudentTranscript.jsx`, `ExamStudentAcademicRecordPage.jsx`, `resultSymbols.js`, and `transcriptPdf.js`.

Real detailed activities with missing marks still require their exact component IDs/marks; the new path cannot flatten them. This negative case is exercised synthetically even though the established contexts of this supplied snapshot contain no such missing detailed-activity observations. An absent registration or unestablished period remains a context problem, not a request for fictional activities. The remaining imported-work descriptions now state this distinction explicitly.

### Historical program context

A blank examination program may use the unique actual historical attempt **only after** exact person ownership, source-college qualification, actual year, unique registration, and subject/credits are independently verified. A conflicting roster observation remains in the proposal/baseline with its source reference and former resolved program. Multiple attempts, changed subjects/credits, or other unresolved context still block. No current-program default, old numeric ID, student transfer, ownership change or registration relink is used.

The requested conflicting-roster case now produces a separate authorized correction review for the approved **29 + 30 = 59**; its incomplete alternate remains source history. An executed synthetic equivalent proves result correction without changing the person, registration/offering/plan context or announcement time. The actual university snapshot remains unchanged.

### Recomputed complete population (v5-08, offline only)

| Exclusive operational disposition | Observations |
| --- | ---: |
| Equivalent official outcome, no rewrite | 5,881 |
| Canonical draft candidate, normal approval still required | 1,854 |
| Stage only, not adoption | 1,235 |
| Reviewable historical aggregate proposal, not adopted | 174 |
| Retained source history | 478 |
| Previously approved explicit deferral union | 367 |
| Linked to deferred evidence | 3 |
| Blocked | 6,469 |
| **Total** | **16,461** |

There are **15,937** attempt review units, **821** candidate / **747** independently resolved students, **270** candidate course identities and **674** period contexts. No real result is adopted by this analysis. The three deferred identities and three linked equivalency attempts are **not six new questions**. The report has no newly classified academic-decision observations; it does not waive the existing deferrals or evidence/workflow requirements.

The previous **4,909** aggregate-follow-up observations reconcile exactly: **4,663** undefined-assessment official equivalents, **4** equivalent results with retained actual definitions, **174** reviewable aggregate proposals, and **68** still staged (**58** undefined actual offerings; **10** unestablished assessment contexts). Of those 68, 57 overlap an official/submitted offering lock, 47 lack approved guidance context, one requires historical-program review, and one has unproved combined-examination evidence. These are overlapping reasons, not 116 additional observations. A verified actual registration/context or independently sourced final exam is still needed; no missing fictional activities are requested.

| Assessment/context evidence across all observations | Observations | Distinct recorded registrations |
| --- | ---: | ---: |
| Actual offering, absent definitions | 6,366 | 5,995 |
| Real aggregate definitions, missing required marks | 51 | 8 |
| Actual definitions with retained marks | 1,795 | 1,719 |
| Assessment context not established | 8,249 | Not established |
| Genuine detailed activities missing marks, among established contexts | **0** | **0** |

The separate identity/context diagnostic covers **4,812** observations / **553 candidate registrations** with unresolved exact owner, qualified program/course/period, or ambiguous actual attempt. It overlaps retained/deferred evidence; it is not a count of newly authorized student transfers. Official equivalents cover **5,881 distinct registrations**. The full private JSON retains every blocker/reference and the complete old-to-new disposition/reason map; the de-identified public summary is not a substitute for that evidence.

The nine specified matching outcomes (**58**, **72**, and **50/51/65/57/75/62/78**) are exact no-ops in the supplied snapshot. Nine executed synthetic before/proposal/after demonstrations preserve result rows/timestamps. The private case report retains their nine competing observations, including the old all-dash sources, and the separately selected 18/61 alternatives. No source observation was deleted or used to evade a deferral.

### Read-only-first operating instructions

Keep the existing immutable bundle and all old preview/receipt files. If it reconstructs from the unchanged pinned local sources, **do not edit or regenerate it for this projection-only change**. Different local source paths require a new preparation through the existing command, not hand editing. This increment adds no schema/permission deployment step beyond the original guarded package and existing runtime prerequisites.

```powershell
# Template only; no university/production operation was executed in this follow-up.
php artisan historical-exams:preview --actor=<authorized-actor> `
  --bundle='<existing-immutable-bundle>' --bundle-hash=<actual-existing-file-hash> `
  --output='<private-fresh-directory>/live-examination-v5-01.json'
```

Inspect the **actual** blockers, actor, schema readiness, current revision and new review hash. Offline snapshot artifacts have no write authorization. A later explicitly reviewed staging/draft application needs a **new persisted UUID**, its reviewed revision/hash and the unchanged bundle; never reuse an old production revision or invent a resume operation. Old aggregate-follow-up work remains immutable: only a fresh v5 review may create the new correction proposal.

After authorized staging, use the existing `historical-exams:review-correction` read command with the exact work ID and a fresh output file. Only a separate deliberate `historical-exams:adopt-correction --confirmed` with that review hash, expected revision, new persisted UUID and nonempty reason may adopt. Afterwards use `historical-exams:verify --operation-id=<adoption-UUID>` with the actor, unchanged bundle/hash and a fresh output; the command detects the correction receipt. Do not repeat a write to check whether it succeeded. Parent staging verification separately reports currently adopted work. For an uncertain response, read the stored operation first; explicit exact replay/resume is not a replacement UUID or automatic retry.

### Actual verification of the implementation follow-up

| Check | Actual result |
| --- | --- |
| Six-file historical/catalog/plan/workspace/offering-proposal PHPUnit run | **261 executions, 5,088 assertions passed**; includes the new equivalent/no-op, absent-vs-detailed, stale coursework, numeric 100/minima, GPA/transcript/SQL, explicit adoption, stale/auth/replay, legacy-v4 and historical-context cases |
| Node/ReactDOM/router/manual-entry/shared PDF HTML semantics | **81 passed**; deterministic measurement is not a PDF download or visual/layout acceptance |
| Full isolated approved MariaDB runner | **Passed on `10.11.18-MariaDB`**, installed plan/catalog revision triggers, two independent aggregate adopters/one outcome/replay, numeric 100 PHP-GPA/SQL parity, previous competing writers/lifecycle/publication/registration checks, preserved timestamps/approvals and package negative/reapply checks |
| Actual supplementary materialization/import | **Both observed independent engine-wait orders passed**, retained completed/practical components, outcomes and receipts; the existing dedicated test remains in the runner |
| Broader seven-file affected PHPUnit | **155/180 passed**, same inherited 5 failures/20 errors and methods as the earlier clean-base comparison |
| Additional affected contracts | **33/39 passed** across the two groups; six inherited source-string/count failures, not hidden or changed |
| PHP syntax / Composer / schema-render contract / diff | **Passed**; no dependencies, schema package or migrations added/changed |
| Changed-file lint | One existing `react-hooks/set-state-in-effect` error in `ExamStudentAcademicRecordPage.jsx` and one existing `react-hooks/exhaustive-deps` warning in `StudentTranscript.jsx`, reproduced against exact pre-follow-up `f80315a`; no new lint diagnostic |
| Production frontend build | **Passed**, 989 modules; existing >500kB chunk warning |
| Complete private workbook | **16,461 unique references**, 20 filterable columns, summary formulas and typed zero/dash/NULL samples checked, all references/resolution/assessment/change columns reopened; both tabs rendered and inspected; no native Excel claim |

The two additional inherited progression contracts are `StudentAcademicProgressionContractTest::test_canonical_calculators_are_reused_not_duplicated` and `::test_review_requires_actual_registration_officer_role_and_assigned_permission`. The same 22-test group produced 20 passes and the identical two failures on clean base `39dece16e71cee586d8bac9b8cd87d2f84bf8557`. Together with the four previously documented materialization/withdrawal contracts, these explain the six failures; no historical assertion was weakened.

Early MariaDB execution correctly rejected an unsupported new work-kind value; implementation now uses the existing `correction_review` CHECK value instead of changing SQL or weakening readiness. An initial private offline helper lacked Laravel's path-only application binding, and an intermediate workbook builder spent excessive time recalculating dependencies before case population; neither was counted as acceptance. Corrected private tooling produced the final complete immutable outputs and successful rechecks.

The permitted browser was retried: inventory `{"apps":[],"browsers":[]}`, then `Browser is not available: iab`. No bypass was used. **Live React with isolated Laravel, both portal contexts, 390/768/1440px reference comparison, and actual PDF generation/download/opening remain pending.** Workbook renderings are spreadsheet QA, not application screenshots.

The updated encrypted PR delivery adds `reconciliation-v5-08.json`, `approved-exam-review-v5-08.xlsx`, the full fresh projection and typed row data, exact before/proposal/after case evidence, remaining blocker/context diagnostics, final verification ledger/logs, synthetic MariaDB evidence and reproduction helpers. All original v4 delivery assets, source/decision pins and issued hashes remain available. The new archive and a **freshly named** inventory are appended to the same unpublished draft carrier; no plaintext identifying report or decryption key is committed/uploaded publicly.

## Guidance-only review follow-up (2026-10-19)

Reviewed base: `4bee3d1aff00b3197bf61b06efe2310fad77bb17`. The final follow-up SHA is recorded in PR #179 and its separately named guidance verification evidence. No population reconciliation, source preparation, staging or university-data application was run for this follow-up. All **16,461** observations retain their existing classifications and deliverables. The unchanged immutable bundle still has SHA-256 `4053a7f87291e7e1f104b89c9e9f75a15dcec97bead486061757f22e36b01fae`.

### Read contract and Arabic presentation

The historical-work details GET now adds a private, authorized `guidance` object derived only from the immutable stored action. It performs no correction review, creates no evidence and changes no result. It adds no query or scope/permission requirement. It is **not** a current readiness or approval indicator.

| Field | Meaning |
| --- | --- |
| `correction_proposal: historical_source_aggregates` | Stored historical-aggregate classification, no recorded blockers, and the recorded assessment exactly matches the existing pure aggregate inspector using its recorded source values and baseline. The technical `correction_review` work kind alone is insufficient. |
| `correction_proposal: component_assessments` | Stored ordinary correction classification with a complete recorded component mapping and expected totals. |
| `correction_proposal: null` | Neither representation has been established by that stored evidence. No missing fictional activity is inferred from the absence of definitions. |
| `missing_activity_marks` | A real, explicitly mapped assessed activity occurs among the recorded missing component IDs. A missing definition is not an activity. |
| `unproven_examination_evidence` | The observed examination aggregate is missing/unproven, excluding ordinary ungraded work and established final absence. |

Adopted, final-absence and ordinary-ungraded instructions retain priority. Actual gaps receive separate Arabic activity/examination instructions; neither internal blockers nor the English `required_next_action` are rendered as instructions. Unknown/older presentation data receives a conservative context/evidence-review message, not a claim of approval or a request for fabricated activities.

Complete historical-aggregate proposals now display:

> مجموعا أعمال الفصل والامتحان النهائي المرصودان في المصدر محفوظان في مقترح تصحيح تاريخي، دون اختلاق علامات أنشطة. يلزم إجراء مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل. الاستعراض لا يغيّر النتيجة الحالية، وحفظ المقترح لا يعني اعتماده.

Ordinary correction proposals display:

> مقترح تصحيح نتيجة محفوظ للمراجعة؛ يلزم إجراء مراجعة مخوّلة جديدة ثم اعتماد صريح منفصل. الاستعراض لا يغيّر النتيجة الحالية، وحفظ المقترح لا يعني اعتماده.

The existing details table now labels its column **التقييمات المسجلة**. Supported no-definition aggregate rows say **مجموع مصدري محفوظ دون تعريف أنشطة تفصيلية؛ لا يلزم اختلاق علامات أنشطة.** Actual component names/marks, missing marks and source zeros remain visible. There is no adoption button or new mutation endpoint. Existing `IncompleteResultsPage.jsx`, `RegistrationGridRow.jsx` controls and `DataTable` remain the references/components; no layout, global styles or portal navigation were redesigned.

### Actual focused and affected verification

| Check | Result |
| --- | --- |
| Pre-fix reproduction | Existing presentation produced the wrong missing-evidence fallback: the new two guidance tests and four real ReactDOM/router synthetic-component regressions failed (**35/41 passed, 6 failed**). |
| Laravel/service/HTTP historical suites | **230 tests, 6,828 assertions passed** across `HistoricalExamCompletionTest.php`, `ApprovedHistoricalExamReconciliationTest.php` and `HistoricalExamCatalogCreditTest.php`. Includes the actual details projection/HTTP response, all six evidence states, malformed recorded aggregate evidence, adopted detail, authorization/read-only coverage, unchanged result snapshots/approvals/work/bundle, and existing review/adoption/replay protections. |
| Node and ReactDOM/router affected suites | **87 passed**, including both Exam Affairs and administrative VP routes, VP office identity, direct details, filter/page return context, manual-entry link context, no mutation requests, no raw internal instruction display, actual gap messages and existing uncertain-I recovery. Synthetic responses and a no-layout DOM do not prove live browser integration. |
| Modified-file ESLint | Passed for both application files and both modified Node/component test files; no diagnostics. |
| PHP syntax / Composer / diff | Both modified PHP files passed; `composer validate --strict --no-check-publish`, `composer check-platform-reqs` and `git diff --check` passed. |
| Frontend production build | Passed, Vite **8.0.16**, **989** modules. The existing >500kB chunk-size warning remains; no new dependency or asset change. |

Runtime: PHP **8.4.14**, PHPUnit **12.5.30**, Node **24.11.0**, PDO SQLite **3.49.2** with synthetic in-memory fixtures. These selected suites have no inherited failures; broader inherited diagnostics documented above were not rerun or represented as fixed. No MariaDB/concurrency rerun was performed for this read/presentation-only change; the earlier isolated MariaDB evidence remains separate and unchanged.

The permitted browser inventory was retried and returned `{"apps":[],"browsers":[]}`. **Live React + isolated Laravel verification, 390/768/1440px desktop/mobile visual comparison, and actual PDF download/opening remain unexecuted.** No browser-policy bypass, production connection, SQL/migration, mark/selection/policy/credit change, permission change or real-data application occurred. Keep PR #179 Draft, open, unmerged and undeployed.

## Post-merge bounded-memory recovery

PR #179 was subsequently merged. The memory-only follow-up and conditional recovery instructions for an existing interrupted operation are in [Historical examination memory recovery](historical-examination-memory-recovery.md). That fix does not regenerate the source bundle/review, change these archived classifications or authorize a new application/adoption. Read the current operation status and revision before any explicit same-UUID resume.
