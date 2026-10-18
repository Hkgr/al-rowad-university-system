# Catalog completion: historical registered attempts

Draft repair, based on fetched `origin/develop` at
`34a9b65fe76bd6955725d813503de70a5b4e0d26` (merged PR #171).
No production connection, deployment, SQL execution or live-operation resumption was performed.

## Cause and approved boundary

`AcademicPlanWorkflow::transferBlockers()` used `StudentCourseRegistration::current()`, whose
intentional, global definition is the stored `registered` status. This is correct for ordinary
registration/transfer consumers, but is insufficient for the dedicated approved-catalog assignment.
Some closed, official, settled historical attempts still retain that status.

The new `CompletionRegistrationReadiness` is used by general completion student readiness,
published-target assignment preview, and the **same** projection inside the existing locked
assignment transaction. It does not modify `scopeCurrent()`, statuses, offerings, registrations,
plan/membership pins, grades, approvals, announcements, I cases/events or historical decisions.
Ordinary transfer still blocks a stored current registration. Attendance and grade editing retain
their existing definitions and authorization; this is not a new grading or registration exemption.

An attempt is excluded from this completion blocker only when:

1. Its real offering exists and is `closed` (existing case-insensitive closed spelling accepted).
2. A result exists and the **authoritative latest** approval is approved. The classifier eager-loads
   only the latest approval per offering and delegates authority/outcome interpretation to `GradeService`.
   An old approved row cannot override a newer pending/rejected/revoked row.
3. Its outcome is settled: a known passed/failed/deprived result, not pending, unknown or legacy I.
   Passed requires its persisted final mark. Official F/Z may have NULL numeric marks; no invented
   components, recalculation, pass thresholds or new GPA formula are introduced.
4. Required lifecycle schema is available. Any recorded I is checked against its current event,
   exact protected outcome snapshot and identity using the **same canonical evidence validation**
   as `IncompleteOutcomeGuard::preservesOutcome()`. Active I, missing/invalid evidence and
   undocumented incomplete outcomes remain blocked. Resolved/expired F/Z may qualify.
5. All other student/academic blockers remain clear.

Candidates include stored-current registrations and recorded/legacy I evidence, including completed
registrations. An invalid or partially unavailable lifecycle contract is never treated as a negative
case lookup. If the case identity column remains readable, completed cases are still found and
blocked when lifecycle validation is unavailable. Unsupported lifecycle registration states are blocked.

The lifecycle guard now exposes a read-only batch projection sharing its existing evidence validator;
its locked mutation guard, lifecycle writers, correction authority and academic rules are unchanged.
It does not authorize a write or accept client-supplied evidence/bypass flags.

Unchanged blockers: registration/modification/replacement requests; withdrawal requests; unclosed
appeals; supplementary operations; pending academic decisions; final materialized graduation.
One genuinely ongoing registration still blocks a student with any number of settled historical ones.

## Bounded reads, transactions and receipts

The caller must authorize and scope an explicit list of at most 50 students. The classifier streams
candidate registrations in chunks of 100, eager-loads result/status/offering/latest-approval context,
and batch-loads I cases, **current-revision events only**, persisted results and statuses. It never
queries lifecycle/approval evidence separately for every registration or loads all approval/event history.
Tests compare one and 80 registrations within a chunk, and 150 across two chunks.

Read-only commands keep their existing revision-fenced snapshots without `FOR UPDATE`. A dedicated
owned-snapshot read facade avoids nesting a writer-locking snapshot while summarizing verification.
Assignment reuses catalog → program → students and established canonical locks. Installed revision
triggers serialize registration/offering/approval changes, and canonical I writers acquire the same
catalog-first transaction. The reviewed epoch and classification are rechecked before assignment.
No automatic mutation retry or new lifecycle cancellation workflow is added.

Publication/default/content/source revalidation, operation actor/UUID binding, 50-student assignment
batches and idempotent receipts remain unchanged. Pure replay is receipt retrieval, not a new
current-state verification. Explicit resume uses the same actor/UUID and a freshly reviewed revision.

## Read contracts and compact reports

Each completion student projection adds `registration_readiness`:

- `historical_settled_registrations`: registration count excluded from this particular blocker.
- `blocking_registrations`: candidate registrations still blocking.
- `blocking_reasons`: primary reason → registration count, not distinct students. A primary reason
  uses the order below; other blockers are retained separately in `blockers`.

| Reason | Meaning |
| --- | --- |
| `offering_missing` / `offering_not_closed` | No proven closed actual offering; open/unknown states remain ongoing. |
| `result_missing` | No persisted result. |
| `authoritative_approval_not_approved` | Latest approval missing, unknown or not approved. |
| `incomplete_schema_unavailable` | Cannot validate lifecycle ownership/evidence. Progress is explicitly unavailable, not fabricated. |
| `incomplete_active` | A valid unresolved I remains outstanding. |
| `incomplete_outcome_conflict` | Recorded settlement/event/identity/snapshot does not match. No repair is attempted. |
| `legacy_incomplete_unsettled` | Incomplete outcome without a documented settled lifecycle. |
| `outcome_not_settled` / `passed_outcome_missing_mark` | Unknown/pending/unsupported outcome or a passed record missing its final mark. |
| `unsupported_registration_state` | A lifecycle registration has an unsupported stored status. |

General preview adds `registration_readiness_summary`, aggregating registration counts/reasons and
student counts with/without detected preview blockers. **No detected preview blocker is not final
target eligibility**. `verify-completion` adds per-program `assignment_readiness`: current eligible,
already-assigned and blocked student counts plus registration counts/reasons. These are available
only for a verified published target/default/source/content; otherwise it reports unavailable and why.
The result of each actual assignment stage also contains its registration report and a compact
`summary` of transferred, already-assigned and blocked students.

Existing verification fields remain: `publication_context_matches`, `default_matches`,
`plan_content_matches`, `historical_source_matches`, `students_not_on_completed_plan` and blockers.
No frontend, new endpoint, permission, schema/SQL package, migration or manifest change is required.

## Operator incident evidence (not reverified on production)

The operator reported nine publications, 122 students already assigned, and 215 students blocked
exclusively by the current-registration message. The offline aggregate counted **1,774 registrations**
on closed 2023–2024 through 2025–2026 offerings with results, and one open 2026–2027 registration
without a result. These are not distinct-student counts, official-approval proofs, or evidence that
all 215 students now qualify. The open registration need not belong to a distinct additional student.

| Program | Reported published version | Members | Required credits | Then remaining |
| --- | ---: | ---: | ---: | ---: |
| 19 | 2 | 61 | 151 | 9 |
| 20 | 4 | 61 | 151 | 9 |
| 32 | 6 | 62 | 150 | 10 |
| 33 | 8 | 65 | 159 | 69 |
| 34 | 10 | 64 | 156 | 18 |
| 38 | 12 | 64 | 147 | 50 |
| 40 | 16 | 60 | 141 | 50 |
| 41 | 19 | 32 | 82 | 0 |
| 42 | 20 | 32 | 82 | 0 |

Default/content/source checks were reported true. Programs 18/28/29/30/31/39 retained academic
identity/prerequisite ambiguities, which are outside this repair. None of these IDs/counts is a
runtime execution list. The approved manifest bytes/SHA-256 remain unchanged.

## Backend deployment and explicit resumption (instructions only, NOT executed)

The elective-placement SQL repair is already reported installed and verified. **Do not reinstall
it to address this blocker. There is no new SQL/migration/permission command for this repair.**
Deploy the reviewed backend code through the normal release process, reload PHP opcode caches/
affected workers as appropriate, and ensure no completion worker is still running. Do not run an
automatic completion job as part of deployment. Keep the usual backup/operational safeguards.

From `backend/`, inspect the existing receipt, a fresh preview, and current target verification:

```sh
php artisan academic-catalog:completion-status e920ea2d-035d-41c0-9f98-c03ed023774e --actor=1
php artisan academic-catalog:complete --actor=1
php artisan academic-catalog:verify-completion --actor=1
```

Record the new `revision` and `registration_readiness_summary`. From verification retain each
program's `assignment_readiness` and publication/default/content/source checks. An old PARTIAL
receipt is historical evidence, not today's eligibility report. PARTIAL/BLOCKED exit statuses
remain expected when academic identities or student blockers are outstanding; inspect the JSON.

An optional compact read-only projection using PHP already required by the application:

```sh
php artisan academic-catalog:complete --actor=1 --no-ansi | php -r '$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo json_encode(["revision"=>$r["revision"],"registrations"=>$r["registration_readiness_summary"]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;'
php artisan academic-catalog:verify-completion --actor=1 --no-ansi | php -r '$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); foreach($r["programs"] as $id=>$p) echo json_encode(["program"=>$id,"default"=>$p["default_matches"],"publication"=>$p["publication_context_matches"]??null,"content"=>$p["plan_content_matches"]??null,"source"=>$p["historical_source_matches"]??null,"assignments"=>$p["assignment_readiness"]],JSON_UNESCAPED_UNICODE),PHP_EOL;'
```

Do not interpret a command/JSON error as zero. After deliberate review only, replace the placeholder
with the fresh reviewed revision (5294 is historical evidence, **not** a value to reuse blindly):

```sh
php artisan academic-catalog:complete --apply --resume --actor=1 --manifest=resources/academic-catalog/approved-2026-10.json --manifest-hash=2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238 --expected-revision=FRESH_REVIEWED_REVISION --operation-id=e920ea2d-035d-41c0-9f98-c03ed023774e
php artisan academic-catalog:completion-status e920ea2d-035d-41c0-9f98-c03ed023774e --actor=1
php artisan academic-catalog:verify-completion --actor=1
```

Another writer's changes require renewed review; never restore its default, replace the operation,
convert registrations to completed, relink historical registrations, fabricate an approval, delete
history or force assignments. A lost response requires status/current verification before another
explicit resume. No guarantee is made that all remaining students become eligible.

## Verification

All records are synthetic. The supplied export was inspected for definitions only, never imported
or executed. The new MariaDB harness installs existing checked-in contracts solely in a new guarded,
loopback-only disposable database, verifies the actual server identity, and shuts down only that server.
It is not a schema instruction for the operator's already installed database.

```powershell
backend/tests/mariadb/completion-historical-registrations.ps1 -MariaDbHome <existing-10.11.18-home>
backend/tests/mariadb/approved-catalog-completion.ps1 -MariaDbHome <existing-10.11.18-home>
backend/tests/mariadb/course-result-symbols.ps1 -BinaryDirectory <existing-10.11.18-home>/bin
```

Verification date: 2026-10-17. Executed results (not CI or production acceptance):

| Check | Actual result |
| --- | --- |
| Focused/affected PHPUnit: completion, plans, legacy display, placement, result symbols/read contracts | 101 passed, 14,420 assertions. |
| New historical-registration suite | Final rerun: 8 passed / 57 assertions (overlaps the focused group). Real HTTP ordinary-transfer coverage; completion boundaries; closed/open/missing/unknown/pending/rejected/revoked cases; canonical I including completed expired Z; all other blockers; scope/authorization; archived students; bounded 1/80/150 reads; and current-summary refusal after default drift. |
| New isolated historical-registration harness | PASS on **10.11.18-MariaDB**: nine synthetic publications, prior assignments, four observed independent-connection catalog waits/stale rejections, canonical completed-I grant, expired F/Z without marks, exact pinned material evidence, same-UUID resume/replay, read-only current eligibility summary and canonical GPA/transcript preservation. |
| Existing full approved-catalog completion MariaDB harness | PASS on **10.11.18-MariaDB**, including nonlocking preview/verification snapshots, stale/default/source/assignment races, replay and admission serialization. |
| Existing full result-symbol/I MariaDB harness | OVERALL PASS on **10.11.18-MariaDB**, fallback and installed catalog paths, actual lifecycle writers/finalization, schema negatives, expiration progress and independent connections. |
| Existing full elective-placement MariaDB harness | PASS on **10.11.18-MariaDB**: genuine NOT NULL/1048 reproduction, upgrade/reapply/partial/negative definitions, preserved objects/rows/timestamps, real CHECKs, six-committed-group interrupted resume and rollback refusal. This executed only on a new disposable server, not the reported installed server. |
| Dependency-free contracts | Approved catalog, result-symbol Phase 1 and Phase 2 contracts passed; these are static/pure-logic checks only. |
| Syntax / Composer / diff | Changed PHP syntax, PowerShell parse, Composer validate/platform requirements and diff checks passed. Manifest SHA-256 is unchanged. No dependencies were installed. |

The new fixtures initially exposed missing audit-table and explicit status-primary-key setup; these
were corrected in the new test fixture, without changing historical tests or loosening runtime rules.
A stale in-flight run was stopped/restarted after the owned-snapshot reporting refinement; it is not
counted as a completed passing run. No unrelated failures occurred in the completed focused group;
the entire Laravel suite was not run. Frontend build/lint and browser checks were not run because
this is backend-only with no UI changes. No production behavior or deployed-data eligibility is claimed.

SQLite HTTP/service tests prove application
behavior, not MariaDB locking. Independent connections observe the actual catalog-lock wait before
competing registration, reopening, latest-approval and canonical I-grant mutations invalidate the
reviewed assignment. The incident-shaped fixture verifies nine synthetic publications and prior
assignments, exact registration pins/results/marks/approvals/announcements/I/events/decisions,
canonical GPA/transcript, same-UUID resume, pure replay and repeated-resume idempotency.
