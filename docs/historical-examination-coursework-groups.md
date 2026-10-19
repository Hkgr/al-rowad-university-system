# Historical examination completion: clarified assessment groups (v3)

The separately approved [v4 reconciliation](historical-examination-approved-reconciliation.md) adds scoped staging, explicit correction adoption and contextual credits. This v3 document and its earlier counts remain historical evidence; issued artifacts are not edited or silently upgraded.

## Authority and implementation boundary

The approved academic model is **coursework 40 + original final examination 60**. A practical examination is an activity within coursework, not a third assessment group. Source J is an aggregate, K is the original final-examination mark, and cached L may contain supplementary effects. Source facts, approved personal/course identities, Data evidence, period provenance and all earlier protections are unchanged.

`ExamComponentMapping` separates the academic group/cap/source aggregate from each required component's ID, label, technical type, maximum, weight, stored mark and missing breakdown. Explicit reviewed aggregate labels remain supported, including existing compatibility mappings where academic labels and technical parts differ. Recognized practical/quiz/assignment activities belong to coursework; an activity is never assumed to be an aggregate merely because its technical type is `practical`. The approved detailed 40/60 technical-part profile can group multiple assessments without pretending to know their individual source marks. Unsupported mappings still block rather than prove a numeric discrepancy.

The actual `GradePartWorkflowService::finalize()` sums **raw marks of required components by component type** and checks the default policy's part maxima and existing pass thresholds. It does not apply `weight_percentage`. The historical projection mirrors that behavior, retains weights/maxima/optional definitions untouched, and does not rescale marks or confuse weights with maxima. Required technical types still own `practical_total` and `theoretical_total`; retained `coursework_total` is not an extra summand and is not overwritten to resemble J. No alternate result or GPA writer is introduced.

### Existing detailed assessments

- Complete verified activities are summed and compared with their source group aggregate. A practical examination is included once within that sum. No extra aggregate component is added.
- A real component/group/result/outcome discrepancy remains a review-only conflict. An unchanged grand total cannot conceal differing group marks. The source's J/K aggregates alone cannot identify which of several individual activities is wrong when their sums match; no individual discrepancy or correction is invented without individual evidence.
- A known aggregate with missing activity marks produces `aggregate_known_component_breakdown_missing`. The private review shows the source aggregate, known subtotal, missing IDs and retained component evidence. No residual allocation, proportional/equal split, whole-J assignment to an activity or synthetic zero is permitted.
- NULL/dash/zero remain distinct. Numeric J must be within 0–40 and K within 0–60; out-of-range values remain source evidence and blockers. Existing minimum passing requirements, rounding and F/Z/W/I behavior are unchanged. Folded/undated supplementary evidence is not reconstructed from L or displayed outcome/GPA cells.

### Wholly undefined historical context

Only when there are **no definitions and no retained assessment/result/approval/lifecycle/supplementary evidence**, and all ordinary academic/authorization/mutability checks pass, the reviewed historical boundary may prepare:

| Academic assessment | Cap | Canonical storage part | Meaning |
| --- | ---: | --- | --- |
| إجمالي أعمال الفصل | 40 | `practical` | Imported J aggregate. Internal activity breakdown is unavailable, not fabricated. |
| الامتحان النهائي | 60 | `theoretical` | Original final-examination assessment from K, only with established attempt/period association. |

The current default policy must actually support these maxima; it is never changed by this workflow. A partial, optional-only, conflicting, submitted or approved configuration is not replaced. An unconfigured context with retained results is not interpreted using virtual definitions: its independently established total/outcome conflicts remain reportable, but absent definitions cannot manufacture new component-value conflicts.

