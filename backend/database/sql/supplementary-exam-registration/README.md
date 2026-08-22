# Supplementary exam registration — Phase 4 manual deployment

These scripts are intended for **manual execution from phpMyAdmin's SQL tab** against `alrowad_uni_rust`.

> Do not use phpMyAdmin **Import** as the evidence source for this deployment. Some phpMyAdmin configurations execute imported `.sql` files successfully but suppress `SELECT` resultsets and show only a generic green success message. That makes an otherwise correct operator report look "silent".

## Operator runbook

1. Open database `alrowad_uni_rust` in phpMyAdmin.
2. Open the **SQL** tab.
3. Paste the complete contents of `00_preflight.sql` and execute it.
4. Proceed only when the last visible report row is `OVERALL | READY`.
5. Paste and execute `01_apply.sql`.
6. Proceed only when its final report says the apply completed successfully / is already applied.
7. Paste and execute `02_verify.sql`.
8. Accept the deployment only when the final `OVERALL` row is `PASS`.
9. `03_rollback.sql` is emergency-only. Read its final `ROLLBACK_RESULT` row before taking any further action.

The operator should copy the visible result table after every step and keep it with the deployment record. A generic message such as **"X queries executed successfully" is not sufficient evidence**.

## Safety contract

All three forward scripts repeat the same semantic Phase 1/2/3 dependency guard: seven InnoDB base tables, required named/type/nullability contracts, signed integer identities, primary auto-increment identities, independent semantic uniques, one-boolean-per-signature exact foreign keys with pairwise signedness, supporting audit indexes, active exams-module permissions, expected mappings, and forbidden mutation mappings. Phase 4 additionally requires period lifecycle status/event fields to be `VARCHAR(19)` or wider; it never resizes an adopted Phase 1 table.

Each target is independently classified `ABSENT`, `COMPATIBLE`, or `CONFLICT`. Compatibility is semantic and index-name independent: exact columns/types/nullability, InnoDB base table, primary auto-increment identity, independent semantic unique/index booleans, exact FK targets with pairwise signedness, and supporting indexes. Apply creates only absent targets, adopts only compatible targets, and performs no DDL/RBAC work on any dependency, lifecycle-width, target, or RBAC conflict. Partial DDL deployment is resumable and each created object is re-inspected before proceeding.

The owned tables are `supplementary_exam_registrations` and `supplementary_exam_registration_events`. Active identity is `(supplementary_exam_offering_id, student_id, current_slot)` and original-attempt history is `(supplementary_exam_offering_id, student_course_registration_id)`. Verification checks row state, cancellation state, provenance, original student identity, orphan events, explicit duplicate identities/current slots, and summer limits through `semesters.semester_order`.

`03_rollback.sql` is emergency-only. It dynamically guards optional tables, reports whether rollback is `SAFE`, `BLOCKED_IN_USE`, `BLOCKED_ADOPTED`, or `NOTHING_TO_DO`, drops only empty marker-owned objects, preserves adopted objects, and removes only marker-owned RBAC. It never touches Phase 1/2/3 tables, normal registrations, offerings, grades, or supplementary results.
