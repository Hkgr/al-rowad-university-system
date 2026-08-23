# Supplementary Examinations Operations Runbook

## Purpose and safety boundary

This runbook governs one supplementary-examination period from announcement through official academic-record materialization. Operators must use the application and its authorized API actions only. Direct SQL changes, ad-hoc scripts, manual edits to grade or registration rows, and calls made with another person's identity are prohibited.

This document contains no credentials. Use the institution's normal authenticated account, least-privilege role assignment, and audited support channel.

## Roles and separation of duties

| Role | Permitted responsibility |
| --- | --- |
| Vice President for Scientific Affairs | Decide and announce the period within its academic year and semester. |
| Student Affairs | Open/operate registration, review the eligible list, and close registration. After closure, use the list as read-only. |
| Exam Office / Exam Board | Assign graders, open grading, review submitted batches, return with a reason, approve, publish, materialize, and inspect reconciliation. |
| Assigned professor/grader | Enter only the supplementary theoretical mark for the assigned offering, save drafts, and submit or resubmit the complete batch. |
| Academic-record viewer or auditor | Read published/materialized results and audit evidence; no workflow mutation. |

No operator should approve a batch by using a grader's account or edit official records to make a reconciliation report pass.

## Authoritative lifecycle

The period is the lifecycle authority. Its normal states are:

1. `announced` — the period exists but registration is not yet open.
2. `registration_open` — Student Affairs registration actions are allowed.
3. `registration_closed` — the candidate list is closed and fixed; registration changes are no longer allowed.
4. `grading_open` — the already-fixed roster has been revalidated and assigned graders may edit theoretical marks.
5. `grading_submitted` — grade batches have been submitted for Exam Office review.
6. `results_approved` — review is complete; results have not yet been published.
7. `results_published` — results are visible as published but are not necessarily in the official academic record.
8. `results_materialized` — the published result has been written to the official academic record.

`legacy` identifies an imported historical record. It is not a shortcut into the active lifecycle.

Each offering has a grading workflow of `waiting`, `draft`, `submitted`, `returned`, `approved`, then `published`. A returned batch must include a review reason and becomes editable only for the assigned grader while the service reports that editing is allowed.

## Operating procedure

### 1. Announce and register

- Verify the academic year, semester, period name, and registration dates before announcement.
- Student Affairs opens registration only through the governed period action.
- Review eligibility reasons and the preliminary count. A preliminary list can change until registration closes.
- Close registration only after the operational owner confirms the cut-off. Treat the list as fixed from `registration_closed` onward.

### 2. Prepare grading

- In the Exam Office supplementary-grades page, select the period and inspect program, offering, candidate counts, and the reconciliation panel.
- Resolve a `CONFLICT` before opening grading. Record the period ID, offering ID, issue category, and time in the support case.
- Assign an eligible grader to each offering using the grader selector.
- Confirm “open grading.” This revalidates the fixed roster and exposes editable sheets only to authorized assigned graders.

### 3. Enter and submit marks

- The professor enters the supplementary theoretical mark only. The preserved practical mark is read-only.
- Blank fields are not zero and must never be interpreted or entered as zero merely to complete a batch.
- Save the draft and verify that no “unsaved changes” indicator remains.
- Check the server-provided minimum, maximum, and step constraints.
- Submit only when every candidate has a saved theoretical mark. Submission sends the complete offering batch and closes ordinary editing.
- If the Exam Office returns a batch, read the reason, correct the draft, save it, and explicitly resubmit the new version.

### 4. Review, approve, and publish

- Compare registered and graded counts and inspect candidate rows before acting.
- `submitted` batches may be returned with a mandatory reason or approved.
- Approval confirms the reviewed batch; it does not publish or materialize it.
- Publishing makes the approved result a published supplementary result. The UI must still show it as awaiting official materialization until that separate action succeeds.
- Preserve all submission versions and review reasons. Do not delete or overwrite audit history.

### 5. Materialize the official record

