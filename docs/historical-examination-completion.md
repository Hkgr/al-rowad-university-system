# Guarded historical examination completion

## Scope

Base: develop `cc9ee640d9491509d6e4c6e06404e58c5bb61577` (merged PR #176). Backend and CLI only. No route, frontend, new permission, schema, SQL package, dependency change, source overwrite or production connection/application. The supplied SQL is parsed as INSERT **data**, never executed/imported. Source fingerprints and observation controls are reconciled, not live execution constants.

The existing Exam Affairs manual-recording preparation, offering context, registration, component, grade-part, approval, Incomplete and plan guards remain authoritative. This workflow never submits, approves, announces, recalculates historical decisions or replaces a conflicting result. Existing value conflicts are private **review-only correction proposals**. It does not grant I, manufacture zeros, transfer a student, change identity/status/level/plan assignment, create a course or reinterpret an old receipt.

## Contracts and interpretation

`historical-exam-completion.sources.v2` contains immutable input hashes/paths, original cached cells and formula text, exact file/sheet/row/column references, row hashes, the supplied snapshot's academic rows and a separately identified `historical-exam-identities.2026-10-18.v1` authority profile. Interpretation is explicitly versioned as `historical-exam-reconciliation.2026-10-18.v2`; approved personal/academic decisions are unchanged. Local preparation requires only the three current inputs, not an old handoff/configuration or another machine's private artifacts. Re-loading re-extracts the original inputs and compares their canonical content. The loader also binds the actual immutable bundle-file SHA-256 into the live review.

**Artifact compatibility:** the v1 source/review contracts are rejected, not reinterpreted. Preserve all v1 bundles, reports, reviews, UUIDs and receipts. Status remains an authorized read of an existing operation; do not pair a new bundle with an old operation or edit a contract/hash/review file. This repair requires fresh preparation, a fresh authorized live preview and a newly persisted UUID for a genuinely new reviewed operation. Inspect any prior uncertain/interrupted operation and current academic evidence first. No old receipt is deleted, rewritten or silently resumed under the new interpretation.

The original exam1 `Data` sheet is retained separately as `student_master`, never as grade observations. Its verified columns are C full name (`الاسم والكنية`), D father, L enrollment date, P number, Q college/institute and R program/department. Only those relevant cells, their original values/formulas, exact references and fingerprints are retained. Resolve the observation's approved number/person ownership first; then independently qualify numbered Data records against that identity and actual college/program relationships. Compatible duplicates retain every reference. Conflicting ownership, father, college, program or explicit examination context blocks. A record belonging to the legitimate owner of an old misattributed number is not redirected to the corrected target. No name-only match, current/default student-program substitution, program transfer or enrollment-date write is introduced. Existing registration periods/context remain protected; Data cannot clear a conflicting registration in another program.

OOXML is read without calculation. VBA compressed source is decoded from the actual CFB chained streams with the existing PhpSpreadsheet readers; project codepage, original module bytes and hashes are retained privately. VBA and source instructions are never executed. The inspected source macro matches raw number/code across years, chooses the highest supplementary mark, writes L/O/Q without replacing K and derives counters from row order. SearchFM selects the last displayed course-name row. None of these behaviors is an application attempt/date/GPA policy.

The reviewed number/person decisions and exact-row ownership rules include the separately approved exam2 row 421. They retain source numbers, names and references **privately**. The three explicitly deferred rows remain deferred. The three bounded missing-number rows require the unique existing first/surname/father identity and Software Engineering context. The approved Business and institute Civil identities stay separate. The checked-in policy contains source/person/target fingerprints and exact references, not a plaintext student roster. A private comparison against all 16,461 original rows confirmed identical ownership decisions after this privacy boundary. Synthetic tests replace only a private property through reflection; there is no production constructor option, request flag or source-file policy override. Qualified program matching reuses `CompletionProgramIdentity` and the existing catalogue aliases. A missing program can be supplied only by a uniquely associated **existing registration**, qualified against its actual college; never by guessing the student's current/default program.

Course matching requires subject, credits and scoped evidence. Historical registrations may prove historical context even when a current membership is inactive. Already approved Civil/university-life mappings are read from `FollowupCourseIdentity`, including the pinned ID 409 and original program-qualified curriculum references. Pinned university-life reuse additionally requires the active origin and an actual active university membership; a same-department or historical-registration fallback cannot replace that scope. The original research subject remains a distinct institute identity despite its colliding original code. Original source codes remain visible. Changed subjects, credits, identities or unresolved contexts block rather than creating/altering an origin.

The existing `engineering-distinct-codes` approval is also reused, without changing catalogue publication or approved profiles:

| Program | Original subject/code/credits | Existing target | Approved source reference |
| --- | --- | --- | --- |
| RU1-TI-CE | فيزياء عامة / TI112 / 4 | TI-CE-112 | exam1:DBMater:824 |
| RU1-TI-CE | كيمياء للمهندسين / TI113 / 4 | TI-CE-113 | exam1:DBMater:825 |
| RU1-TI-E | فيزياء عامة / TI112 / 3 | TI-E-112 | exam1:DBMater:853 |
| RU1-TI-E | رسم هندسي / TI113 / 4 | TI-E-113 | exam1:DBMater:854 |

These four matches require active, subject/credit-matching origins and actual program-unit context. A mismatch cannot fall back to a colliding generic TI112/TI113 origin. The original workbooks' DBMater rows were checked read-only to establish those references.

The private report uses the requested classifications: `existing_equivalent`, `missing_registration`, `missing_result`, `missing_component`, `existing_value_conflict`, `source_conflict`, `ambiguous_attempt`, `unresolved_course`, `unresolved_period`, `deferred_identity`, `protected_result`, `pending_approval`. Multiple conflicting observations never become additional attempts; exact duplicates retain every source reference. Counters, row order, M/N and filenames do not prove attempts, grades or years. A direct equal-total/F-versus-passed candidate is explicitly not full identity acceptance.

## Periods, components and official results

- Existing registration periods win. More than one candidate attempt in the source year remains blocked without actual semester/attempt evidence.
- For a missing registration, the reviewed fallback requires one actual approved guidance-plan placement matching source D. Odd terms 1/3/5/7 map to installed semester order 1; even terms 2/4/6/8 to order 2. The year comes from source R. The receipt records placement IDs, plan version, rule and administrative authority, explicitly **not a verified examination date**. Missing/conflicting guidance blocks. Summer and exact dates are never inferred.
- Supplementary rows remain undated; a possible parent year is not a sitting date. A folded supplementary total/indicator blocks ordinary completion rather than using K as the supplementary mark or applying one mark to several years. Independent regular rows can still complete.
- Components are matched using explicit actual component names/roles and limits, not a 40/60 assumption. An explicit `أعمال الفصل` practical component may receive source J; an arbitrary component merely called `العملي` is not evidence that J belongs there. Unsupported types/definitions/policy combinations, missing/symbolic marks and contradictory totals/outcomes block. A representable theoretical-only context can use canonical preparation when the actual course/policy supports it.
- `ExamComponentMapping` owns the single source-to-required-component map used for marks, proposals, aggregates and outcome checks. Like canonical grade-part finalization, required component **types** own `theoretical_total` / `practical_total`. J is not universally practical and K is not universally theoretical. `coursework_total` is retained legacy metadata, not a third required-component type or an additional summand; canonical finalization retains its existing value or initializes zero. No stored aggregate is rewritten. Unresolved roles block interpretation rather than proving an existing numeric value wrong. Read equivalence is separate from mutation permission: an exact officially approved result is a no-op, while `mutation_blockers` still records its write lock.
- NULL, dash, zero, F, Z, W, I, equivalency and ordinary missing data remain distinct. All lifecycle-owned I states are protected. Existing complete marks/results/approvals and announcement times are never corrected by default apply.
- **Saved output means draft components/registration context, not an official result.** A missing result with complete components and no missing mark awaits canonical finalization; this workflow does not fabricate a result/approval to close it. Existing unapproved results remain pending. Canonical part submission, review, approval and offering finalization are mandatory before official readers/GPA include a newly completed result.

## Authorization, atomicity and receipts

The existing `ExamManualGradeEntryAccess` is used, including active actual role/assigned permissions, the existing administrative-office delegation and super-admin boundary. Student, course and offering scopes are checked independently. Status and verification reauthorize the original actor and every reviewed target. No new privilege/grant or generic bypass exists.

`historical-exam-completion.review.v2` binds the actor's scoped projection, original bundle-file/source hashes, canonical bundle content, observed catalogue revision, exact proposals, qualified Data evidence and complete target baselines. The same Data/component interpretation is used in persisted preview, locked revalidation and receipt verification. A snapshot report is not live authorization. Apply obtains the shared catalogue lock before student/course/offering/approval/registration/component/result locks and repeats authorization/baseline validation. Each attempt's canonical preparation, missing marks, audit and action receipt are one transaction. An audit/guard failure rolls that unit back, while independent eligible work can continue. Existing/new plan-link guards are never disabled.

Append-only start/action/result receipts use the existing chunked `CompletionReceipts` audit facility with `exam_start`, `exam_action`, `exam_result` stages. Actor, original reviewed intent, operation UUID and deterministic per-action UUIDs are bound. Matching completed operation results are returned **after authorization/locks but before current-revision rejection**. Different intent with the same UUID conflicts. Another concurrent invocation seeing an unfinished start receives a controlled explicit-resume requirement, not an automatic continuation or another write.

An explicit resume retains the original bundle/review/UUID, validates completed actions' exact current content and remaining baselines, and uses the explicitly supplied current revision. Only context rows created and exactly receipted by this same operation can account for later shared-context creation; arbitrary external differences cannot be normalized away. Lost responses are never retried automatically. A started operation with no final receipt reports `INTERRUPTED`; a final result with blockers stays `PARTIAL` and requires a new reviewed operation to change intent.

Protected pre-existing academic rows are hashed by stable IDs into the start receipt. Only specifically reviewed NULL marks are excluded from that protected set, and their entire post-write rows remain checked by their action receipt. Verification checks both retained evidence and exact completed target content; new rows cannot hide a missing old row. Subsequent legitimate academic changes may invalidate verification and require review; the verifier never repairs them.

## Operator sequence (not executed on production)

Use a private non-public directory **outside the checkout**, restricted to authorized operators. Preserve all originals, previous reports/reviews and receipts. Use unused filenames; writers refuse overwrites. Arrange a tested database backup and a maintenance window for the affected academic writers before a separately authorized apply. No SQL snapshot import, schema install or permission synchronization is needed by this feature.

From `backend` (replace all placeholders with actual reviewed values):

```sh
php -d memory_limit=1536M artisan historical-exams:prepare --snapshot=/private/input/alrowad_uni_rust18-10.sql --snapshot-hash=3b2da3b6d7ef1dbb075ce81ee69a76693ca78938527b3d90730b05f89d1c1f33 --exam1='/private/input/data 2023@2024.xlsm' --exam1-hash=38460d1f3ba6573d3df859787ae9c54076c87e684b568631a634ad2617854b73 --exam2='/private/input/data 2025.xlsm' --exam2-hash=7be23fb22a748eb9b613987e64fa4dfd40086ade5a9fea5a2d046e0a42805fce --output=/private/review/exam-bundle-NEW.json

php -d memory_limit=1536M artisan historical-exams:preview --actor=<authorized-actor-id> --bundle=/private/review/exam-bundle-NEW.json --bundle-hash=<recorded-file-sha256> --output=/private/review/live-preview-NEW.json
```

The actual attached filenames omit `(1)`/`(2)` but their hashes match the request. For this repair use new names such as `exam-bundle-v2-NEW.json`, `live-preview-v2-NEW.json` and `apply-v2-NEW.json` throughout the commands above/below, never the old v1 artifacts. Review counts as **observations versus distinct proposed actions**, all provenance/blocks, component mappings, current revision and `review_hash`. The `.review.json` sidecar is the immutable action set. Do not edit it or substitute the offline snapshot report. A nonzero blocked/partial result is not permission to broaden apply or reuse an earlier revision.

Privately generate and persist a **new operation UUID before sending the first write** (for example PowerShell `[guid]::NewGuid().ToString()` saved to a new private file). Retain it together with the reviewed bundle/hash, review/hash and expected revision. Only after explicit operator approval:

```sh
php -d memory_limit=1536M artisan historical-exams:apply --actor=<authorized-actor-id> --bundle=/private/review/exam-bundle-NEW.json --bundle-hash=<recorded-file-sha256> --review=/private/review/live-preview-NEW.json.review.json --review-hash=<reviewed-hash> --expected-revision=<reviewed-live-revision> --operation-id=<persisted-uuid> --output=/private/review/apply-NEW.json

php -d memory_limit=1536M artisan historical-exams:status --actor=<same-actor-id> --operation-id=<same-persisted-uuid> --output=/private/review/status-NEW.json

php -d memory_limit=1536M artisan historical-exams:verify --actor=<same-actor-id> --bundle=/private/review/exam-bundle-NEW.json --bundle-hash=<same-file-sha256> --operation-id=<same-persisted-uuid> --output=/private/review/verification-NEW.json
```

After a lost response, **status first**. A completed matching operation can be explicitly replayed exactly without creating another action. If and only if the known operation is interrupted and its evidence remains valid, obtain/review the current catalogue revision and explicitly repeat `historical-exams:apply` with `--resume`, the **same** original bundle/review/UUID, the explicitly inspected current `--expected-revision`, and a fresh output name. Do not invent an operation, rebase baselines, change hashes, or create a replacement UUID to escape a conflict. Changed content/sources need a new bundle/live review/new persisted UUID, with old receipts retained.

Command exit codes: 0 for successful preparation/completed operation; 2 for `PARTIAL`, `BLOCKED` or `INTERRUPTED`; 1 for a controlled validation/authorization/schema/revision/operation error. Output failure after a write may still be an uncertain result: inspect status, never infer rollback.

## Actual verification and private reconciliation

The actual supplied input hashes matched the request. The SQL data controls are 1,007 students, 740 courses, 7,918 registrations and 7,911 results. Cached observations were independently recounted: exam1 regular 9,308 numbered plus six unnumbered; exam1 supplementary 2,044; exam2 regular 4,356; exam2 supplementary 747. Total source observations: **16,461**. Data contributes **295 numbered records / 295 unique numbers**, separately, not additional attempts. The independent raw numbered join reproduces **5,533 blank-program regular observations / 225 source numbers** with unique Data program evidence; those are candidates, not eligibility. The extraction also retained 34,449 / 18,167 stored formula cells and decoded 27 / 26 VBA source modules respectively, without executing them. The declared project codepages are CP1252 / CP1256; original source bytes are retained independently of the display decoding.

Final snapshot-only reconciliation (not live readiness):

| Measure | Review units |
| --- | ---: |
| All review units, including unresolved/blocked source groups | 15,885 |
| Independently eligible missing work | **0** |
| Fully comparable existing no-op | 649 |
| Existing-value conflict, review only | 166 |
| Conflicting source observations, review only | 330 |
| Explicit deferred identity | 3 |
| Unresolved course/context | 6,623 |
| Unresolved period | 2,205 |
| Missing registration, but blocked by other requirements | 1,475 |
| Protected result/evidence | 4,430 |
| Ambiguous attempt | 4 |
| Pending-approval classification | 0 |

These are **review units, not distinct proven attempts or counts of unique students/courses**. Duplicates retain all 16,461 references; the six-unit regrouping is not six new observations/attempts. Conflicts total **496** review units; a candidate discrepancy is not itself approval to correct it. All seven requested equal-total/F-versus-passed investigation references still appear in the conflict report. The 1,475 missing-registration classifications do not imply eligibility. No actual result was saved; therefore no real-data output is newly pending approval. Synthetic successful writes are explicitly `SAVED_PENDING_APPROVAL` and create no official result/approval.

Sequential offline counterfactuals isolate each repaired interpretation (never executable apply reviews):

| Interpretation | Units | Equivalent | Existing conflicts | Unresolved course/context | Missing registration | Protected |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Reviewed 8257451, known defective | 15,879 | 4 | 5,193 | 7,709 | 344 | 88 |
| Component/aggregate repair alone | 15,879 | 664 | 166 | 7,709 | 344 | 4,455 |
| Plus qualified Data evidence | 15,885 | 649 | 166 | 6,659 | 1,439 | 4,430 |
| Plus all four restored TI identities | 15,885 | 649 | 166 | 6,623 | 1,475 | 4,430 |

Unresolved component interpretation now produces mapping/protection blockers, not a fabricated existing-value discrepancy. Data can also expose contradictory context, so not every previously comparable candidate remains a no-op. The private JSON includes observation-level before/after transitions and all blocker groups with counts/references; blocker groups overlap and must not be summed as students or attempts.

Representative remaining evidence: `exam1:OutcomeofMater:2744` is now an exact official no-op with J=30, K=20, L=50 and stored practical=30, theoretical=20, coursework=0. `exam2:علامات:3241` and `3316` correctly resolve TI-CE-112 / TI-CE-113, but their retained supplementary indicators/inconsistent original totals and unavailable faithful component mapping still block writing. `3238` remains a Surveying physics credit mismatch; `3313` remains a Surveying subject mismatch. No source value is corrected to overcome these blockers. Broader blockers include unresolved qualified program/course identity, absent/ambiguous required component semantics, undated supplementary evidence, retained folded totals, and protected academic evidence. A missing mapping can also be a consequence of an unresolved identity; it is not proof that the live database lacks a configuration.

The final private bundle and detailed JSON/Excel are stored outside Git. Real source files were not changed. This snapshot currently establishes **no eligible apply action**; do not broaden scope to overcome component/identity/period evidence blockers. A fresh authorized live preview is required, and may have different blockers or counts.

The review repairs were first reproduced with three failing/erroring regressions at 8257451: realistic practical coursework storage, missing Data extraction, and absent TI transformations. The final targeted/affected run passed **271/271 tests, 3,860 assertions**; the historical-behavior subset passed **51/51, 1,161 assertions**. Five static/pure contracts passed. All nine PHP files changed in this repair passed syntax checking; Composer validate/installed platform checks and `git diff --check` passed. The additional 22 registration/progression source-contract tests have **19 pass and the same three inherited literal-assertion failures** (`effectivePermissions()` twice and `ProgramCourse::query()` once), independently reproduced again at clean base **cc9ee64**. No historical test was weakened/skipped. Runtime is PHP **8.4.14**, Composer **2.8.12**. Application dependencies are unchanged.

Isolated testing uses MariaDB **`SELECT VERSION() = 10.11.18-MariaDB`**, downloaded as a temporary test tool from the official archive and checked against its published SHA-256 `1e36bb0834718e56fd53e150978b55429e5da921a97c07c16b41086c0c61099c`. No system database service or supplied dump is used. The historical runner exercises canonical missing-context creation with plan links, four independent grade/submission/registration/catalogue races, same-UUID serialization, committed lost response, explicit resume and content verification. It now additionally exercises real v2 CLI preparation/writer/loader, Data-qualified live preview, locked canonical creation from the persisted review, exact replay before stale revision checking, read-only verification and unchanged source hashes. A concurrent invocation of an unfinished UUID can receive the controlled resume-required response; completed replay returns the original result without another write.

Intermediate fixture failures exposed absent synthetic approval columns/auto-increment metadata and use of the professor/disabled legacy transition entry points. The fixture was completed to match the existing contracts, and the races use the actual Exam Affairs submission and canonical locked registration-transition boundaries. No application guard was disabled. On a database where the existing Incomplete package is already installed, the harness validates its runtime schema/trigger contract; it does not reinstall over deliberately populated academic test evidence. The complete existing student/catalogue MariaDB chain and the new historical-examination scenarios passed together; the verified disposable server was stopped.

Reproduce the new behavior coverage from `backend` with `php vendor/bin/phpunit tests/Feature/HistoricalExamCompletionTest.php --filter test_historical_`. From the repository root, `powershell -NoProfile -ExecutionPolicy Bypass -File backend/tests/mariadb/student-completion.ps1 -HistoricalOnly -MariaDbHome <verified-existing-10.11.18-directory>` creates and stops its own marked disposable server. Omit `-HistoricalOnly` to run the existing student/catalogue concurrency chain first. All SQL in these test fixtures is synthetic; no new deployment SQL package is supplied.

The private JSON contains complete original/source/baseline detail; the Excel review is an index and readable summary, not an alternative apply contract. Its Summary formulas were recalculated and matched to the JSON counts; all three sheets were rendered and inspected. The exported workbook was reopened to check its cached summary values, review-row count and error cells. This is spreadsheet inspection, not application/browser or native-Excel acceptance. Neither identifiable artifact belongs in Git or the public PR.

Non-identifying comparison files are checked in under [review-evidence/pr177](review-evidence/pr177/README.md): aggregate before/after classifications, observation-transition counts and all overlapping blocker-reason counts. They were extracted with an explicit allowlist; names, student numbers, individual marks, source-row references and executable artifacts are excluded. Their provenance records the code commit and input/private-report hashes. These read-only offline summaries are not live authorization or apply inputs. The artifact-only follow-up checked JSON consistency/privacy and `git diff --check`; application test results above belong to the preceding code repair, not a new behavioral run.

No live university connection, real-data apply, deployment, frontend acceptance or full CI success is claimed. Official acceptance still requires an authorized fresh live preview, academic review of the actual blockers/mappings and the established approval workflow after any separately authorized draft completion.
