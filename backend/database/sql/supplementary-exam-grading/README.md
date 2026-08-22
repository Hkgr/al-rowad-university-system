# Supplementary examination grading — Phase 5

Manual, phpMyAdmin-compatible deployment package. Run `00_preflight.sql`, require
`OVERALL | READY`, run `01_apply.sql`, then require `OVERALL | PASS` from
`02_verify.sql`. **The repository never executes these scripts.** Back up first.

Phase 5 intentionally does not adopt the legacy `supplementary_exam_results` table:
that table identifies a mark by period and regular registration, has no Phase 4
registration/offering identity, current assignment, submission version, review, or
publication history. It is preserved and classified as `LEGACY_PRESERVED`; Phase 5
uses four narrowly named tables instead.

## Owned schema

* `supplementary_exam_grader_assignments`: append-only assignment history; exactly
  one `current_slot = 1` row per offering (nullable unique slot).
* `supplementary_exam_grade_results`: one identity per fixed Phase 4 registration.
* `supplementary_exam_grade_submissions`: immutable offering/version batch identity.
* `supplementary_exam_grade_events`: permanent mark/workflow snapshots.

Permissions in the existing `exams` module are exact: `doctor_instructor` receives
`grades.view` and `grades.enter`; `exam_officer` receives all five Phase 5
permissions. No Phase 5 permission is mapped to super admin, Dean, VP, or students.
Runtime mutations additionally require the actual role, assigned permissions,
active grader assignment, and scope. Lock order is period → offering → assignment
→ registrations → results/submission.

Only the supplementary theoretical mark is writable. The practical mark is read
from the original `student_course_results` row and is never copied as an editable
Phase 5 value. Preview calculation calls the canonical application `GradeService`.
No regular result, component, approval, transcript, GPA, progression, or graduation
row is written. Official materialization is expressly deferred to Phase 6.

`03_rollback.sql` is emergency-only. It reports `BLOCKED_IN_USE` if any assignment,
result, submission, or event history exists; it drops only empty tables carrying
the Phase-5 ownership comment and removes only Phase-5 RBAC rows.
