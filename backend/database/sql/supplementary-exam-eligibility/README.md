# Supplementary exam eligibility — Phase 3

Run `00_preflight.sql`, inspect `OVERALL | READY`, then run `01_apply.sql` and `02_verify.sql` manually in phpMyAdmin. Do not run these files from Laravel. No SQL was executed while developing this package.

Phase 1 and Phase 2 are hard dependencies. `PHASE2_NOT_DEPLOYED` means no Phase 3 mutation is permitted. The scripts create only the explicit deferral identity/history table and its immutable event table. They do not create registrations, marks, course offerings, or results.

`03_rollback.sql` is emergency-only. It refuses rollback when any decision/audit row exists and drops only empty tables bearing the Phase 3 ownership marker. Adopted/unowned objects and academic records are preserved.

Concurrency lock order shared by declaration and theoretical entry is registration, source course offering, grade-part approval, grade-component marks, supplementary offering, then current deferral. The unique `(student_course_registration_id,current_slot)` key is the final concurrency authority.