`ExamManualGradePreparationService::previewHistorical()` / `saveHistorical()` select this assessment plan at an explicit CLI domain boundary. HTTP callers cannot enable it with an input flag. Ordinary manual preparation keeps its current behavior. Both historical methods reuse the existing authorization, catalogue/student/course/offering/approval/registration/component locks, supplementary/I/plan guards, canonical draft writer and audit. Contact hours, delivery roles, faculty qualifications and instructor assignments are not changed or fabricated. No schema, SQL, dependency or permission change is needed.

Every work unit remains atomic. Shared definitions created by one unit are reused only when this same operation's durable receipts prove their exact content. Explicit resume validates earlier commits before continuing another student; unrelated configuration changes are not normalized away. The action receipt includes the **original reviewed assessment evidence**, and verification compares it canonically with the immutable review as well as checking all retained/post-write content. New marks stay drafts until the existing offering-wide submission/review/approval/finalization workflow completes.

## Contract and operator compatibility

Source/review contracts are now `historical-exam-completion.sources.v3` / `historical-exam-completion.review.v3`, interpretation `historical-exam-reconciliation.2026-10-18.v3`, profile `historical-coursework40-final60.v1`. v1/v2 sources/reviews are rejected, not silently reinterpreted. Preserve their files, UUIDs and receipts. Authorized status remains a read of the existing operation; this change does not upgrade an old operation into a v3 apply/resume.

