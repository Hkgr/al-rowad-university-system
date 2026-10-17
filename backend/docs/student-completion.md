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
- The replacement `AlRowad-Student-Conflicts-solve.xlsx` (SHA-256
  `f9b931e9690e80dd515a80cf7bfd01bb799de3459f45d770d1f8da0c3aff0932`),
  and the supplied local SQL snapshot. The previous unresolved-workbook policy
  is superseded for this new review; its old operation receipts remain unchanged.

`Data` is the identity master. `FilterVac` is a display copy, not evidence of an
active administrative status. Derived transcripts, login cells, macro sheets and
course-catalog displays are not additional authoritative examination attempts.
The reader loads only selected columns in 250-row chunks and uses cached formula
values; a missing cache remains unknown. Aggregate reports still require memory
for the complete population; the revised files were prepared with a **768 MB
CLI memory limit**, not a production PHP configuration change.

The private configuration carries reviewed number-and-name identity decisions,
accepted name variants, program aliases (including college-qualified aliases)
and explicit creation-name fields where approved. No real student identity is
hardcoded in the repository. A number swap never applies to another name. The
reviewed canonical-number/program disagreement remains a required academic
transfer, not a duplicate creation or a direct program update. An unresolved
field that still lacks an authoritative resolution stays narrowly blocked. Source originals,
normalized values, source file/sheet/row references and all identical-copy
references remain in the private bundle/report. Rows without a number are
reported separately; they are not assumed to be distinct identifiable students.

The bundle is deterministic. Loading it rechecks file fingerprints and reconstructs
the normalized content from the original files and private decisions; editing a
report/bundle to remove a blocker is not accepted. Content hashes bind requests;
they **do not replace the installed, increasing catalog revision**.

### Revised administrative precedence (source contract v2)

`E` resolves only the fields it actually addresses. Remaining fields use `C`,
backed by the latest structured Student Affairs roster. Blank E is approved C
fallback, **not** automatically “waiting for administration.” The workbook's old
blank-cell instruction does not override that approved policy. “Not present in
Student Affairs” does not supply an identity, date, program or status.

The private configuration lists each interpreted review row, exact A:E values,
approved targets and fields, E passages or C roster references, plus exact
source-record number/label/father/program/college context. Preparation validates
these against the pinned files. Original text, hashes and cell references remain
available. A JSON key-order change is not a changed source reference.

No fuzzy/global name aliases are introduced. Distinct people sharing a short
name/source number are disambiguated only by the recorded context and explicit
decision. Unknown records remain in `unassigned_source_records`; they are not
assigned to either person by guesswork. Competing reviewed owners are documented,
not merged. Existing registration/result ownership is never reassigned.

Structured first/family/father fields remain separate. An unlabelled three-part
display name does not license mechanical splitting, duplicating a compound first
name or inserting the father's name into the family name. Unresolved individual
fields remain visible without unnecessarily blocking an independent approved
field correction. A program-only E does not resolve a name discrepancy.

Explicit current administrative discontinuation and examination-proved absence
are different authorities. The former uses E's approved frozen status (including
at missing-student admission) without inventing examination periods, and overrides
an older source's active flag. The latter still requires the complete examination
proof below. Existing archived/nonactive/protected records and live administrative
blockers remain protected. There is no automatic reactivation.

**Changed sources/decisions require a new bundle, new authorized live review and
NEW operation UUID. Never resume an old UUID with this replacement workbook.**
Status can still read old receipts; genuine original v1 bundles retain their
original source-shape read/replay compatibility. Old receipts are not rewritten.

## Interpretation and readiness

| Area | Rule |
| --- | --- |
| Population | Union of scoped existing students, including archived records, roster identities and examination-only identities. Out-of-scope existence is unavailable, not reported as a missing student. |
| Examination context | Match student, course identity, chronological attempt, academic year and the actual registered offering/semester. Curriculum term/parity never proves the examination semester. Annual ambiguous rows and undated supplementary evidence stay blocked. |
| Complete absence | A nonempty expected set is mandatory. A unique finalized canonical academic term with matching registered credit hours certifies the roster; every expected registration must have one unambiguous explicit `-` examination observation. A single row or registration alone is insufficient. |
| Values | Numeric zero is an attended numeric mark, not absence; blank is unknown. Result symbols are not interchangeable with `-`. Conflicting copies, unresolved I/W evidence and inconsistent academic evidence block action. |
| Chronology | Later actual registrations or examination periods, even ambiguous ones, prevent a current-discontinuation write. Undated supplementary evidence cannot be placed on a guessed date. |
| Level | Exact enrollment date within recorded academic-year boundaries first; only when no date exists, a consistent source cohort can support a derived level. Its reference is the last recorded examination year, **not** promotion to the current year. Existing levels never change. |
| Existing student | Creation is a no-op. Only explicitly reviewed first/family/father field differences may be corrected through the normal model/policy, with before/after and decision references. Account, email, program, level and unrelated personal fields are not changed. Creation, bounded correction, derived level and status readiness are independent. |
| Missing student | Require true normal admission fields, exact enrollment date, documented status, installed level, unambiguous program and explicit provable plan context. Cohort alone cannot supply the required enrollment date. |
| Status write | Only active, nonarchived students, to installed active `frozen` (no numeric ID assumption), under explicit administrative authority or full examination proof. Existing protected states and live request/appeal/decision/supplementary/Incomplete blockers remain. No automatic reactivation. |

