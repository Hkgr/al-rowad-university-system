# Ready student completion and examination-discontinuation review

## Boundary

This is a CLI-only, explicitly reviewed operation. There is no new HTTP endpoint,
UI, permission, migration, SQL installer, account/email creation, mark import or
course registration import. Existing admission, plan, result, Incomplete,
progression and graduation rules remain authoritative. No production database was
connected to or changed during implementation.

Preparation reads local workbooks and a SQL dump **as data**. It never executes the
dump, macros, workbook formulas or instructions embedded in source cells. A
snapshot report is not live evidence and is never an application authorization.
Outputs contain personal academic data: keep them outside the checkout, public
web directories, tickets and PR attachments, under restricted filesystem access.
The commands refuse output paths inside the checkout and refuse overwrites.

## Sources and decisions

The operator's private `sources.json` pins SHA-256 hashes and selected worksheets:

- The latest Student Affairs roster, `قالب_بيانات_الطلاب_الجدد (قبل النهائي.xlsx`,
  not an older alternative. Row 4 is the documented example, explicitly excluded.
- `data 2023@2024.xlsm`: `OutcomeofMater`, `supplementary`, `Data` and `FilterVac`.
- **`data 2025.xlsm` is the actual attached file**, not the request's alternative
  filename `data 2025(1).xlsm`: `علامات`, `تكميلي` and `FilterVac`.
- The reviewed student-conflicts workbook, and the supplied local SQL snapshot.

`Data` is the identity master. `FilterVac` is a display copy, not evidence of an
active administrative status. Derived transcripts, login cells, macro sheets and
course-catalog displays are not additional authoritative examination attempts.
The reader loads only selected columns in 250-row chunks and uses cached formula
values; a missing cache remains unknown. Aggregate reports still require memory
for the complete population; the supplied files were prepared with a **512 MB
CLI memory limit**, not a production PHP configuration change.

The private configuration carries reviewed number-and-name identity decisions,
accepted name variants, program aliases (including college-qualified aliases)
and explicit creation-name fields where approved. No real student identity is
hardcoded in the repository. A number swap never applies to another name. The
reviewed canonical-number/program disagreement remains a required academic
transfer, not a duplicate creation or a direct program update. An unresolved
conflict without a new approved solution stays blocked. Source originals,
normalized values, source file/sheet/row references and all identical-copy
references remain in the private bundle/report. Rows without a number are
reported separately; they are not assumed to be distinct identifiable students.

The bundle is deterministic. Loading it rechecks file fingerprints and reconstructs
the normalized content from the original files and private decisions; editing a
report/bundle to remove a blocker is not accepted. Content hashes bind requests;
they **do not replace the installed, increasing catalog revision**.

## Interpretation and readiness

| Area | Rule |
| --- | --- |
| Population | Union of scoped existing students, including archived records, roster identities and examination-only identities. Out-of-scope existence is unavailable, not reported as a missing student. |
| Examination context | Match student, course identity, chronological attempt, academic year and the actual registered offering/semester. Curriculum term/parity never proves the examination semester. Annual ambiguous rows and undated supplementary evidence stay blocked. |
| Complete absence | A nonempty expected set is mandatory. A unique finalized canonical academic term with matching registered credit hours certifies the roster; every expected registration must have one unambiguous explicit `-` examination observation. A single row or registration alone is insufficient. |
| Values | Numeric zero is an attended numeric mark, not absence; blank is unknown. Result symbols are not interchangeable with `-`. Conflicting copies, unresolved I/W evidence and inconsistent academic evidence block action. |
| Chronology | Later actual registrations or examination periods, even ambiguous ones, prevent a current-discontinuation write. Undated supplementary evidence cannot be placed on a guessed date. |
| Level | Exact enrollment date within recorded academic-year boundaries first; only when no date exists, a consistent source cohort can support a derived level. Its reference is the last recorded examination year, **not** promotion to the current year. Existing levels never change. |
| Existing student | Creation is a no-op. Personal data, user account, email, program and level are not updated. Creation readiness, derived level and status readiness are independent. |
| Missing student | Require true normal admission fields, exact enrollment date, documented status, installed level, unambiguous program and explicit provable plan context. Cohort alone cannot supply the required enrollment date. |
| Status write | Only active, nonarchived students, to the installed active `frozen` code (no numeric ID assumption), with complete current evidence, no later activity, protected state, unresolved request/appeal/decision/supplementary/Incomplete evidence. No automatic reactivation. |

Private reports separate identity/program problems, examination classifications,
raw source-observation counts, deduplicated observations and actual registration
sets. `possible_absence_incomplete_evidence` means **not proven**, not a negative
finding that the student attended. The private Excel companion is a review aid;
only the authenticated live preview creates an applicable immutable review.