Use the full [operating sequence](historical-examination-completion.md#operator-sequence-not-executed-on-production) with unused **v3** filenames:

1. Prepare `exam-bundle-v3-NEW.json` from the unchanged three sources and record its actual SHA-256.
2. Generate a fresh authorized `live-preview-v3-NEW.json` and immutable `.review.json`. Inspect current scope, actual blockers, assessment groups/proposals, revision and review hash.
3. Only for a separately approved genuinely new operation, persist a **new UUID before writing**. Apply the exact reviewed bundle/review/hashes/revision; do not reuse an old revision, replace an old operation's contents or edit its review.
4. After a lost response, read status first. Matching replay is deliberate and exact. Resume requires a known interrupted **v3** operation, its same original artifacts/UUID, revalidated receipts and the explicitly reviewed current revision. No automatic retry is introduced.
5. Verify the operation and continue the existing canonical approval cycle separately. A saved draft is not an official result or GPA-eligible completion.

No production preview/apply, deployment or real-data mutation was performed for this change.

## Recalculated snapshot evidence

Fresh private v3 artifacts were generated from the unchanged original sources. The SQL was parsed as data only, not imported/executed; no macro or source formula was executed. Controls remain **16,461 examination observations / 15,885 review units**, plus 295 separate Data records/unique source numbers. Snapshot controls remain 1,007 students, 740 courses, 7,918 registrations and 7,911 results. Review units are not unique students/courses or proven distinct attempts.

| Measure | Previous v2 | Clarified v3 |
| --- | ---: | ---: |
| Independently eligible in offline projection | 0 | **603** |
| Existing equivalent/no-op | 649 | 649 |
| Existing-value conflict | 166 | 166 |
| Source conflict | 330 | 330 |
| Protected evidence | 4,430 | 4,430 |
| Missing-registration classification, including blocked units | 1,475 | 1,475 |
| Unresolved course/context | 6,623 | 6,623 |
| Unresolved period | 2,205 | 2,205 |
| Deferred identity | 3 | 3 |
| Ambiguous attempt | 4 | 4 |
| Pending-approval classification | 0 | 0 |
| Verified detailed groups with known aggregate but missing marks | Not separately counted | 0 |

The 603 eligible units are a **subset** of the 1,475 missing-registration units, not an additional classification. They cover 603 observations / 86 wholly undefined contexts / 87 students / 69 courses. They change offline eligibility from false to true; no source-observation classification changes. Zero missing-breakdown blockers does not mean all source activity details are known: undefined-context aggregates explicitly retain unavailable internal detail, and unresolved/protected contexts are not treated as verified detailed groups. **No registration, mark or result was written in this analysis. Offline eligibility is not live readiness.**

All seven F-versus-passed investigation controls remain conflicts. The 35 overlapping blocker groups include unresolved group/context mapping (11,802 units), existing evidence that prevents initialization (4,071), unresolved qualified program/course identity, missing/symbolic/out-of-limit values, and undated/folded supplementary evidence. See the checked-in [aggregate comparison](review-evidence/pr177/academic-groups-v3/comparison-summary.json) and [all blocker counts](review-evidence/pr177/academic-groups-v3/blocker-counts.json). Do not sum overlapping reasons. Known aggregate/missing breakdown needs actual activity evidence; unknown ownership/subject/period and supplementary associations still need their existing authorized evidence. No extra authority to change academic values was inferred.

Detailed private reports retain representative blocked and eligible evidence, group mappings/subtotals/missing identities, exact before/after transitions and original references. Identifying JSON/workbooks/bundles/reviews remain outside Git. Earlier comparison files and private artifacts are preserved, not overwritten.

## Actual verification

- Before implementation, the initial five clarification regressions had three failures and one error (one passed): complete detailed-group matching, missing-breakdown reporting, undefined shared configuration, and the absent profile contract exposed the old behavior.
- Final affected PHPUnit run: **239/239 passed, 3,697 assertions**. Eleven new group tests cover detailed practical/quiz/assignment sums, non-interchangeable weights, real group discrepancies with equal grand totals, NULL versus zero, no residual allocation, shared aggregate initialization without contact-hour changes, interrupted resume, retained evidence, caps, protected configurations, issued-v2 rejection, and actual HTTP submission/approval/finalization of both aggregate and detailed assessments. Earlier identity/Data/TI/I/supplementary/no-op/draft-GPA tests remain intact.
- Additional registration/progression source contracts: **19 pass / 3 inherited failures, 492 assertions**. The same three literal expectations (`ProgramCourse::query()` once, `effectivePermissions()` twice) were independently reproduced on clean baseline `cc9ee640d9491509d6e4c6e06404e58c5bb61577`, reusing the existing vendor dependencies with App/Tests bound to the baseline. No historical test was changed or skipped to hide them.
- Ten relevant static/pure contracts, syntax for all eight changed PHP files, Composer validate and installed platform requirements passed. Runtime: **PHP 8.4.14 / Composer 2.8.12 / Node 24.11.0**. No dependency was installed.
- Isolated synthetic MariaDB reports actual **`SELECT VERSION() = 10.11.18-MariaDB`**. The student/catalogue chain passed its historical-content, installed-guard, independent registration/catalogue waits, publication, replay/resume and persisted-v6 cases. After the resource-limited combined run, a separate historical run passed completely: independent same-UUID/grade/submission/registration/catalogue waits, committed lost response, canonical drafts, two students sharing one 40/60 configuration through interruption/resume/replay/verification, and actual persisted v3 CLI preparation/preview/apply/replay/verification. Installed catalogue/plan/I guards remained active; the disposable instances were stopped. No real SQL snapshot was imported.
- Resource failures were visible, not counted as passes: one disposable startup failed for lack of temporary-disk space; another combined attempt reached historical tests but hit system paging/memory exhaustion. Stopped test evidence was retained locally, and affected historical checks were rerun separately on available D storage. No unrelated service or system memory setting was changed.
- The first full-JSON private reconciliation/reconstruction attempt also exceeded available paging capacity. A bounded-memory streaming comparison and a later sequential **actual v3 source-bundle reconstruction** succeeded; the latter used an intentionally unavailable database configuration. All three source hashes and the old v2 bundle/report hashes remain unchanged. No source workbook was authored, recalculated or exported in this follow-up.

The aggregate evidence was checked against the private report with bounded-memory streaming, source/reference controls and all seven investigation controls. These are backend/source-data checks, not browser, responsive, native-Excel or production acceptance. No live university connection, real-data apply, merge or deployment is claimed. PR #177 remains Draft, open and unmerged.
