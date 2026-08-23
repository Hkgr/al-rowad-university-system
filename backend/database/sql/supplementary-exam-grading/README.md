# Supplementary examination grading — Phase 5

This is a **manual phpMyAdmin SQL-tab deployment package** for
`alrowad_uni_rust`. The application and test suite never execute these scripts.
Back up the database before deployment.

## Operator runbook and evidence

1. Open the `alrowad_uni_rust` database in phpMyAdmin.
2. Open phpMyAdmin's **SQL** tab.
3. Paste and execute the complete `00_preflight.sql` file.
4. Inspect the **last visible result table**. Continue only when it is exactly
   `OVERALL | READY`; stop on `OVERALL | BLOCKED`.
5. Paste and execute the complete `01_apply.sql` file from the **SQL** tab.
6. Continue only when its last visible result is exactly `OVERALL | APPLIED` or
   `OVERALL | ALREADY_APPLIED`; stop on `OVERALL | BLOCKED`.
7. Paste and execute the complete `02_verify.sql` file from the **SQL** tab.
8. Accept the deployment only when its last visible result is exactly
   `OVERALL | PASS`.
9. Keep screenshots or exports of the visible final result tables as deployment
   evidence.

Do **not** use phpMyAdmin **Import** output as deployment evidence: some
configurations suppress normal `SELECT` result sets. A generic green “queries
executed successfully” banner is also not sufficient evidence. Always inspect and
retain the **last visible result table** from the SQL tab.

The scripts do not use the selected database implicitly: every application object
is fully qualified with `alrowad_uni_rust`. They do not use `DATABASE()`, stored
procedures, `DELIMITER`, or `SIGNAL`.

## Compatibility and fail-closed behavior

Preflight, apply, and verify inspect the Phase 1–4 and regular-grade dependencies
used by Phase 5. Every Phase 5 target is independently classified in session
variables as `ABSENT`, `COMPATIBLE`, or `CONFLICT`. Compatibility requires the
complete semantic contract: base-table/InnoDB identity, every column and its type,
signedness, nullability, default and extra attributes, exact column set,
PK/AUTO_INCREMENT, semantic unique and non-unique indexes, every FK signature,
and pairwise FK signedness.

Apply independently recomputes all guards. `ABSENT` targets are created one at a
time and immediately re-inspected; `COMPATIBLE` targets are adopted without
rewriting; any `CONFLICT` blocks all DDL and RBAC writes. A partial compatible
installation is resumable. Verify repeats the complete target/dependency/RBAC
contract and is at least as strict as preflight.

The legacy `supplementary_exam_results` table is preserved. It identifies a mark
by period and regular registration and lacks Phase 4 registration/offering
identity, grader assignment, submission versions, review, and publication history,
so Phase 5 does not silently adopt or reinterpret it.

## Owned schema and RBAC

Phase 5 creates, when absent:

* `supplementary_exam_grader_assignments` — append-only assignment history and one
  nullable current slot per offering;
* `supplementary_exam_grade_results` — one identity per fixed Phase 4 registration;
* `supplementary_exam_grade_submissions` — offering/version batch identity;
* `supplementary_exam_grade_events` — permanent workflow/mark snapshots.

Created tables carry the comment `owned:supplementary-exam-grading-phase5`.
Created permissions carry the same ownership marker in `permissions.description`.
Pre-existing compatible tables, permissions, and mappings are adopted and
preserved rather than relabelled.

The exact permission matrix remains:

* `doctor_instructor`: `supplementary_exams.grades.view` and
  `supplementary_exams.grades.enter`;
* `exam_officer`: view, assign, enter, review, and publish.

No Phase 5 mutation permission is mapped to Super Admin, Dean, a vice president,
a student, or another unrelated role.

## Emergency rollback

`03_rollback.sql` is emergency-only. It dynamically counts rows in each optional
Phase 5 workflow table. Any row in assignments, results, submissions, or events
returns `ROLLBACK_RESULT | BLOCKED_IN_USE` and performs no DDL or RBAC deletion.
An empty Phase-5-named table or permission without the ownership marker returns
`ROLLBACK_RESULT | BLOCKED_ADOPTED` and is preserved. Only marker-owned, empty
objects can be removed. With no owned/adopted objects it returns
`ROLLBACK_RESULT | NOTHING_TO_DO`; successful removal returns
`ROLLBACK_RESULT | ROLLED_BACK`.

Rollback never deletes regular marks/results or any Phase 1–4 object.

## Academic isolation

Only the supplementary theoretical mark is writable. The original practical mark
is read through `student_course_results` and is never copied as an editable Phase
5 value. Preview calculation uses the canonical application `GradeService`.
Phase 5 does not write regular results, components, approvals, transcript/GPA,
progression, graduation, or Ministry records. Official materialization remains
Phase 6.