The already-batched results/approvals are hydrated for GradeService's canonical
`officialAttemptResultStatus()`. An official pass conflicting with an absence
claim for the **same registered attempt** blocks examination-derived status and
records both the source rows and official result/approval references. A later
returned approval is not official. F/Z alone do not prove absence or attendance;
retained Z marks cannot become a pass. Missing marks remain missing.
Consecutive fully proved absent semesters remain current absence: later activity
is evaluated after the **latest** candidate, not after every historical absence.

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

Each student is independently transactional. Successful admission/bounded identity correction/status change,
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
   php -d memory_limit=768M artisan student-completion:prepare --sources=C:/private/sources.json --output=C:/private/bundle.json
   Remove-Item Env:DB_CONNECTION
   Remove-Item Env:CACHE_STORE
   ```

   Record `bundle_hash` from the output. `bundle.json.report.json` is explicitly
   `offline_snapshot_not_apply_authorization`; it does not contain an apply review.

2. In the intended, independently authorized environment, preview with the actor's
   real existing permissions and scope:

   ```text
   php -d memory_limit=768M artisan student-completion:preview --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --output=/private/preview.json
   ```

   Review every proposed action, field, plan context, evidence, blocked row and
   `review_gaps`. Preserve `preview.json.review.json`, `review_hash` and its
   increasing `review.revision`. There is no command that silently accepts all
   unresolved identities or turns them into ready proposals.

3. Apply only the explicitly approved live review, using a new UUID:

   ```text
   php -d memory_limit=768M artisan student-completion:apply --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --review=/private/preview.json.review.json --review-hash=REVIEW_SHA256 --operation-id=UUID --expected-revision=REVISION --output=/private/apply-1.json
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
   php -d memory_limit=768M artisan student-completion:verify --actor=ACTOR --bundle=/private/bundle.json --bundle-hash=SHA256 --operation-id=UUID --output=/private/verification-1.json
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
record-count assertions were then exercised in the focused run. The final combined
ten-class run executed **148 tests / 14,959 assertions: 145 passed, 3 failed**.
All three failures are the old source contracts below, reproduced
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

The **initial, superseded** private snapshot review had 1,008 canonical identities (909 existing, 99
missing), 7,918 registrations, 16,455 source examination rows / 15,887 deduplicated
observations, and 6 separately reported unnumbered rows. Only 3 missing identities
are preliminarily creation-ready; 96 are blocked. No status change is fully
proved. They are not targets for the revised reconciliation. Revised counts and
verification are recorded in the PR and regenerated private report, separately
for existing exam identities, creations, corrections, transfers, administrative
status decisions and examination proof. Neither review performed a real apply.

No full-suite/CI, native Excel UI,
browser/live Laravel integration or production verification is implied by the
targeted CLI tests. The Excel companion was generated with Artifact Tool,
formula/source counts reconciled, previews inspected, then native RTL view/text
metadata added without changing values/formulas/caches; it was not opened in the
native Excel application. No frontend feature is introduced by this work.

## Release boundaries

### Earlier approved-resolution repair verification (superseded counts)

The replacement workbook controls were recalculated: **97 review rows, 45 explicit
solutions and 52 approved C fallbacks**. These are controls, not execution lists.
Its 97 rows describe 98 approved number owners because one explicit decision
identifies two separate people. All private interpretations retain original
cells, field authority, selected source context and competing-owner checks.

The regenerated snapshot report separately records:

| Category | Count |
| --- | ---: |
| Exam-linked identities already present | 749 |
| Ready missing / still-blocked missing | 3 / 96 |
| Reviewed number-owner resolutions | 98 |
| Ready existing identity corrections / actual fields | 15 / 24 |
| Required program transfers (not executed directly) | 4 |
| Explicit administrative discontinued decisions | 3 |
| Ready discontinued admissions within those decisions | 2 |
| Examination-proved current discontinuation | 0 |
| Insufficient examination proof | 717 |
| Source examination rows left unassigned, not merged | 28 |