- Before materialization, refresh the queue and reconciliation report.
- Confirm the expected registered, graded, published, and materialized counts.
- Materialize only a published, conflict-free offering for which the service explicitly reports `can_materialize`.
- The operation preserves the original practical mark and applies the published supplementary theoretical result through the governed service.
- An `already_materialized` response is idempotent success; do not retry through an alternate path.
- Refresh again and verify the materialized count and the period reconciliation status.

## Immutability and correction policy

- The roster becomes immutable when registration closes (`registration_closed`). Registration changes after that point require a separately approved future correction process.
- One regular academic attempt cannot be fixed in two supplementary periods. If closure reports a duplicate-period conflict, leave the later period open and cancel the conflicting registration through the governed registration action before trying again. A materialized attempt cannot enter another supplementary workflow.
- Preserved practical marks are immutable in the supplementary workflow.
- Submitted versions, approval/publishing decisions, actors, timestamps, and return reasons are audit evidence and must remain append-only.
- Published and materialized results are not edited directly.
- Configuration remains available for future academic activity. Only the grading-policy/status semantics selected by a fixed current workflow, or referenced as official historical provenance, are protected from meaning-changing edits.
- `results_materialized` is terminal. Registration, grading, assignment, publication, offering, and period mutation paths reject further changes; reconciliation remains available for audit.
- Grade correction or reversal after publication/materialization is intentionally deferred to a dedicated, authorized correction workflow. Until that workflow exists, stop and escalate; do not improvise a repair.

Rollback of a deployment, database transaction, or failed materialization attempt is not a grade reversal. A technical rollback only restores the operation's prior technical state. It does not authorize changing a student's published or official result, and it must not be presented to operators as an “undo grade” action.

## Reconciliation report

The reconciliation endpoint and UI are read-only. They may be refreshed, but they must not offer a repair or write action.

| Result | Meaning | Operator response |
| --- | --- | --- |
| `PASS` | Governed records and counts agree. | Continue if all other state and permission checks pass. |
| `WARNING` | The lifecycle may continue only if the warning is understood and local policy permits it. | Review every issue, record the decision, and escalate uncertain warnings. |
| `CONFLICT` | Records disagree in a way that can make an action unsafe. | Stop approval, publication, or materialization and escalate with identifiers and counts. |

Review the report's counts for offerings, registrations/candidates, grades, submissions, published results, and materialized results. Review each issue by its localized category and referenced period, offering, registration, or student identifier.

For a conflict:

1. Stop the affected lifecycle action. Do not repeatedly click the mutation action.
2. Refresh once to exclude a stale view and save the displayed identifiers and counts in the support case.
3. Check that the selected academic period and offering are correct and that no batch is still being processed.
4. Escalate to the authorized academic-data owner and application support team. Provide identifiers, timestamps, current states, and the stable error/issue category; do not provide passwords or session tokens.
5. The engineering/data owner must diagnose through audited, read-only queries first. Any corrective capability must be implemented and approved as a governed application operation.
6. Refresh reconciliation after the authorized resolution. Resume only when the conflict is absent and the academic owner accepts any remaining warnings.

## Failure handling and handoff

- Distinguish loading, empty data, authorization errors, validation errors, and lifecycle conflicts. An empty queue is not a successful materialization.
- When a request times out, refresh the read model before retrying; the original action may have committed.
- If the response reports a stable conflict or not-ready code, follow its lifecycle requirement instead of bypassing it.
- For handoff, record the academic period, offering, current period/workflow/materialization states, counts, last successful action, actor role, timestamp, and support-case reference.
- Never include credentials, access tokens, complete student exports, or unrelated personal data in a support case.

## End-of-period checklist

- Every offering has an assigned grader or a documented no-candidate disposition.
- Registered, graded, published, and materialized counts have been reviewed.
- No batch remains unexpectedly in `draft`, `submitted`, or `returned`.
- Every returned batch has a reason and a subsequent resolution.
- Reconciliation contains no `CONFLICT`.
- Published results intended for the official record show materialized status.
- Audit evidence and support-case references are retained under institutional policy.
