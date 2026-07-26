# Authorization Phase 1

This phase makes identity, account status, roles, and permissions authoritative
across the Laravel API and React dashboards.

## Deployment

From the `backend` directory:

```bash
php artisan migrate --path=database/migrations/2026_07_26_000000_harden_core_role_permissions.php --force
php artisan optimize:clear
```

The migration adds missing baseline grants without removing existing grants.
Its rollback intentionally preserves authorization records. The explicit path
is important for installations created from the checked-in SQL dump, whose
migration history may not include Laravel's original scaffold migrations.

Assign the exam account its role if the imported database still has no active
assignment:

```bash
php artisan rbac:assign-role exam.board exam_officer
```

The command accepts either a username or email address and is safe to rerun.

## Baseline responsibility matrix

| Role | Primary capabilities |
|---|---|
| `registration_officer` | Student records, course registration, read-only grades and attendance |
| `exam_officer` | Grade operations, exam operations, appeals, supplementary exams, and deprivation decisions |
| `doctor_instructor` | Grades and attendance only for assigned course offerings |
| `academic_advisor` | Student, registration, and read-only grade context |
| `dean` / `head_of_department` | Academic structure and course setup |
| `hr_officer` | Employee and organizational structure management |
| `student` | Own student record only |
| `super_admin` | All permissions |

## Runtime guarantees

- Login returns active roles, effective permissions, available dashboards, and
  a server-selected default dashboard.
- Disabled, locked, pending, or otherwise inactive accounts cannot log in.
- Existing tokens are rejected and revoked after an account becomes inactive.
- Every versioned API route requires an explicit permission or is an
  authenticated shared lookup.
- Student-only users cannot call staff endpoints even if historical role data
  contains broad permissions.
- A student can access only the student record linked to their account.
- A faculty member can read or mutate grades and attendance only for offerings
  assigned directly or through `course_offering_instructors`.
- User, role, and permission audit fields are derived from the authenticated
  session and server clock.
- Deleting a user disables the account; deleting a user-role assignment
  deactivates it. Security history is retained.

## Smoke checks after deployment

1. Login as `exam.board` and confirm `default_dashboard` is `/exam-board`.
2. Login as a registration officer and confirm grade mutation returns `403`.
3. Login as an exam officer and confirm student mutation returns `403`.
4. Login as a student and request another student's profile; expect `403`.
5. Login as a faculty member and request an unassigned offering's attendance
   or grades; expect `403`.
6. Disable a test account and confirm its existing token is rejected.

## Deliberately deferred

The full grade lifecycle (`draft`, `submitted`, `returned`, `approved`,
`published`, `locked`) remains the next authorization-sensitive phase. This
phase protects the current endpoints but does not claim that grade approval and
publication are complete.
