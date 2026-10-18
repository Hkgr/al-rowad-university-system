# Dean teaching staff foundation

Manual SQL runbook for Phase 1 of Dean > Teachers.

This phase establishes organizational affiliation, canonical theoretical/practical
assignment slots, uniqueness, compatibility with the legacy offering pointer, and
fine-grained Dean permissions. It does **not** build the Dean Teachers UI.

## Architecture reused (no duplicate tables)

No new teacher/college relationship table was created. In particular, this runbook
does **not** create `faculty_college_assignments`, `teacher_colleges`,
`faculty_courses`, `teacher_courses`, `teaching_sessions`, `teacher_sessions`,
`teaching_staff_members`, or a new `system_modules` row.

Existing university architecture is reused:

- Faculty identity remains `employees` → `faculty_members`.
- College membership uses `colleges.organizational_unit_id` →
  `organizational_units` → `employee_unit_assignments`.
- The FMF academic College is linked to an operational `organizational_units`
  row (created only when missing and preflight-safe).
- Teaching-staff College affiliation is `employee_unit_assignments`, not a new
  faculty-college table.
- The existing `positions.position_code = 'INSTRUCTOR'` row is reused via
  `employee_positions`.
- `courses.theoretical_hours` / `courses.practical_hours` are authoritative for
  whether a theoretical or practical assignment is valid.
- Term-specific teaching assignments are `course_offering_instructors` slots.
  Identity is `(course_offering_id, instructor_role)` with
  `instructor_role IN ('theoretical', 'practical')`.
- The previous unique key `(course_offering_id, faculty_member_id)` was wrong:
  it blocked the same teacher from occupying both the theoretical and practical
  slots of one offering. The replacement unique key is
  `(course_offering_id, instructor_role)` — at most one instructor per component.
- `course_instructors` remains the generic Course ↔ Faculty association and is
  not deleted when a particular offering slot changes.
- `course_offerings.faculty_member_id` is retained as a compatibility pointer
  to the primary slot (theoretical when the course has a theoretical component;
  practical only when the course is practical-only). Application code that
  removes the primary slot sets the pointer to `NULL`. The practical slot on a
  dual-component course never overwrites it, and no silent role promotion
  occurs. `01_apply.sql` never mass-clears non-null pointers: it only fills a
  NULL pointer from a proven primary slot. Zero-component offerings that still
  have a faculty pointer, and pointer/slot faculty conflicts, are blocked in
  preflight for manual review.
- Historical `attendance_sessions.faculty_member_id` values are never rewritten.
  Those rows remain the teacher who actually conducted the session.

## Production order

`01_apply.sql` is fail-closed. It computes `@apply_ready` from the same destructive
invariants as preflight. If `@apply_ready <> 1`, it performs **zero writes** and
**zero ALTER TABLE** operations and reports:

- `APPLY_STATUS | BLOCKED`
- `ACTION | Run 00_preflight.sql and resolve blockers first`

Do not rely on the operator remembering to skip apply after a BLOCKED preflight.

Safer production order (keeps application code and assignment schema from drifting):

1. Run `00_preflight.sql` against the current production database.
2. Require `OVERALL = READY`.
3. Take a fresh full database backup.
4. Enter a short maintenance/change window for teaching-assignment writes.
5. Deploy the merged backend code.
6. Immediately run `01_apply.sql`.
7. Run `02_verify.sql`.
8. Require `OVERALL = PASS`.
9. Exit the maintenance/change window.

**SQL MUST NEVER run automatically from deployment.** The operator executes these
files manually (phpMyAdmin or equivalent). Application deploy/start code must not
apply them.

## No automated rollback

This conversion changes the canonical meaning of teaching assignments and
organizational affiliations. There is no rollback SQL. The rollback mechanism is
the mandatory database backup taken before `01_apply.sql`.
