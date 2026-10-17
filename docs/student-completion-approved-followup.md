# Approved missing-program and student-completion follow-up

## Scope and authority

Base: `86d27bd` (merged PR #173). This increment uses the existing five student-completion commands and the existing three catalog-completion commands. It adds no web route, frontend, permission, dependency, migration, SQL package, offering, registration or marks import. Nothing here is a production application or a historical plan approval.

The new private `student-completion.sources.v3` contract retains the E-then-C decisions, original observations and previous ownership evidence. It pins the handoff tables, original source files and exact 28 approved observation references. A source number is never a number-wide replacement. The competing canonical owners remain separate. Matching copies retain their references; regular and undated supplementary observations remain different evidence. Ownership resolution does not establish an examination date or settle conflicting marks.

New decisions are separate from original source facts:

- Missing students only: administratively approved active default; explicitly approved frozen status wins. Existing statuses and documented enrollment dates are not overwritten.
- Missing dates only: September 1 of the earliest unambiguous **regular examination** academic year. An undated supplement, filename, number prefix or curriculum term is not evidence. The named explicit date is recorded as an administrative estimate too.
- Explicit compound identity components are retained, not mechanically split. The newly approved program remains college-qualified, and its numeric source hint is not used as identity.
- Existing transfer requirements and genuine ongoing academic-work blockers remain independent of administrative status authority. An identity-only proposal cannot change status.

## Approved catalog intent

The private manifest includes original curriculum rows, source hashes, existing-origin before-images, exact department identity and user amendments. All four contexts are required in the report; a blocked context is not silently omitted. University Architecture is excluded; the Engineering Institute drawing program is distinct.

| Institute program | Total | University mandatory | University elective | Institute mandatory | Other three budgets |
|---|---:|---:|---:|---:|---:|
| Civil / general construction | 85 | 12 | 4 | 69 | explicit 0 |
| Surveying | 80 | 12 | 2 | 66 | explicit 0 |
| Engineering drawing | 78 | 12 | 2 | 64 | explicit 0 |
| Environment / sustainable development | 74 | 12 | 4 | 58 | explicit 0 |

“Institute” uses the canonical `college` requirement scope. Missing is never silently zero. The approved zero budgets are new explicit decisions, not corrections to a historical reference.

Deterministic identity amendments:

| Source context / subject | Source code | System code | Credits |
|---|---|---|---:|
| Civil / general physics | TI112 | TI-CE-112 | 4 |
| Surveying / general physics | TI112 | TI-E-112 | 3 |
| Civil / chemistry | TI113 | TI-CE-113 | 4 |
| Surveying / engineering drawing | TI113 | TI-E-113 | 4 |
| Environment / global environmental issues | ESI112 | ESI112 | 2 |
| Environment / climate, earth and people | ESI114 | ESI114 | 2 |
| Environment / social projects and small business | ESI224 | ESI224 | 3 |
| Environment / graduation project | ESI224 | ESI228 | 3 |

The four extracted university elective alternatives use `ESI-UE-01` through `ESI-UE-04`, two credits each, with a four-credit budget. They are actual alternatives, not `RUS11X` / `RUS12X` choice-slot origins. Their advisory placement is unset; no source placement was invented. The original environmental workbook was not available locally: the exact cached extraction in the pinned handoff is used, with its recorded original-file hash, not a claim of independently hashing the missing original. The selected 2023 examination workbook is the Downloads copy matching the handoff; the different same-named D: copy was rejected. The actual second filename is `data 2025.xlsm`.

Existing course origins are reused only against reviewed code/credit/before-images and actual prerequisites. Names/contact hours/prerequisites of used origins are not rewritten. New origins and complete memberships/groups are passed through canonical course, workspace, validation, approval and default services. Existing incomplete references are fixed **before** any related origin/plan completion. Each program is transactional: approval failure rolls back its program, draft, origins and events; independently valid contexts may continue. Same-operation shared origins require their exact earlier saved receipt/publication evidence, not a collision bypass.

A newly created program is never published empty. Its first complete approved plan has no fake transitional predecessor. Historical CLI admission to that particular program requires the reviewed profile hash, exact publication event, new-program provenance, current approved fixed target and actual content fingerprint. The default alone is insufficient. The event states “approved now from recorded source curriculum, not historical approval.” Ordinary admission hooks and database assignment triggers remain active. Existing-program transitional admission remains unchanged; no general transfer path is relaxed.

## Operating order (operator-run only)

Use fresh private paths **outside Git and the web tree**. Preserve old sources, bundle, reviews, UUIDs, receipts and outputs. `CompletionFiles` refuses overwrites. Every large JSON command below explicitly reserves 768 MB. Placeholder hashes/IDs must be replaced from the newly generated outputs, not copied from an old SSH shell or guessed. The review hash is the command's canonical `review_hash`, **not** a hash of the formatted review file.

1. Prepare from locally readable, hash-verified originals and the newly approved private source configuration. This is data-only, does not resolve an actor or database service, and does not execute SQL or VBA:

```sh
php -d memory_limit=768M artisan student-completion:prepare --sources=/private/followup/sources.json --output=/private/followup/new-bundle.json
```

2. A currently authorized scientific operator with all four actual department scopes (or the existing super-admin guard) previews catalog prerequisites. The `--manifest` for **this** follow-up is the v3 source bundle. Existing pinned v1 catalog manifests continue using their original command contract:

```sh
php -d memory_limit=768M artisan academic-catalog:complete --actor=<catalog-actor-id> --manifest=/private/followup/new-bundle.json --manifest-hash=<bundle-file-sha256> --output=/private/followup/catalog-preview.json
```

Review its `review`, blockers, before-images, six budgets, source amendments and proposed origins. Do not substitute an unrelated existing draft. Unexpected hierarchy, origin or prerequisite evidence remains blocked. The command also writes `catalog-preview.json.review.json`.

3. Only after explicit authorization, apply with a **new** catalog UUID and the exact immutable review:

```sh
php -d memory_limit=768M artisan academic-catalog:complete --apply --actor=<catalog-actor-id> --manifest=/private/followup/new-bundle.json --manifest-hash=<bundle-file-sha256> --review=/private/followup/catalog-preview.json.review.json --review-hash=<catalog-review-hash> --expected-revision=<catalog-review-revision> --operation-id=<new-catalog-uuid> --output=/private/followup/catalog-result.json
php -d memory_limit=768M artisan academic-catalog:completion-status <same-catalog-uuid> --actor=<same-catalog-actor-id> --manifest=/private/followup/new-bundle.json --manifest-hash=<bundle-file-sha256> --output=/private/followup/catalog-status.json
php -d memory_limit=768M artisan academic-catalog:verify-completion --actor=<catalog-actor-id> --manifest=/private/followup/new-bundle.json --manifest-hash=<bundle-file-sha256> --output=/private/followup/catalog-verification.json
```

No automatic write retry after disconnection. The same actor/content UUID returns its durable receipt **before** rejecting an old revision. `--resume` is explicit, uses the same immutable review/UUID, and requires the currently reviewed epoch. Saved stages are not repeated. A changed source/proposal needs a new bundle, review and UUID, not an edited old receipt. Re-read status before resuming; a missing receipt is not rollback proof.

4. After catalog verification, create a **fresh live student preview**, using the existing student-policy/read/manage permissions and actual program scope. The offline snapshot is not apply authority:

```sh
php -d memory_limit=768M artisan student-completion:preview --actor=<student-actor-id> --bundle=/private/followup/new-bundle.json --bundle-hash=<bundle-file-sha256> --output=/private/followup/student-preview.json
php -d memory_limit=768M artisan student-completion:apply --actor=<same-student-actor-id> --bundle=/private/followup/new-bundle.json --bundle-hash=<bundle-file-sha256> --review=/private/followup/student-preview.json.review.json --review-hash=<student-review-hash> --expected-revision=<student-review-revision> --operation-id=<new-student-uuid> --output=/private/followup/student-result.json
php -d memory_limit=768M artisan student-completion:status --actor=<same-student-actor-id> --operation-id=<same-student-uuid> --output=/private/followup/student-status.json
php -d memory_limit=768M artisan student-completion:verify --actor=<same-student-actor-id> --bundle=/private/followup/new-bundle.json --bundle-hash=<bundle-file-sha256> --operation-id=<same-student-uuid> --output=/private/followup/student-verification.json
```

The student writer retains catalog-first locking, per-student transactions, locked evidence/review checks, actor-bound receipts and explicit resume. Never transfer an existing student, change enrollment evidence, clear a blocker by changing registrations/results, or import marks through these commands.

## Earlier operation verification

Run `verify` with its **original** bundle and UUID. Old v1/v2 bundles remain reconstructible; extra catalog tables are read only for v3 follow-up preparation. A receipt is historical, not an instruction to restore old current values.

Verification separately returns `current_state_matches`, `current_state_verdict`, `subsequent_audit` and the academic-evidence comparison. An unbroken later completion receipt chain, with exact before/after fields and preserved assignment context, can explain a legitimate later correction; it does not make the old current-state comparison true or dismiss academic-evidence changes. Unexplained changes require an audit investigation, not automatic “damage” classification, repair, or success. Changes made by other workflows require their own audit evidence review; this increment does not invent such proof.

## Executed verification and limits

Private source analysis uses the latest supplied snapshot as data only: 912 existing students; union 1008; 96 missing; **5 ready / 91 blocked before catalog completion**. No existing identity corrections or status writes are ready in that snapshot. The 18 previously completed students' relevant fields match (15 corrections + 3 creations); this is a snapshot comparison, not live receipt/production verification. Two actual program-transfer requirements remain. The missing population remains 68 with examination observations and 28 without; these are not the separate 28 ownership-resolved source observations.

All 16,455 source examination observations remain represented; 15,887 deduplicated evidence observations; zero unassigned examination observations. The difference from the prior 15,864 is 23 newly assigned evidence observations after collapsing five matching copies, not 28 new independent attempts. Undated supplementary chronology and unsupported identities/levels remain review issues. Counts after catalog application require the fresh live preview above; they are not predicted production results.

Executed: full source reconstruction/hash validation and offline preparation with a deliberately nonexistent DB connection; PHP syntax for every changed/new PHP file; Composer validate and platform requirements (PHP 8.4.14, Composer 2.8.12); 68/68 focused behavior/source tests (375 assertions); affected plan/catalog/result-symbol suites; `git diff --check`. The affected 100-test run passed 97 and failed three inherited source-literal contracts, reproduced unchanged on clean base `86d27bd` (19/22). These expect old `ProgramCourse::query()` / `effectivePermissions()` literals and were not weakened or changed. The one-sheet private Arabic report was rendered, its 1,008 identities / 11 columns / formula totals inspected, and native RTL metadata added with an assertion that values, formulas and caches were unchanged. It was not inspected in the native Excel application.

The isolated MariaDB runner was attempted but its previously recorded 10.11.18 executable was absent; no server was started and no `SELECT VERSION()` ran for this increment. The expanded independent-connection scenarios are present but **unexecuted**. SQLite behavior is not MariaDB lock/trigger/schema evidence. No dependency was installed to mask that limitation.

No production connection/apply, marks import, merge or deployment occurred. This backend-only increment has no new visual acceptance claim. Full CI and real operator application remain pending.