The union still happens to contain 1,008 identities / 909 existing / 99 missing;
these values were recomputed, not used as targets. Source examination-row counts
include unassigned observations (16,455 total); 15,864 assigned deduplicated
observations are reported separately. A simple **one-sheet** Excel accompanies
the detailed private JSON. Neither is a live review or evidence that a student
was created/corrected/frozen. The missing civil-institute program, missing exact
admission fields, program-transfer workflow, an ambiguous spelling in an
unlabelled name decision, unreviewed names and source ownership remain explicit.

Executed repair verification:

- Final focused suites: **46 tests / 177 assertions, PASS** (including active
  installed frozen status, field-specific fallback, name correction, source
  identity separation, administrative creation, official-pass conflict and
  consecutive absence). Normal UpdateStudentRequest field validation is reused
  under locks for corrections/status writes.
- Expanded 11-class invocation: **163 tests / 15,010 assertions: 160 passed,
  the same 3 historical source contracts failed**. The final missing-interpretation
  assertion was then covered by the focused suites; this is not a claim of a
  green complete repository suite.
- Both old contract classes were reproduced in a clean detached worktree at
  reviewed head `e6b98328c96d927738f14a7fdf3b735a2d35a8cf`: 22 tests / 19 passed /
  the same 3 failed. No old test was edited or skipped.
- The isolated **10.11.18-MariaDB** harness passed again with seven observed
  independent catalog-lock races (now including identity-field changes), exact
  academic material/GPA/transcript preservation, installed admission-trigger
  assignment history and two concurrent same-UUID executions.
- PHP syntax on changed files, Composer validation/platform requirements,
  three static/pure catalog/result-symbol contracts and diff checks passed.
- Real source preparation **and full source-bundle reconstruction** succeeded
  with an intentionally unavailable DB connection and process-local array cache.
  Report fingerprints matched the current projection. The only GradeService
  change avoids constructing a relation loader when approvalStatus is already
  hydrated; it changes no result/approval rule or writer.
- Excel summary formulas matched the JSON; formula-error scan was clear and
  previews were inspected. Native RTL metadata preserves all values/formulas/
  caches. Native Excel rendering, full repository/CI and live production
  preview/apply/verification remain unexecuted.

Use `--sources` with the new private configuration, preserve its new bundle/hash,
obtain an authorized **new live preview**, then use a **new operation UUID** for
any separately authorized future application. Existing five command names and
the runbook sequence above are unchanged. No real apply was executed.

No new schema or permission activation is necessary; existing catalog/plan and
Incomplete contracts must already be installed and ready. Deploying backend
code and approving any eventual data operation are separate decisions. Do not
run completion automatically during deployment or from GET requests. Keep this
change's PR Draft, open, unmerged and undeployed until review; there is no new
scheduled task, production import or automatic status sweep.

### Remaining-review repair: ongoing registrations and qualified programs

Baseline inspected and fetched: `15b4409fda9fd99ad1d29f7d0c48649e0472cf1b`.
The two findings were reproduced against that head in a detached, isolated
worktree using the new synthetic regressions: an administrative status proposal
incorrectly stayed ready on an open offering without a result; the institute's
Business Administration resolved to the college program; absent unit context
still resolved through the generic alias. All three regressions failed there.

Administrative E authority still bypasses **examination proof only**. Both live
preview and the catalog/program/student-graph-locked writer use the same pure
projection and the existing catalog-completion distinction: a historical
registration must have a closed offering, an authoritative approved outcome and
a settled F/Z/pass (a pass needs its actual mark). Merely retaining `registered`
does not block closed, officially settled history. Genuine ongoing work produces
`ongoing_registration:<reason>` and `status_change.registration_blockers` with
the registration ID, offering ID and precise reason. Lifecycle protection remains
an additional independent check. Nothing rewrites registrations, results,
approvals or history to remove a blocker. Identity-only proposals remain explicit
and independent and cannot acquire a status action implicitly.

The canonical academic distinction was extracted without changing its original
catalog-completion lifecycle checks or their ordering. Official evidence remains
batched and query-free during snapshot projection. Offering-owned approvals are
selected and fingerprinted by offering, even if a compatible test schema also
contains a nullable student column; that column must not hide the authoritative
approval. Synthetic historical fixtures now contain a genuinely approved settled
F, rather than relying on missing/unapproved results being ignored.

Approved program fields now retain their source-bound unit context. C uses its
structured roster reference and college/institute. E retains its exact decision
and uses an owned supporting `context_reference`, or unambiguous owned roster
context. An E decision can select a different department without changing C's
unaddressed identity fields. A source reference belonging to another/unassigned
owner is not admissible context. `CompletionProgramIdentity` validates the
program's real department/college relationships and returns the canonical program
ID/code and unit. Qualified aliases take precedence; a generic alias is only a
candidate **inside** that unit. An alias cannot replace a conflicting exact unit
identity. Missing, conflicting or ambiguous context yields a narrow
`approved_program_*` blocker, not a guessed program or a transfer. No program is
created, reparented or transferred by this reader.

