# Phase 1 — Supplementary Examination Period Governance

Manual SQL only. Cursor / agents must **not** execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

A `USE \`alrowad_uni_rust\`;` statement is **not** used. Existing packs qualify every object so phpMyAdmin's selected database cannot redirect application names into `information_schema`.

**DO NOT use Laravel migrations.**
**DO NOT use seeders.**
**DO NOT execute `01_apply.sql` unless `00_preflight.sql` returns `OVERALL = READY`.**

Ownership marker:

`[phase1-supplementary-exam-period-governance]`

## Academic concept

A Supplementary Examination Period is an **optional** examination cycle attached to **one** existing Academic Year + Semester.

It is **not** a Semester, CourseOffering, teaching term, or normal registration semester. It is **not** created automatically for every semester.

- **No period row:** supplementary exams have not been opened for that academic-year/semester.
- **Governed period exists:** the dedicated Scientific Vice President has formally announced supplementary exams.

The Scientific VP does **not** create a row to say "No". Absence is the negative decision.

Phase 1 does **not** invent a "regular exams finished" gate. The announcement itself is the authoritative decision.

## Canonical identity

`academic_year_id` + `semester_id`

At most **one** `supplementary_exam_periods` row for that pair.

Do **not** use `semester_id` alone.

If preflight finds duplicate pairs, `OVERALL = BLOCKED`. Operators must review the reported ids. This pack never deletes, merges, or guesses a canonical duplicate.

A **legacy** row for an identity still occupies it. Application announce returns HTTP 409 and does not create a second row.

## Legacy handling

Existing `supplementary_exam_periods` rows are preserved.

They are **not** silently treated as Scientific VP decisions.

After apply:

| Field | Legacy rows |
| --- | --- |
| `status` | `legacy` |
| `opened_by_user_id` | NULL |
| `opened_at` | NULL |
| `decision_note` | unchanged / NULL |

Do not fabricate `opened_by_user_id` or `opened_at` for historical rows.

## Status semantics

Canonical lifecycle source: `status` (VARCHAR).

`is_active` remains for **backward compatibility only**. Clients must not submit or toggle it.

Phase 1 values:

| status | meaning |
| --- | --- |
| `legacy` | Predates governance. Readable. Occupies the identity. |
| `announced` | Scientific VP formally opened the period. |

Documented future values (**not implemented** in Phase 1):

`registration_open`, `registration_closed`, `in_progress`, `results_processing`, `locked`

Do **not** use `rejected`, `not_opened`, or `no`.

New governed row:

- `status = announced`
- `is_active = 1`
- `opened_by_user_id` = authenticated Scientific VP
- `opened_at` = server timestamp

## Authorization

Mutation (announce) requires **both**:

1. `$user->isScientificVicePresident()` (actual `vice_president_scientific` role)
2. `$user->effectivePermissions()->contains('supplementary_exams.periods.decide')`

`User::hasPermission()` is **not** the mutation gate (it includes the `super_admin` virtual bypass).

Denied for mutation: generic `vice_president`, `vice_president_administrative`, `dean`, student-affairs identities, exam-affairs identities, super_admin virtual permission.

University scope remains the existing PRES organizational root:

`scope_type = 'university'`, `scope_id = PRES organizational_unit_id`

Do **not** invent a new DataScope type. `SupplementaryExamPeriod` is institution-level.

## Permissions

| Code | Maps to |
| --- | --- |
| `supplementary_exams.periods.view` | `vice_president_scientific`, `dean` |
| `supplementary_exams.periods.decide` | `vice_president_scientific` **only** |

Decide is **not** mapped to `vice_president`, `vice_president_administrative`, `super_admin`, or `dean`.

Module: existing `exams` (`system_modules.module_code = 'exams'`).

### Unresolved Student Affairs / Exam Affairs mappings

Repository evidence:

- There is **no** `student_affairs` role. Student Affairs dashboards use `students.view` / `students.manage` and operational role `registration_officer`.
- There is **no** `exam_affairs` role. Examination operations use `exam_officer` plus `exams.view` / `exams.manage`.

Phase 1 **does not guess** those mappings. `registration_officer` and `exam_officer` are **not** granted `supplementary_exams.periods.view` here.

Operators who later need Student Affairs or Exam Affairs readers must map the proven role after review. Preflight reports this as `UNRESOLVED_VIEW_MAPPING`.

## SQL execution order

1. `00_preflight.sql` — READ ONLY. Continue **only** when `OVERALL = READY`.
2. `01_apply.sql` — idempotent schema + RBAC. Independently recomputes preflight guards.
3. `02_verify.sql` — READ ONLY. Must say `OVERALL = PASS`.
4. `03_rollback.sql` — **emergency only**. Fail-closed if governed periods or events exist.

`01_apply.sql` does **not**:

- DROP `supplementary_exam_periods` or `supplementary_exam_results`
- DELETE period or result rows
- TRUNCATE anything
- overwrite academic marks
- create sample periods
- fabricate Scientific VP decisions

`supplementary_exam_results` schema, data, and semantics are untouched.

The identity UNIQUE contract is **semantic**: there exists a UNIQUE index whose ordered columns are exactly `academic_year_id,semester_id`. The physical name (`uq_sep_year_semester` or any other) is **not** an academic invariant. Preflight, apply completion, and verify all use `@identity_unique_exists`. An equivalent unique index under another name is `COMPATIBLE`; apply will not create a second identity unique and will not rename a user-owned index.

## Event table contract

`supplementary_exam_period_events` is required for governed audit atomicity. A pre-existing object is `COMPATIBLE` only when the **full** contract holds:

- BASE TABLE, `ENGINE = InnoDB`
- PRIMARY KEY `supplementary_exam_period_event_id` (integer, `auto_increment`)
- required columns with compatible types, lengths, nullability, and `created_at` timestamp/datetime NOT NULL
- period FK → `supplementary_exam_periods.supplementary_exam_period_id`
- actor FK → `users.user_id`
- lookup indexes: period, actor, and `event_type,to_status` prefix

Physical constraint/index **names** are not required when an equivalent safe contract exists.

If a pre-existing event table (or a same-named non-base object) does not satisfy that contract: `CONFLICT` / `BLOCKED`. Apply creates the table only when **ABSENT**, adopts it when **FULLY COMPATIBLE**, and refuses when **CONFLICT**. Apply never rewrites an unknown pre-existing table.

`SupplementaryExamPeriodGovernance::schemaReady()` is fail-closed on the same identity UNIQUE and event-table contract (indexes + foreign keys + required types). Announcement cannot proceed after an incomplete SQL deployment.

## Rollback

Production data may exist after deployment.

If any governed period (`status = announced` or non-null `opened_by_user_id` / `opened_at`) or any event row exists: `BLOCKED_IN_USE`. Drop nothing. Never delete `supplementary_exam_results`.

Rollback may remove only objects **owned** by `[phase1-supplementary-exam-period-governance]`:

- Governance columns (`status`, `opened_by_user_id`, `opened_at`, `decision_note`): drop only when `COLUMN_COMMENT` contains the Phase 1 marker. Compatible columns without that marker are **ADOPTED / DO NOT DROP**.
- Event table: drop only when `TABLE_COMMENT` proves Phase 1 ownership **and** the table is empty **and** rollback is not `BLOCKED_IN_USE`. A pre-existing event table is left in place.
- Indexes and foreign keys cannot carry a reliable ownership marker: adopted compatible identity UNIQUE indexes and other constraints are left in place. `fk_sep_opened_by` is dropped only when the `opened_by_user_id` column itself is Phase-1-owned.
- Permissions: existing description-marker logic is unchanged; pre-existing compatible permissions without the marker are not removed.

Rollback is conservative and may leave harmless compatible schema behind. It must not destroy adopted objects.
