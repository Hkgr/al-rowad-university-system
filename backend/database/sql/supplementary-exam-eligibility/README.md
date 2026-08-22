# Supplementary exam eligibility — Phase 3

These are manual phpMyAdmin scripts. Run `00_preflight.sql`; continue only for `OVERALL | READY`. Then run `01_apply.sql` and `02_verify.sql`. SQL was not executed during development.

The preflight, apply, and verify scripts independently prove the complete Phase 1/2 table, engine, column, PK/AI, semantic unique, FK, supporting-index, governance RBAC, status, grading-policy, and active `exams` module dependencies. Each target is classified `ABSENT`, `COMPATIBLE`, or `CONFLICT` from its full semantic contract. Apply never uses `CREATE TABLE IF NOT EXISTS`, never adopts a conflict, and creates only an `ABSENT` object. RBAC collisions and off-matrix mappings block all work; only missing Phase-3-owned RBAC is added transactionally.

The only new application tables are `supplementary_exam_theoretical_deferrals` and `supplementary_exam_theoretical_deferral_events`. There is no registration, mark, CourseOffering, or result materialization. The unique identity is `(supplementary_exam_offering_id, student_course_registration_id)` and the current-slot authority is `(student_course_registration_id, current_slot)`.

`03_rollback.sql` is emergency-only and safe for absent or partially deployed targets. Optional tables are counted through conditional prepared statements, so an absent table cannot cause error #1146. Any decision/event row yields `BLOCKED_IN_USE`. Only empty objects bearing `[phase3-supplementary-exam-eligibility]` are dropped; adopted objects survive. RBAC is removed only with the same provenance marker.

The shared mutation lock order is original registration, source CourseOffering, grade-part approval, grade components/student marks, target supplementary offering, then current deferral. The database current-slot unique remains the final concurrency authority.
