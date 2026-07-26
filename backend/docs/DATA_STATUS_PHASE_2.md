# Data Status Phase 2

## Root cause

Historical course registrations are commonly stored with registration status code `completed`. Grade-sheet, result-summary, transcript, GPA, and CGPA queries previously treated only `registered` as academically visible, or loaded every status and relied on later calculations. That made valid historical results disappear and made the distinction between visibility and write eligibility unclear.

## Status-domain rules

Status identity is always the stable `status_code`; numeric IDs remain storage and legacy CRUD details only.

| Registration status | Current / seat / term hours | Historical result visibility | New attendance | New grade entry | GPA/CGPA |
| --- | --- | --- | --- | --- | --- |
| `registered` | Yes | Yes when a result exists | Yes, subject to authorization | Yes, subject to authorization and result workflow | Yes when a valid result exists |
| `completed` | No | Yes when a result exists | No | No (read-only historical result) | Yes when a valid result exists |
| `dropped` | No | No | No | No | No |
| `withdrawn` | No | No | No | No | No |

A missing result is not a failure and is excluded from transcript and GPA calculations. Existing `incomplete`, `deprived`, and result-status grading policy remains unchanged. Incomplete, deprived, and withdrawn results remain excluded from GPA. CGPA continues to select the highest eligible attempt per course.

## Implementation and API behavior

`StudentCourseRegistration` owns reusable current, academic-attempt, excluded, attendance-entry, and grade-entry semantics. `GradeService` uses academic attempts for summaries, transcripts, GPA, and CGPA. The default grade sheet contains current registrations plus completed registrations that have results; `include_inactive=true` retains its legacy diagnostic behavior of returning every registration. Historical rows are visible but grade creation, update, and recalculation accept only current `registered` rows.

`AttendanceService` uses the current-registration scope for rosters and deprivation candidates. Authorized historical attendance reads are unchanged, while attendance writes reject every non-current registration.

Student list filtering now accepts `student_status_code`; the graduates UI and dashboard use `graduated`. Student updates accept `student_status_code`, safely resolve it to the environment's ID, and explicitly fail validation if the code is missing. Grade-approval creation similarly accepts `approval_status_code`; the Examination Committee UI sends `approved`. Numeric ID fields remain accepted for backward compatibility.

Existing route middleware, policies, assigned-section checks, student ownership checks, and Examination Committee deprivation/finalization boundaries were not widened. Visibility of a historical row does not confer write authority.

## Seat and credit behavior

Only `registered` occupies a seat and contributes current-term registered credit hours. Completing, dropping, or withdrawing a registration does not make it current. Existing duplicate and prerequisite/repeat checks remain unchanged.

## Database impact

No database structure or production data was modified.

No migration or data-correction command is required.

## Safe Plesk verification

1. Deploy application files without running a migration or seeder.
2. Confirm environment and cached configuration point to the intended database without displaying credentials.
3. Clear application/route caches using the site's normal non-destructive deployment procedure.
4. As an authorized Examination Committee user, read a historical completed grade sheet and transcript; confirm its result is present and its edit action is rejected.
5. As an assigned instructor, read old attendance and confirm a new write against completed/dropped/withdrawn registration returns validation error.
6. Confirm current seat counts and registered-hour totals contain only `registered` rows.

## Unresolved business rules

This phase does not define graduation eligibility or rebuild result approval. The existing explicitly requested `graduated` transition and `approved` submission are code-based, but eligibility prerequisites and multi-stage approval policy require separate documented product rules.