## Authorization, history and locking

Every live command requires an active account, existing `students.view` and
`grades.view`, plus actual university/college/department/program scope. Apply also
requires `students.manage`, the normal student policy and actual target program
scope. The existing central super-admin behavior is preserved. Status and verify
check the operation owner and both original and current student/program scope.
No permission is granted by these commands.

The installed catalog control is locked first, then program, student (including
archived lookup) and the existing academic graph's offering/registration/term
lock order. The live row and original immutable proposal are compared again
under locks. Installed revision triggers fence other admission, program,
registration and academic-evidence writers. There is no value-fingerprint-only
revision and no automatic transaction retry.

Each student is independently transactional. Successful admission/status change,
plan handling and its receipt commit together; failure rolls them all back. Known
blocked students do not prevent unrelated ready students. Unexpected failures
stop execution; a disconnected client must not infer rollback.

The narrow `AcademicPlanAdmission::createHistorical()` boundary keeps all normal
model/DB admission guards. Legacy programs retain their legacy context. A ready
program requires exactly one fixed transitional reference and a valid current
approved admission default; that default is never used as historical evidence.
The real default assignment made by admission/DB triggers is retained and ended,
then the reviewed historical assignment is appended in the same transaction.
No assignment/history is deleted, no trigger is disabled and no historical
approval/date is invented. Preparing, archived or unprovable programs stay
blocked. Ordinary admission methods are unchanged.

Exact material digests compare pre-existing results, component definitions and
marks, approvals, announcement fields, academic terms, decisions/events,
Incomplete history, plans, memberships and existing assignments before/after a
student write. They detect changes; they never repair or recalculate history.
Operational receipts use existing `user_activity_logs`, with bounded TEXT-safe
chunks and verified hashes. Raw source names/examination values remain in the
private review rather than being duplicated into the generic activity feed.

## Operator runbook (not executed on production)

Use a restricted directory **outside the repository**. Preserve its bundle,
review, hashes, actor and operation UUID durably for lost-response recovery.
Back up the database before a separately authorized real application. Do not
use a report made against the supplied SQL snapshot as a live review.

1. Prepare locally. The following process-local settings also make the existing
   Laravel boot cache independent of database-backed cache configuration. The
   invalid connection name is intentional proof that preparation does not need a
   DB; restore/unset it before running live commands. Do not change global cache
   settings or the application provider.

   ```powershell
   $env:CACHE_STORE = 'array'
   $env:DB_CONNECTION = 'unavailable_source_preparation_probe'
   php -d memory_limit=512M artisan student-completion:prepare --sources=C:/private/sources.json --output=C:/private/bundle.json
   Remove-Item Env:DB_CONNECTION
   Remove-Item Env:CACHE_STORE
   ```

   Record `bundle_hash` from the output. `bundle.json.report.json` is explicitly
   `offline_snapshot_not_apply_authorization`; it does not contain an apply review.

2. In the intended, independently authorized environment, preview with the actor's
   real existing permissions and scope:

   ```text
   php -d memory_limit=512M artisan student-completion:preview --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --output=/private/preview.json
   ```

   Review every proposed action, field, plan context, evidence, blocked row and
   `review_gaps`. Preserve `preview.json.review.json`, `review_hash` and its
   increasing `review.revision`. There is no command that silently accepts all
   unresolved identities or turns them into ready proposals.

3. Apply only the explicitly approved live review, using a new UUID:

   ```text
   php -d memory_limit=512M artisan student-completion:apply --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --review=/private/preview.json.review.json --review-hash=REVIEW_SHA256 --operation-id=UUID --expected-revision=REVISION --output=/private/apply-1.json
   ```

   Root receipt lookup follows authorization/locks **before** rejecting an old
   revision. The same actor/UUID/content returns the recorded result without
   redoing writes. Different content under that UUID is rejected. `COMPLETE`
   means only the immutable reviewed ready actions completed; consult
   `completion_scope`, `review_gaps` and blocked source students. It does not
   certify the whole university population. `PARTIAL`, `BLOCKED` and
   `INTERRUPTED` are not complete acceptance.

4. If a reply is lost, read status. No automatic apply/replay follows:

   ```text
   php artisan student-completion:status --actor=ACTOR --operation-id=UUID --output=/private/status-1.json
   ```

   An interrupted operation exposes confirmed individual receipts and unknown
   numbers. Missing receipts are not proof that an uncertain write rolled back.
   For deliberate resumption, obtain and inspect a **new live preview** for its
   current revision, then rerun the original apply command with `--resume` and
   that explicitly reviewed current `--expected-revision`. Keep the **original**
   bundle/review/review hash/UUID unchanged. Saved student stages are returned
   before revision comparison; unexecuted stages must still match their original
   row exactly. Changed sources/proposals require a new review and operation,
   never silent rebasing. Use a new output filename each time.

