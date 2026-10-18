# PR #177: historical examination comparison evidence

**Latest: [clarified 40/60 group model (v3)](../../historical-examination-coursework-groups.md)**, with [new aggregate comparison](academic-groups-v3/comparison-summary.json) and [35 grouped blocker counts](academic-groups-v3/blocker-counts.json). The original v2 comparison files below are preserved as historical before-change evidence. v3 has 603 offline initialization candidates in 86 contexts; this is not live readiness or a real-data apply.

These are non-identifying, aggregate extracts of the recalculated private report at code commit `c915ba2e60edd375d0cff99c1723f441e51afa1c`. They compare the reviewed implementation (`825745177a551c63a871b3ded2599128c02968a5`) with the three repaired interpretations. They are **offline snapshot analysis**, not a live preview, executable bundle/review, or authorization to apply data.

## Files and privacy

- [comparison-summary.json](comparison-summary.json): source controls, final classifications, sequential before/after comparisons and observation-level transition counts. Input, private-bundle and private-report SHA-256 values establish provenance without including their contents.
- [blocker-counts.json](blocker-counts.json): every remaining blocker reason with aggregate review-unit and source-observation counts. The three individually deferred references are grouped as `deferred_identity`.
- [Operating guide](../../historical-examination-completion.md): contracts, safeguards, actual verification and operator instructions.

The original workbooks, SQL snapshot, detailed JSON/Excel reports, bundles and immutable reviews remain outside Git. These extracts exclude student names/numbers, individual marks, per-record examples/references, local paths and operation identities. They cannot replace the private evidence needed for an authorized academic review.

## Before/after comparison

| Interpretation | Review units | Equivalent/no-op | Existing-value conflict | Unresolved course/context | Missing registration | Protected evidence |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Reviewed 8257451, known defective | 15,879 | 4 | 5,193 | 7,709 | 344 | 88 |
| Component/aggregate repair alone | 15,879 | 664 | 166 | 7,709 | 344 | 4,455 |
| Plus qualified Data evidence and retained registration context | 15,885 | 649 | 166 | 6,659 | 1,439 | 4,430 |
| Plus all four restored TI identities | 15,885 | 649 | 166 | 6,623 | 1,475 | 4,430 |

The final classification partition is 649 equivalent + 166 existing-value conflicts + 330 source conflicts + 6,623 unresolved course/context + 2,205 unresolved period + 4,430 protected + 1,475 missing registration + 3 deferred identity + 4 ambiguous attempt = **15,885 review units**. Independently eligible and pending-approval classification counts are both **zero**; neither is forced. Missing registration does not mean eligible. No real-data result was saved or made pending approval.

Review units are not unique students, courses or proven distinct attempts. Source observations remain **16,461**, including six unnumbered rows; adding Data evidence regroups six review units but adds no examination observation. The **295 Data records / 295 unique source numbers** are separate student-master evidence, not grade attempts. The raw unique-number join's **5,533 observations / 225 numbers** are candidates, not eligibility.

### Changes measured per source observation

| Repair step | Classification transition | Observations |
| --- | --- | ---: |
| Aggregate repair | Existing-value conflict to protected | 4,375 |
| Aggregate repair | Existing-value conflict to equivalent | 660 |
| Qualified Data evidence | Unresolved course to missing registration | 1,095 |
| Qualified Data evidence | Protected to unresolved course | 40 |
| Qualified Data evidence | Equivalent to protected | 15 |
| Four restored TI identities | Unresolved course to missing registration | 36 |

These are sequential offline counterfactuals, not independently executable reviews. Transition counts use source observations, whereas the preceding table uses review units; do not equate the denominators.

## Remaining blockers

The JSON retains precise reason-level counts, including unresolved subject/program context, absent faithful component mappings, undated/folded supplementary evidence and protected academic evidence. Reasons overlap: **do not sum blocker counts** as students or attempts. A component-mapping blocker can follow an unresolved identity; it does not establish that a live database lacks configuration. Identity/context contradictions remain blocked rather than being cleared to improve readiness.

## Verification provenance and limits

The preceding code repair at `c915ba2` passed 271 affected PHPUnit tests / 3,860 assertions (including 51 historical tests / 1,161 assertions), five relevant static/pure contracts, syntax checks for nine changed PHP files, and Composer validate/platform checks. The isolated synthetic server reported **10.11.18-MariaDB**; its installed-guard concurrency and persisted CLI preparation/preview/apply/replay/resume/verification scenarios passed. An additional source-contract run retained the same three independently reproduced baseline failures (19 pass / 3 inherited failures). Details are in the operating guide; these results are not a claim that the artifact-only follow-up reran application tests.

For this artifact-only follow-up, JSON parsing, equality against an allowlisted extraction of the private report, classification/source-control sums, exclusion of per-record evidence, and `git diff --check` were checked. No source artifact or executable bundle/review was edited. No live database, production application, browser/native-Excel acceptance or full CI result is claimed.

For a separately authorized new operation, use the current **v3** fresh-preparation/live-preview instructions in the operating guide; the v2 figures above are historical comparison evidence only. Preserve old reports, receipts and UUIDs; do not use any aggregate comparison file as an apply input or substitute an offline revision for a newly reviewed live revision. PR #177 remains Draft, open, unmerged and undeployed.
