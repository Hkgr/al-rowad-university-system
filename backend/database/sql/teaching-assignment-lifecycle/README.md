# Phase 8 — Teaching Assignment lifecycle (safe replacement + formal removal)

Manual SQL only. Cursor must **not** execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

This package **extends** the existing Phase 4 Teaching Assignment workflow.
It does **not** create a second removal workflow table set.

Ownership marker for Phase 8 columns / FK / index:

`[phase8-teaching-assignment-lifecycle]`

## Lifecycle

A. No effective instructor → `action_type = assign` (existing workflow)

B. Effective instructor A → replace with B (existing replacement):
   A stays active until dual-VP approval, then atomic A → B.
   Replacement may be requested while the Course Offering is OPEN or CLOSED
   because there is no coverage gap.

C. Effective instructor A → remove without replacement (`action_type = remove`):
   A stays active until dual-VP approval, then `is_active = false`.
   **Never DELETE** the `course_offering_instructors` row.
   **Never** change `course_instructors` generic course history.

There is still only **one current academic action** per
`course_offering_id + instructor_role` (`uq_tar_current_slot`).

## OPEN / CLOSED rule

Pure removal may be requested or materialized **only** while:

`course_offerings.status = closed`

If the Offering is OPEN: HTTP 409 `teaching_assignment_removal_requires_closed_offering`.

Do **not** auto-close the Offering. Phase 7 formal closure is the only
OPEN → CLOSED path. This package therefore **requires Phase 7 closure
tables** to exist before apply (`OVERALL = BLOCKED` if they are absent).

A current unmaterialized REMOVE request (`current_slot = 1`,
`status IN ('submitted','returned')`) blocks Phase 5 normal opening and
Phase 6 exceptional opening with `teaching_assignment_removal_pending`.

## Stale removal persistence (commit then 409)

When a REMOVE request becomes stale during final VP approval, the
workflow **must not** throw inside `DB::transaction()`. Laravel would
roll back the supersede. The service returns an outcome from the
transaction, **commits**, then raises HTTP 409.

Target mismatch (`TA8-23`, `TA8-24`, `TA8-37`):

- do not deactivate any instructor
- persist `status = superseded`, `current_slot = NULL`, `superseded_at`
- persist `removal_stale` event
- commit
- then HTTP 409 `teaching_assignment_removal_stale`

Offering became OPEN before materialization (`TA8-38`):

- do not remove the instructor
- persist the same superseded / `current_slot` NULL / audit state
- commit
- then HTTP 409 `teaching_assignment_removal_requires_closed_offering`

The superseded request can never materialize later. After it is no
longer current, Dean may open a fresh action for that role (`TA8-39`).

## Files / deployment order

1. `00_preflight.sql` — READ ONLY. Continue only when `OVERALL = READY`.
2. `01_apply.sql` — independently recomputes preflight guards, including
   Phase 7 tables and a READ-ONLY Teaching Assignment RBAC matrix.
   `apply_ready = 0` when RBAC prerequisites are invalid; no Phase 8 DDL.
   Continue only when `apply_status = APPLIED`.
   MariaDB `ALTER TABLE` DDL auto-commits; this is documented and each
   object is added independently so a retry after partial DDL remains
   recoverable. No RBAC DML.
3. `02_verify.sql` — READ ONLY. Continue only when `OVERALL = PASS`.
4. `03_rollback.sql` — emergency only. Blocks if any `action_type = 'remove'`
   row or Phase 8 removal audit event exists. Never drops original
   Teaching Assignment tables or Phase 4 RBAC.

## New schema objects

On `teaching_assignment_requests`:

- `action_type VARCHAR(16) NOT NULL DEFAULT 'assign'`
- `action_reason TEXT NULL` (required at application level for remove)
- `target_course_offering_instructor_id INT NULL`
  FK `fk_tar_target_instructor` → `course_offering_instructors.course_offering_instructor_id`
- index `idx_tar_action_status (action_type, status)` — **NON-UNIQUE** (`NON_UNIQUE = 1`).
  A UNIQUE index with the same name/columns is CONFLICT / FAIL.

Existing rows receive `action_type = 'assign'` from the column default.
This package does not run an unrelated data backfill.

## Rollback constraints

- Safe when Phase 8 objects are absent, partial, or fully present **and unused**.
- `BLOCKED_IN_USE` if removal business history exists.
- Uses `information_schema` + guarded dynamic SQL so missing optional
  columns are never referenced in statically parsed `IF()` subqueries.
- Never removes Phase 4 permissions/RBAC.

## SQL acceptance

- SQL-TA8-01 — clean pre-Phase 8 DB with valid Phase 4/5/6/7 → preflight READY
- SQL-TA8-02 — `action_type` absent → apply adds `VARCHAR(16) NOT NULL DEFAULT assign`
- SQL-TA8-03 — existing TA rows → `action_type = assign`
- SQL-TA8-04 — compatible `action_type` exists, `action_reason` absent → READY; apply adds only the missing object
- SQL-TA8-05 — conflicting `action_type` definition → BLOCKED
- SQL-TA8-06 — missing Phase 7 closure infrastructure → preflight BLOCKED
- SQL-TA8-07 — apply clean DB → APPLIED
- SQL-TA8-08 — rerun apply → idempotent
- SQL-TA8-09 — verify → OVERALL PASS
- SQL-TA8-10 — rollback before any remove history → conservative rollback possible
- SQL-TA8-11 — rollback after remove request/history → BLOCKED_IN_USE, no history deletion
- SQL-TA8-12 — rollback on fully absent Phase 8 schema → no missing-column SQL error
- SQL-TA8-13 — preflight READY, then Teaching Assignment RBAC becomes invalid
  before `01_apply` → apply independently returns BLOCKED (`apply_ready = 0`);
  no Phase 8 DDL executes
- SQL-TA8-14 — Phase 8 objects valid but Phase 7 closure tables missing at
  verify time → `02_verify` OVERALL = FAIL (`phase7_closure_infrastructure`)
- SQL-TA8-15 — `idx_tar_action_status` exists as UNIQUE(action_type,status)
  → preflight BLOCKED / index CONFLICT; apply BLOCKED; verify FAIL
- SQL-TA8-16 — `idx_tar_action_status` exists as normal NON-UNIQUE
  (action_type,status) (`NON_UNIQUE = 1`) → COMPATIBLE / PASS

## Application acceptance (stale commit-before-409)

- TA8-37 — Removal target becomes stale before second VP approval.
  Second approval returns 409 `teaching_assignment_removal_stale`.
  Request is persisted `superseded`, `current_slot` is NULL, effective
  slot unchanged. Retrying the same request cannot materialize it.
- TA8-38 — Removal request exists on CLOSED Offering. Offering becomes
  OPEN before final approval. No instructor is removed. Request is
  persisted `superseded` with `current_slot` NULL. HTTP 409
  `teaching_assignment_removal_requires_closed_offering`. Request cannot
  later reactivate or materialize.
- TA8-39 — After a stale removal is superseded, Dean may create a fresh
  valid action for that role.