5. Verify without mutation:

   ```text
   php -d memory_limit=512M artisan student-completion:verify --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --operation-id=UUID --output=/private/verification-1.json
   ```

   Verification checks current written fields/assignment, original academic
   material and receipt identity; it does not rewrite a mismatch. Exit status is
   0 for a normal result, 2 for partial/blocked/interrupted, 1 for a controlled or
   unexpected error. Inspect the JSON, not exit status alone. No apply command
   in this runbook has been run against the university's production data.

## Verification evidence

The source-specific review artifacts are private and deliberately absent from
Git. The real supplied files were read locally, including the latest roster;
the SQL dump was parsed, not imported. Preparation succeeded with the invalid
DB connection above and array cache. This establishes offline parsing, **not**
live identity correctness or production readiness of any student.

Synthetic behavior coverage includes conditional identity decisions, missing
programs/creation fields, explicit example exclusion, original values and display
copies, complete/noncomplete one-exam sets, zero/blank/absence, real semester
matching, conflicting copies, undated supplementary/later activity, academic-year
boundaries, protected/archive/Incomplete states, scoped access, historical
admission, rollback, bounded queries, idempotent replay, interrupted result and
explicit resume, and academic-material preservation.

The isolated multi-connection harness is:

```text
powershell -NoProfile -ExecutionPolicy Bypass -File tests/mariadb/student-completion.ps1 -MariaDbHome PATH_TO_EXISTING_10_11_18_BINARY
```

It starts a new loopback-only server and installs **existing** catalog/plan/
Incomplete contracts into synthetic fixtures. It checks process-list-observed
catalog lock contention with status, program, registration, result evidence,
curriculum and student-creation writers; exact academic content/GPA/transcript
preservation; real admission-trigger assignment history; and two simultaneous
same-UUID applications without duplicate admission. It stops only its own
guard-marked server. SQLite tests are not evidence of MariaDB locks.

Executed focused regressions passed **31 tests / 125 assertions**. The expanded
eight-class run passed **126 tests / 14,463 assertions**; the four additional
record-count assertions were then exercised in the focused run. A final combined
run, including the two historical source-contract classes, is recorded in the
PR verification section. Three failures in those old contracts were reproduced
on a clean detached worktree at base `ebb5eff7501df91a00fb59fb5bdf77913d950f14`:

- `StudentAcademicProgressionContractTest::test_canonical_calculators_are_reused_not_duplicated`
  expects `ProgramCourse::query()` instead of the existing plan-context resolver.
- `StudentAcademicProgressionContractTest::test_review_requires_actual_registration_officer_role_and_assigned_permission`
  expects the old literal `effectivePermissions()` call.
- `StudentRegistrationLifecycleContractTest::test_withdrawal_review_requires_actual_advisor_role_and_assigned_permission`
  expects that same old literal in the unchanged permission helper.

They were not edited, skipped or treated as passing. Baseline reproduction ran
both unchanged contract classes: 22 tests, 19 passed, the same 3 failed.
The new multi-connection harness passed on **10.11.18-MariaDB**, including all six
contending writers, real default-trigger history and same-UUID workers. The
existing full `course-result-symbols.ps1` suite also passed on that exact version,
including fallback/installed catalog paths and lifecycle/schema protections.
Three separate dependency-free catalog/Phase 1/Phase 2 contracts passed; these
are static/pure checks, not database behavior tests. PHP syntax (20 PHP files),
PowerShell parsing, Composer validation/platform checks and diff checks passed.

The private snapshot review has 1,008 canonical identities (909 existing, 99
missing), 7,918 registrations, 16,455 source examination rows / 15,887 deduplicated
observations, and 6 separately reported unnumbered rows. Only 3 missing identities
are preliminarily creation-ready; 96 are blocked. No status change is fully
proved. These are snapshot findings, not production counts or executed actions.

No full-suite/CI, native Excel UI,
browser/live Laravel integration or production verification is implied by the
targeted CLI tests. The Excel companion was generated with Artifact Tool,
formula/source counts reconciled, previews inspected, then native RTL view/text
metadata added without changing values/formulas/caches; it was not opened in the
native Excel application. No frontend feature is introduced by this work.

## Release boundaries

No new schema or permission activation is necessary; existing catalog/plan and
Incomplete contracts must already be installed and ready. Deploying backend
code and approving any eventual data operation are separate decisions. Do not
run completion automatically during deployment or from GET requests. Keep this
change's PR Draft, open, unmerged and undeployed until review; there is no new
scheduled task, production import or automatic status sweep.
