# Unplaced elective alternatives: schema repair and interrupted completion

Status: Draft repair; not production verification, deployment, or authorization to resume a live operation.
Base: `d7798803daaf60239193367c43f64672659c9453` (merged PR #170 and corrective `29fb46b`).

## Cause and unchanged academic decisions

The supplied schema defines **both** `program_courses.academic_level_id` and
`recommended_semester_id` as signed `INT NOT NULL`. The approved manifest and target builder
legitimately supply two NULLs for an unplaced elective alternative. The membership validator
previously accepted this, but MariaDB rejected the insert with error **1048**. An allocated
auto-increment number (including the reported attempted version 14) is not a committed version.

The repair allows two NULLs only for an elective. Mandatory memberships need both real foreign
keys; one NULL and one non-NULL is invalid. Explicit placements, fixed/historical memberships,
IDs, timestamps, requirement-group budgets and academic rules are not rewritten. Sports remains
141 required credits, institutes remain 82; alternative-pool capacity is not the graduation budget.
The approved manifest is unchanged: SHA-256
`2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238`.

## Separate manual installation package

Use `backend/database/sql/elective-advisory-placement/` in the existing
`alrowad_uni_rust` database. There is no migration, new table, new dependency or permission grant.
This package requires the recognized installed scientific catalog, academic-plan and D05
code-correction contracts. It never replaces a trigger, routine or D05 permit.

Before any DDL, it checks signed types, nullability, defaults/comments, generated plan context,
engine, primary/versioned unique indexes, all five FK mappings/rules, CHECK definitions,
catalog/plan readiness and known trigger/routine definitions (including parameters).
Unknown definitions or invalid existing placements are BLOCKED, not repaired automatically.
Additional non-unique indexes and unrelated objects are retained. Privilege/SQL execution errors
are failures, not permission to ignore inspection. Review actual output; do not proceed after errors.
The application database identity must be able to inspect these existing required objects; this
repair does not grant privileges or silently treat invisible definitions as compatible.

**MariaDB DDL implicitly commits. This is not a transactionally rollbackable installation.**
Take a tested backup of schema, triggers/routines and data. Stop affected academic writers,
scheduled workers and completion commands, and verify they are stopped before installation.
Do not run old writers against the new contract during the maintenance window.

Run in phpMyAdmin, in order:

1. `00_preflight.sql`: require `READY`; this means a recognized installable state, not that NULLs
   are already supported. A fresh original schema should still fail `02_verify.sql`.
2. `01_apply.sql`: require `APPLIED`. It first adds the guarded placement CHECK, then makes both
   columns nullable. It does not update existing rows or remove any existing protection.
3. `02_verify.sql`: require `PASS`, `detail=elective_placement_ready`, zero partial placements
   and zero mandatory memberships without placement. Record the unplaced-elective count as data,
   not an error or an instruction to fill it.
4. Activate the matching application code, inspect a fresh completion preview, then resume
   ordinary writers only after operational checks. Do not automatically start completion.

If interrupted between DDL steps, keep maintenance active. Rerun preflight, apply and verify.
CHECK-first and either single-column-nullable compatible partial states are recognized.
Reapplication is idempotent. Do not drop guards, fill NULLs, delete rows or force an unknown state.

`03_rollback.sql` is an **explicit separate operation**, not failure compensation. It validates
the same contract and refuses if any placement NULL exists, because restoring NOT NULL would
lose the approved meaning. It never fabricates year/semester IDs or deletes history. When safe,
it restores NOT NULL before dropping only this package's recognized CHECK. Recheck the restored
schema and application compatibility before reopening writes. Prefer a reviewed forward fix
once real unplaced memberships exist.

## Runtime prerequisite and replay

Preview reports `schema_prerequisites.elective_advisory_placement`, `can_apply` and `BLOCKED`
when the repair is unavailable. An applying call checks readiness under catalog-first locks
before creating a start receipt or any new publication/assignment stage. Publication revalidation
also checks readiness before assignment batches and final confirmation. Normal scientific
membership/distribution writers use the same placement policy; no eligibility bypass is added.

Authorized pure UUID replay still returns its stored receipt **before** stale-revision/readiness
rejection. This retrieves an outcome; it does not verify today's defaults or restart work.
`IN_PROGRESS` means no final durable receipt, not proof that a worker is running.

## Explicit same-operation resumption (operator instructions, NOT executed)

Reported incident evidence, not a hardcoded execution list:

| Program | Earlier published version | Required hours | Members | Students then pending |
| --- | ---: | ---: | ---: | ---: |
| 19 | 2 | 151 | 61 | 13 |
| 20 | 4 | 151 | 61 | 19 |
| 32 | 6 | 150 | 62 | 17 |
| 33 | 8 | 159 | 65 | 79 |
| 34 | 10 | 156 | 64 | 22 |
| 38 | 12 | 147 | 64 | 65 |

All six were reported with matching defaults and six groups. Programs 40/41/42 had no completed
publication; 18/28/29/30/31/39 retained academic blockers. This repair has **not inspected production**.
Do not assume these IDs/counts still describe the live state or that version 14 exists.

After installing/verifying and confirming there is no running completion worker, from `backend/`:

```sh
php artisan academic-catalog:completion-status e920ea2d-035d-41c0-9f98-c03ed023774e --actor=1
php artisan academic-catalog:complete --actor=1 --manifest=resources/academic-catalog/approved-2026-10.json --manifest-hash=2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238
php artisan academic-catalog:verify-completion --actor=1
```

Review the fresh preview's revision, schema prerequisite, blockers, dependency groups and students,
and verify each earlier publication's content/source/default against its recorded proof. Keep the
existing actor and UUID. Only after review, replace `FRESH_REVIEWED_REVISION` below with that actual
revision (never blindly reuse 7), and explicitly execute:

```sh
php artisan academic-catalog:complete --apply --resume --actor=1 --manifest=resources/academic-catalog/approved-2026-10.json --manifest-hash=2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238 --expected-revision=FRESH_REVIEWED_REVISION --operation-id=e920ea2d-035d-41c0-9f98-c03ed023774e
php artisan academic-catalog:completion-status e920ea2d-035d-41c0-9f98-c03ed023774e --actor=1
php artisan academic-catalog:verify-completion --actor=1
```

The commands retain current authorization/scope checks. A lost response requires the read-only
status/verification path before any deliberate resume. Another writer's default/content/source
change is a conflict, not permission to restore it or replace the operation. Existing academic,
registration, appeal, supplementary and final-graduation blockers stay effective. `PARTIAL` or
`BLOCKED` is not successful assignment of all students. Do not use a replacement UUID, force
assignment, fake receipt or delete history to make the report look complete.

Compact evidence to retain: server version; 00/01/02 outputs; manifest hash; reviewed revision;
operation/actor; program/current target/source IDs; default/content/source matches; group/member
counts and required hours; transferred/already-assigned/blocked/remaining students; final status.
Receipt retrieval and current-state verification are separate pieces of evidence.

## Read-path audit and minimal UI compatibility

| Consumer | Behavior retained/verified |
| --- | --- |
| Scientific version/workspace | Nullable relationships, no inner join discarding alternatives; explicit filters restrict only the requested placement. |
| Advisory grouping | Unplaced group remains visible with unknown-placement labels, not year/semester zero. |
| Dean offerings | `all_curriculum`/explicit selected memberships retain unplaced electives; advisory-semester selections deliberately match only placed rows. Actual offering period remains explicit. |
| Registration | Uses actual offering and assigned plan membership; prerequisites, credit limits, scope and offering gates remain mandatory. No automatic every-semester offer. |
| Requirement progress | Uses group membership and independent budgets, not advisory placement. No budget recalculation or invented zero. |
| Reporting | Uses actual enrollment/offering periods; no new placement join or loss of alternatives. |

Only necessary editor compatibility was changed: preserve two blanks as NULL rather than
`Number(null)`/NaN, allow an elective's two blanks, reject partial pairs/mandatory blanks.
References are the existing `WorkspaceCourseEditor`, `ProgramForms` and catalog `MembershipEditor`.
Their layouts/components, Cairo, RTL, colors and workflows remain unchanged. No portal redesign.

## Verification

Verification date: 2026-10-17. Reproducible suites (existing dependencies only):

```powershell
backend/tests/mariadb/elective-placement.ps1 -MariaDbHome <existing-10.11.18-home>
backend/tests/mariadb/approved-catalog-completion.ps1 -MariaDbHome <existing-10.11.18-home>
backend/tests/mariadb/course-result-symbols.ps1 -BinaryDirectory <existing-10.11.18-home>/bin
```

The first suite begins genuinely NOT NULL, reproduces 1048 with rollback, checks early refusal,
upgrade/reapply/partial/negative definitions, unchanged objects/rows/timestamps, PHP and actual
MariaDB CHECK enforcement, and a synthetic six-committed-group interruption followed by same-UUID
resume. It never uses the live UUID or imports student records. The completion suite checks canonical
GPA/transcript/material results and independent-connection stale/default/source/assignment fences.
The separate course-result suite checks actual I lifecycles under installed and fallback locking;
it is not a claim that the completion fixture contains every production I/snapshot shape.

Executed results:

| Check | Actual result |
| --- | --- |
| New isolated placement/incident harness | PASS on `10.11.18-MariaDB`: real 1048, early preview/apply refusal, guarded install/reapply/partial states, incompatible trigger/type/comment/FK/CHECK/unique definitions, exact existing rows/timestamps/objects, real CHECK/PHP validation, six earlier committed groups, no premature assignments/final receipt, explicit same-UUID resume, all verified sports memberships/141 credits, institutes 82, material results/decision snapshots/GPA/transcript preservation, duplicate-free academic replay and lossy rollback refusal. |
| Focused placement + completion + workflow + legacy-display PHPUnit | 42 passed, 12,254 assertions. Includes 3 HTTP/SQLite placement regressions and 2 new source contracts. |
| Offering advisory behavior + scientific catalog + result-symbol contracts | 4 additional passing tests; together with the initial placement/contract run, 9 passed / 90 assertions. The later focused run adds requirement-pool assertions. |
| Broader workspace/distribution/program/catalog/result-symbol/read behavior | 107/108 passed, 1 inherited MySQL-grammar fixture failure: missing `user_roles.assigned_at`. Reproduced on clean base `d779880`. |
| Dean bulk source contracts | 20/24 passed; the same 4 stale source-text contracts fail on clean `d779880` (tests 06, 08, 13 and checkbox text). Not rewritten to hide failures. |
| Related Node tests | 38/39 passed. Both new pure-logic tests passed. One stale Scientific-home source-regex contract also fails on clean `d779880` after the earlier `ScientificHome` extraction. |
| Existing full MariaDB completion harness | PASS on `10.11.18-MariaDB`: publication/archived assignment/material GPA/transcript preservation, D05 code-only correction, negative guards, nonlocking snapshot/stale fences, independent publication/assignment/final-confirmation drift and replay/admission races. |
| Existing full MariaDB I lifecycle harness | OVERALL PASS on `10.11.18-MariaDB`, installed/fallback catalog paths, independent races, schema negatives, expiration progress and protected settlements. |
| Changed-file ESLint, production Vite build | PASS; build reports large-chunk/plugin-timing warnings, not behavioral or visual acceptance. |
| Changed PHP syntax, Composer validate/platform requirements, diff checks | PASS. No dependencies installed or lockfiles changed. |

An early completion-harness attempt failed with a missing MariaDB temporary-table file in shared
OS TEMP. The harness now uses its own marked directory as `tmpdir`; the full fresh rerun above
passed. Additional new schema-probe fixture runs exposed an implicit FK-index rename and a
same-name FK replacement issue; the probes now restore their original definitions explicitly.
These failed attempts are not counted as successful runtime verification.

SQLite HTTP and dependency-free Node coverage are application/pure-logic evidence only.
SQL source contracts are not runtime evidence. The allowed browser inventory returned no surfaces,
and opening IAB returned `Browser is not available: iab`. Live React/Laravel visual acceptance,
responsive screenshots, production installation and live operation resumption were **not executed**.
The isolated completion fixture checks populated numeric marks/components/approvals/announcements,
decision payloads and transcripts; fully populated I lifecycle behavior is exercised separately,
not a combined reconstruction of all live I cases and historical snapshots. No production-data or
all-student completion claim is made. Unknown identities and existing student blockers remain blockers.
