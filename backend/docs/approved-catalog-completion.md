# Approved catalog and curriculum completion

Status: implementation for a **Draft** PR; not a production acceptance or deployment.
Base: `8b296a88796bf77a2bbd2524d79d76965b8e39aa` (PR #169 merged).
Verification date: 2026-10-17, Asia/Damascus.

## Sources and scope

The allowlisted artifact is `backend/resources/academic-catalog/approved-2026-10.json`.
It retains all nine sheets, academic row keys, source locators, 67 expressions and their cached values,
24 decisions (academic authority remains separate from implementation safeguards), and academic before-images.
No workbook, raw database export, student identities, credentials or student execution list is committed.

- Workbook SHA-256: `3a2d8c3c395d8ede3bfb8dc2225bc7e8b2e3fe34aa81603368ec7faf534b18bd`.
- Manifest SHA-256: `2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238`.
- Supplied SQL SHA-256: `bb75f4674616ed872ac8f25c2b37821654d764f5961a1f2cf97a59bcb0cc8dd9`.

The read-only generator uses Python's standard ZIP/XML libraries:
`python -X utf8 backend/tools/build-approved-catalog-manifest.py <approved-workbook.xlsx>`.
It writes stdout only; the workbook is never edited. Byte-for-byte reproduction was executed successfully.
LF is pinned for checked-in JSON. Changing a source requires a reviewed artifact/version and corresponding
application/SQL allowlist update, not an operator-edited manifest.

`baseline-verification.json` records an offline recheck of the supplied export: all 15 program identities,
their student counts (909 in total), and all 565 catalog before-images matched.
This is **not** an inspection of the deployed database. Runtime matching uses current scoped rows and a
monotonic catalog revision; 909 is not a selection limit or a hardcoded student list.

| Program | Students in supplied export | Existing memberships | Existing groups | Legacy total | Approved target |
| --- | ---: | ---: | ---: | ---: | ---: |
| 18 — برنامج الإجازة في إدارة الأعمال | 119 | 61 | 6 | 151 | 151 |
| 19 — برنامج الإجازة في الاقتصاد | 13 | 61 | 6 | 151 | 151 |
| 20 — برنامج الإجازة في المحاسبة والتمويل | 19 | 61 | 6 | 151 | 151 |
| 28 — برنامج الإجازة في الهندسة المدنية والمشروعات العامة | 82 | 66 | 6 | 165 | 165 |
| 29 — برنامج الإجازة في هندسة الحواسيب والذكاء الصنعي | 26 | 69 | 6 | 164 | 164 |
| 30 — برنامج الإجازة في هندسة البرمجيات | 171 | 63 | 6 | 165 | 165 |
| 31 — برنامج الإجازة في معلم صف | 33 | 63 | 6 | 146 | 146 |
| 32 — برنامج الإجازة في رياض الأطفال | 17 | 62 | 6 | 150 | 150 |
| 33 — برنامج الإجازة في الإرشاد النفسي | 79 | 65 | 6 | 159 | 159 |
| 34 — برنامج الإجازة في التربية الخاصة | 22 | 64 | 6 | 156 | 156 |
| 38 — برنامج الإجازة في الإعلام والاتصال | 65 | 64 | 6 | 147 | 147 |
| 39 — برنامج الإجازة في الحقوق | 141 | 58 | 6 | 149 | 149 |
| 40 — برنامج الإجازة في التربية الرياضية | 76 | 0 | 0 | 147 | 141 |
| 41 — برنامج المعهد المتوسط في إدارة الأعمال | 42 | 0 | 0 | 82 | 82 |
| 42 — برنامج المعهد المتوسط في المحاسبة والتمويل | 4 | 0 | 0 | 82 | 82 |

Architecture 27, software institute 43, college 20/departments 43–45, and environment department 52
are excluded from curriculum completion. No missing program or unit is created.
The explicitly approved shared drawing-code correction affects existing architecture references by code
only; its membership rows, program and student assignments are not rebuilt.

## Academic decisions implemented

| Decision | Implementation |
| --- | --- |
| Sports | Reuse program 40; current name becomes «برنامج الإجازة في التدريب الرياضي». The approved plan has 141 credits; the old 147 remains on its transitional reference and legacy program field. Existing read projections expose the current plan's `plan_total_credit_hours`. TS423 is 3 credits. |
| Sports subject matching | Workbook subject classifications/contact provenance are retained, including college TS221 and department ES223, and the gymnastics/swimming/combat/athletics subject corrections. No prefix-based inference. |
| Institutes | Reuse 41/42, each 82 credits; SRC01 controls administration identities, SRC04 only identity-verified contacts, SRC05 accounting. No invented degree. |
| TIF125 | A separate public-relations subject, 3 credits and 2+2 contact hours. |
| Shared origins | TIF211 and TIF226 are each created once and linked with independent program placement. FIE221 keeps its verified shared ID and both source labels. |
| Computer/civil references | CEAI424/324 and FE111/112/411/412 follow approved subject identities; used-origin labels/hours are not swapped to satisfy a source code. |
| Civil drawing/chemistry | Exact D05 code-only permutation FE114→FE113 on drawing and FE113→FE114 on chemistry; IDs, 4/3 credits, names and all other fields remain unchanged. |
| Electives | CHOICE_SLOT rows are budgets, never origins. Preserve valid existing alternatives (including later runtime alternatives); elective pool capacity is not the graduation budget. Named sports alternatives are real separate rows. |
| Prerequisites | Resolve through program-specific reconciled identities, preserve existing minimum-result semantics, deduplicate edges and reject self/cycles. A missing edge on a used/fixed origin blocks publication, rather than changing historical context. |
| Special education | Source PSB321–326 are not merged with same-name PSC counseling subjects; no automatic equivalence. |
| Missing contacts | NULL stays unknown. No zero, theory-only, contact/credit conversion or 60/40 default is invented. |

## Runtime contract and authorization

Commands are server-side maintenance operations, not new HTTP endpoints:

- `academic-catalog:complete`: read-only preview by default, with actual before/proposed plans, row evidence,
  six independent budgets, dependency groups, current/archived student counts, old progress availability and blockers.
- `academic-catalog:complete --apply`: explicit guarded mutation.
- `academic-catalog:completion-status <UUID>`: authorized durable outcome read.
- `academic-catalog:verify-completion`: read-only comparison of actual plan contents, pinned source contents,
  defaults and every current student assignment.

All require an existing active authorized actor through ScientificProgramAccess/ScientificCourseAccess and
the actual scope of every included program and referenced course. Apply uses current plan-management,
approval, assignment, structure-management and course-management permissions. Central super-admin behavior
is unchanged. No role, permission or scope is granted by these commands. Receipt reads require the same
authorized actor and current scope; knowing a UUID is not authority.

The artifact is allowlisted by exact bytes, not an arbitrary bulk-write JSON payload.
Every row resolves to a verified existing identity, an approved new definition, a budget-only slot, or
BLOCKED with its row key/evidence. Supplied candidate IDs are checked against full current before-images.
Unassigned candidates require exact normalized subject/credit evidence in the same program; fuzzy matches
and source-code-only matches are not accepted. The 19 initially unassigned verification candidates are listed
in the baseline report, not silently merged. The actual runtime report determines which remain blocked.

### Transactions, replay and resume

The existing catalog-control lock is first. Authorization is rechecked under it.
An existing actor/manifest-matching receipt is returned **before** comparing the current revision.
Different content/identity is rejected. Each connected new-origin group is independently atomic:
pin all its old references, create at most 50 origins, resolve edges, copy the source, save all memberships
and explicit six-group values, run canonical approval, then select the new default.
A failure rolls back that entire group, including reference fixation and new origins.
Other independent groups can finish; dependent blocked programs are not published.

No draft is selected arbitrarily; existing drafts require explicit review.
Already matching approved plans are attested operationally rather than copied into another plan.
No-op runs do not produce a false academic change. Textual name corrections are separately audited.
Actual before/after requirements, memberships and names are recorded in existing immutable plan events.
Large receipts are atomically chunked in existing TEXT activity logs with an exact reconstruction hash;
no new receipt table is introduced.

External writers between stages invalidate the reviewed epoch. There is no automatic write retry.
An interrupted root may be IN_PROGRESS while some stage receipts are confirmed.
A repeated request without `--resume` reads its saved outcome; it does not silently continue.
Explicit resume uses the same UUID/manifest and a newly reviewed revision, verifies saved stages,
and only attempts outstanding work. A NOT_FOUND receipt is not rollback evidence.

### Existing students and history

Canonical fixation retains existing membership/group IDs, inactive groups, NULL budgets and current
academic evidence, assigns all existing students including soft-deleted records, and pins old operational
records to their original context. It does not invent past approval.
Fixation explicitly preserves updated_at, including tables whose MariaDB TIMESTAMP has ON UPDATE CURRENT_TIMESTAMP;
the identity-only civil correction also preserves the original timestamp. A database-managed timestamp must
not disguise provenance pinning as a new academic edit. The isolated fixture exercises this behavior.
Published plans are explicit approved defaults for future admissions; the transitional reference is never
made approved/default implicitly.

Existing students are examined in explicit batches of at most 50.
The dedicated internal completion assignment boundary reuses canonical progress, official results,
equivalences, attempts, graduation calculations and transfer blockers.
Only the exact recorded transitional source may have unavailable old progress; it remains
`available=false`, never zero. This exception is not available through the ordinary transfer HTTP path.
Pending registrations/requests, appeals, supplementary materialization, unresolved decisions and finalized
graduation remain blockers. Completed registrations are not relinked to the new plan.
Each successful transfer records old/new assignments and full effects; blocked students stay on the source.

A publication hashes material results, component marks, approvals/events, I cases/events, announcements
and existing academic decisions/snapshots before and after, excluding only the provenance fields that
canonical fixation is authorized to populate. Pinned source groups/memberships/mappings are hashed
separately, retaining IDs and values. No GPA recalculation or result rewrite is performed.
Overall PARTIAL/BLOCKED and separate already-assigned/transferred/blocked records are returned whenever
any program/student remains blocked. They must not be reported as all students completed.

## Exact SQL extension

`backend/database/sql/approved-catalog-completion/` is the only new manual SQL package.
No migration, table, grant or academic backfill is added.

- 00_preflight and 02_verify are read-only exact-definition inspections.
- 01_apply refuses unknown installed trigger/function definitions before replacing the two known UPDATE
  guards. Compatible partial installation and reapplication are supported.
- The function signature, body, target/timing/operation of both triggers and existing readiness controls
  are checked. The existing plan verifier recognizes the exact extension only with its exact function.
- The permit is an append-only plan event bound to the manifest, root operation, actor, connection and
  exact previous subjects/codes. A session contains its event ID, not a boolean bypass.
- Only the three uniqueness-safe D05 transitions are allowed, all other academic fields unchanged.
  The scratch code is collision-checked, and permit/context is cleared in finally.
- Both ordinary guards remain active. No trigger/FK is disabled and no approved result is unlocked.
- 03_rollback restores the previous known guards only if the extension has never issued a permit.
  Once history exists it refuses; a reviewed forward fix is required, never deletion of audit/history.

The installed plan package must be verified before this extension. The earlier catalog verifier describes
pre-version indexes/trigger counts and is not the post-plan verification contract; do not treat its FAIL
on a versioned schema as permission to reinstall old indexes. If a base installer is rerun it restores
its conservative original guard, so reapply and reverify the extension afterward.
Do not run older code against newly changed guards without the documented compatibility review.

## Maintenance runbook (not executed on production)

1. Back up database, application version and existing trigger/routine definitions. Test restoration.
2. Pause affected application writes, queues, scheduled writers, admissions, catalog, registration and
   grade/assignment workers. Do not allow old/new writers concurrently during installation.
3. Verify the installed academic-program-management package (02_verify); its contract supersedes the
   pre-version catalog index/trigger verifier. Inspect the new 00_preflight: require READY.
4. Apply 01_apply with a client that stops on SQL errors; verify 02_verify: require PASS.
   In phpMyAdmin, run these files separately and stop on the first error. Never use a force/continue mode.
5. Start the compatible code only while writes remain paused. Use an existing authorized actor.
6. Run preview; privately retain/review full row, program and student evidence. No runtime count is assumed.
7. Apply only the reviewed manifest, epoch and operation UUID.
8. Query status after any disconnected response. Do not generate a new UUID to work around uncertainty.
9. Inspect verify-completion, blocked rows/students, source hashes and independent group outcomes.
   Resolve academic ambiguities through reviewed source decisions; do not patch a used origin to force success.
10. Resume only after an explicit new preview/review. Reopen writers/workers only after compatibility,
    official results, new admission defaults and operational blockers have been checked.

Example, from backend (replace placeholders, never run blindly):

```text
php artisan academic-catalog:complete --actor=<authorized-user-id>
php artisan academic-catalog:complete --apply --actor=<authorized-user-id> --manifest=resources/academic-catalog/approved-2026-10.json --manifest-hash=2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238 --expected-revision=<reviewed-epoch> --operation-id=<uuid>
php artisan academic-catalog:completion-status <same-uuid> --actor=<same-user-id>
php artisan academic-catalog:verify-completion --actor=<same-user-id>
php artisan academic-catalog:complete --apply --resume --actor=<same-user-id> --manifest=resources/academic-catalog/approved-2026-10.json --manifest-hash=2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238 --expected-revision=<newly-reviewed-epoch> --operation-id=<same-uuid>
```

## Verification and remaining acceptance

Executed so far:

- Manifest reproducibility, private-export aggregate/identity/before-image recheck, all 90 budgets.
- 31 focused completion/plan/legacy-display PHPUnit tests: 501 assertions, passed, including actual console
  default-preview/required-flag behavior and explicit stage resume.
- Six-file related course/I/workspace/program PHPUnit run: 98 tests, 97 passed, one inherited failure.
  `ScientificCourseManagementTest::test_read_endpoints_emit_no_for_update_with_mysql_query_grammar`
  fails because its MySQL-grammar fixture lacks `user_roles.assigned_at`. Reproduced with the same
  installed dependencies on a separate clean code worktree of the exact base SHA. Not fixed or hidden here.
- Composer validate --strict and check-platform-reqs passed; no packages installed.
- Six dependency-free PHP contracts passed (artifact/program/workspace/catalog/I Phase 1/I Phase 2);
  these are static/pure-logic checks only.
- Isolated MariaDB **10.11.18-MariaDB**, **REPEATABLE-READ**: publication/archived assignment/replay/no-op/
  future admission, material result/approval/announcement comparisons, exact code-only permutation and rollback,
  unchanged excluded memberships, normal guard denial, package reapply, unknown no-op trigger rejection,
  runtime readiness and history-safe rollback refusal passed.
- Independent connections with observed catalog lock waits: catalog text write, admission, grade write and
  audited assignment versus stale completion; exact concurrent replay without extra plans; admission
  serialization to the explicit default passed. SQLite is not the evidence for these claims.
- Existing I MariaDB suite passed on **10.11.18**, including both fallback and installed catalog paths,
  real HTTP/middleware, lifecycle/calculation/calendar cases, independent races, schema negatives,
  expiration progress and immutable history.

The final expanded MariaDB run passed component/event content, canonical transcript/GPA, historical
registration/progression contents and ON UPDATE timestamp preservation, exact function signature checks,
and installed plan-verifier compatibility. Program 40/41/42 synthetic fixtures exercise empty
and incomplete old contexts; other fixture programs are deliberately identity-blocked to test independent
progress. These are not evidence that all 15 live programs or the 909 real students have been migrated.

Browser recheck: enabled surfaces returned no browsers; opening the permitted IAB returned
`Browser is not available: iab`. No sandbox bypass attempted. Live scientific-portal rendering against
Laravel, desktop/mobile visual review and screenshots remain **unexecuted**. No frontend was changed;
there is no visual/end-to-end or production acceptance claim.

PHP syntax passed for all 14 changed PHP files; staged/unstaged diff checks passed.
The final MariaDB completion harness and the existing I harness both finished successfully and shut down
only their verified disposable instances. No production database, student execution or deployment occurred.

Synthetic before/after highlights (not a university migration report):

| Program | Old memberships/groups | Old total | New groups/total | Existing-student result |
| --- | --- | ---: | --- | --- |
| 40 | 0 / 0 | 147 | 6 / 141 | Guarded assignment; original official result, components, transcript/GPA and announcement retained |
| 41 | 1 / 1, inactive NULL-budget group | 82 | 6 / 82 | Incomplete source and IDs retained; missing budget completed only in new plan |
| 42 | 0 / 0 | 82 | 6 / 82 | Archived synthetic student included |

verify-completion emits actual before/after membership counts, group counts and totals for each recorded
publication. It does not substitute workbook expectations for unexecuted database outcomes.
