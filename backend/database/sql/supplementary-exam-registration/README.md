# Supplementary exam registration — Phase 4

Run `00_preflight.sql`, inspect `READY`, run `01_apply.sql`, then run `02_verify.sql` in phpMyAdmin. Never run the emergency rollback as routine deployment. No Laravel migration or seeder owns these objects, and application SQL is fully qualified to `alrowad_uni_rust`.

The owned tables are `supplementary_exam_registrations` and `supplementary_exam_registration_events`. Active identity is protected by `(supplementary_exam_offering_id, student_id, current_slot)` and original-attempt history by `(supplementary_exam_offering_id, student_course_registration_id)`. A registered row uses `current_slot=1`; cancellation preserves history with `current_slot=NULL`.

Apply is Phase-3 guarded, distinguishes absent/compatible/conflicting targets, and is resumable after the first DDL. Rollback drops only marker-owned empty tables and marker-owned RBAC. Any business row produces `BLOCKED_IN_USE`; adopted objects are preserved.
