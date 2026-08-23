# Supplementary examination official materialization - Phase 6

This is the manual phpMyAdmin deployment package for `alrowad_uni_rust`.
The application and automated tests do not execute these scripts. Back up the
database before a deployment and retain the final result table from every step.

## Run order

1. Run `00_preflight.sql` as one execution in phpMyAdmin's **SQL** tab.
2. Continue only when the last row is exactly `OVERALL | READY`.
3. Run `01_apply.sql` as a separate execution. It recomputes its own guards and
   does not rely on variables left by preflight.
4. Continue only when the last row is `OVERALL | APPLIED` or
   `OVERALL | ALREADY_APPLIED`. Stop on `OVERALL | BLOCKED`.
5. Run `02_verify.sql` as a separate read-only execution.
6. Accept the deployment only when the last row is exactly `OVERALL | PASS`.

Use the SQL tab rather than phpMyAdmin Import when collecting evidence, because
some Import configurations suppress ordinary `SELECT` result sets. A generic
success banner is not a substitute for the final operator row.

Every application object is fully qualified. The scripts do not use
`DATABASE()`, `SIGNAL`, `DELIMITER`, or stored procedures. Their session
variables are local to one file; no file relies on variables from an earlier
execution. Signed integer compatibility is checked semantically with
`data_type = 'int'` and `column_type NOT LIKE '%unsigned%'`, never by display
width.

## Objects and ownership

Phase 6 adds these tables when absent and compatible to create:

- `supplementary_exam_materializations`: immutable structured source, target,
  canonical policy, authoritative regular approval, preserved registration
  status, practical-component evidence, and before/after result provenance.
  The practical-component snapshot is protected by a database JSON-validity
  check.
  Unique constraints cover the Phase-4 registration, Phase-5 grade result and
  immutable publication event, source submission/version/registration tuple,
  target registration, and target result.
- `supplementary_exam_materialization_events`: one immutable success event per
  materialization.

Created tables use the comment
`owned:supplementary-exam-materialization-phase6`. A pre-existing object is
classified as `COMPATIBLE` only when its full semantic contract is safe;
otherwise it is `CONFLICT` and apply is blocked. Compatible adopted objects are
not relabelled. Additional non-unique indexes created for foreign keys are
tolerated, while an unrecognized unique index is treated as a conflict because
it could reject a valid materialization batch.

The package adds exactly one permission:

`supplementary_exams.results.materialize`

Its only default role mapping is `exam_officer`. Existing permissions mapped to
another role are conflicts. Created permissions use the ownership marker in
`permissions.description`; compatible adopted permission/mapping rows are
preserved.

## Academic behavior

The application materializes one complete published offering in one database
transaction. It updates the existing `student_course_results` row rather than
creating another registration or academic attempt. It changes only the official
theoretical total, canonical final mark and result status, calculation actor/time,
plus the original registration's matching result status. Practical total,
coursework total, deprivation state, announcement timestamp, practical component
rows, attendance, and registration identity are preserved.
An older official row whose nonzero practical total cannot be reconciled exactly
to required, approved practical component rows is rejected rather than inferred
or rewritten.

The exact immutable Phase-5 publication event, publication/update timestamps,
the latest regular approval identifier, and locked component evidence form the
available source/target drift guard.
Before a period becomes `results_materialized`, the application re-locks every
materialized official target in that period and verifies its recorded after
snapshot; a drifted earlier target keeps the period non-terminal.
Because the current schema stores these timestamps only to whole-second precision,
a same-second external write remains a residual limitation; relational and
before/after provenance checks still fail closed on detectable mismatches.

The Phase-5 source mark and submission remain immutable. Transcript and GPA read
the canonical existing result path; this package creates no parallel academic
aggregate tables.

## Rollback is not grade reversal

`03_rollback.sql` is a deployment rollback tool only. Any provenance row, event
row, or period already marked `results_materialized` returns
`ROLLBACK_RESULT | BLOCKED_IN_USE`. Current terminal period state and terminal
period events are both treated as evidence of use. The script does not restore
old grades and never updates or deletes regular academic results.

Only empty Phase-6-owned tables and the owned permission/mapping can be removed.
Adopted objects return `ROLLBACK_RESULT | BLOCKED_ADOPTED` and remain untouched.
No owned objects returns `ROLLBACK_RESULT | NOTHING_TO_DO`; a safe removal returns
`ROLLBACK_RESULT | ROLLED_BACK`.

An official correction after use requires a separate, authorized and audited
academic correction workflow. The deployment rollback must never be used as an
academic undo operation.