Private interpretations were regenerated, including explicitly source-bound E
contexts and reviewed source-unit spelling aliases. The four former transfer
cases were inspected individually: **two C-backed institute cases already match
their existing institute program**; **two E-backed program decisions still
require the existing academic transfer workflow**. Neither is executed here.
The source fingerprints, complete snapshot contents, student baselines and all
15 independent identity-correction proposals/24 fields were compared and remain
unchanged. Detailed references and the four-case reconciliation stay outside Git.

Latest private snapshot controls (categories overlap and must not be summed):

| Category | Count |
| --- | ---: |
| Union / existing / missing | 1,008 / 909 / 99 |
| Missing ready / blocked | 3 / 96 |
| Reviewed owners / explicit E / C fallbacks | 98 / 45 / 52 |
| Ready identity corrections / fields | 15 / 24 |
| Genuine program transfers / former false institute transfers | 2 / 2 |
| Administrative discontinued decisions / ready frozen admissions | 3 / 2 |
| Ready existing status writes / examination-proved current absence | 0 / 0 |
| Students with ongoing-registration blockers / referenced registrations | 5 / 12 |
| Reviewed program labels still missing or ambiguous inside their unit | 7 |
| Raw examination rows / assigned deduplicated observations | 16,455 / 15,864 |
| Unassigned source observations / unnumbered rows | 28 / 6 |

The seven program-context blockers do not license new programs or inferred
aliases. Missing admission fields, protected states, identity/source-ownership
conflicts, undated supplementary evidence and incomplete examination coverage
remain visible. The report is a snapshot, **not a live authorization or an apply**.
The final bundle SHA-256 is
`d059b7375b981a5f5004dfd18873315323e862879b66e7aa06b89fc381e8d83f`.
Use this new bundle and an authorized **new live review and new operation UUID**.
Do not resume an older operation with the changed content. Old receipts and old
private artifacts have not been modified.

Actual verification for this repair:

- Final focused four-class run: **60 tests / 291 assertions passed**. Expanded
  11-class run: **169 tests /
  15,066 assertions: 166 passed, the same 3 historical contracts failed**. No
  repository-wide or CI pass is implied. The same three old source-contract
  failures listed above were reproduced at the reviewed baseline (22 tests:
  19 pass, 3 fail); they were not edited or skipped.
- The isolated **10.11.18-MariaDB** harness passed eight real independent-process
  catalog-lock races, including a newly inserted open registration with no
  result competing with an administrative write. The new preview is blocked
  with exact references; an explicitly selected identity correction leaves
  status and complete academic material unchanged. Installed catalog/plan/I
  contracts, historical admission-trigger history, GPA/transcript preservation
  and simultaneous same-UUID behavior were exercised. No claim about other
  database versions or SQLite proving locks is made.
- PHP **8.4.14** syntax checks, Composer **2.8.12** validation/platform checks,
  three dependency-free static/pure contracts and `git diff --check` passed.
  No dependencies were installed.
- Private preparation/full reconstruction ran with a deliberately invalid DB
  connection and process-local array cache. The initial default 128 MB report
  process exhausted memory; the documented process-local `768M` limit completed
  preparation/reconstruction. No production configuration was changed.
- The one-sheet Excel report was regenerated with Artifact Tool; summary
  formulas reconcile to JSON, formula-error scan is clear and SDK previews were
  inspected. The metadata-only native RTL step asserted unchanged values,
  formulas and caches. Native Excel rendering remains unexecuted; an SDK preview
  is not native Excel acceptance.

No production connection, live preview/apply, deployment, frontend work, marks
import, SQL, migration or permission change occurred. Keep PR #173 Draft, open
and unmerged. Resolving remaining academic/source blockers is a separate reviewed
operation, not permission to clear evidence or silently rerun writes.

## Approved follow-up

The subsequent missing-program/source-ownership increment is documented in
[Approved student-completion follow-up](../../docs/student-completion-approved-followup.md).
Its issued v3/v4/v5 bundles remain reconstructible and replayable. New two-file
preparation uses the distinct v6 contract (handoff plus companion SQL only), with
the explicit `followup-course-identities.2026-10-18.v3` mapping revision, fresh
live reviews and new operation UUIDs. Do not modify or reuse the historical
operation described above. See the follow-up guide for exact 768 MB preparation,
catalog-first operating order, explicit resume and current verification commands.
