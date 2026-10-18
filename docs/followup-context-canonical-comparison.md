# Follow-up catalog persisted-context comparison repair

## Scope and root cause

Base: develop `2e4093120e1d96f0f8c6ca7ab5a3dbf73acec3a1`, after merged PR #175. This repair changes only the comparison in `FollowupCatalogService::project`, plus synthetic tests and documentation. No academic decision, source/profile revision, preparation logic, artifact encoding, permission, schema or workflow changes.

`CompletionFiles::write` uses `CompletionData::tokens`, which sorts associative keys. `CompletionSources::load` validates reconstruction and returns the decoded persisted bundle. The live context builder inserts the same keys in a different order. PHP strict array equality compares insertion order too, so strict `in_array($reference, $contexts, true)` rejected equivalent records. In-memory bundles hid this boundary.

The service now compares the existing canonical SHA-256 representation of each complete reference. Every reviewed reference must still be present. Kind, reference ID, department, program and requirement-group fields remain part of the comparison; missing fields and string IDs versus integer IDs do not match. Additional live references remain allowed. Subject, activity, credits, scope, before-images, revisions, authorization and publication checks are unchanged. The input arrays/artifacts are not sorted, rewritten or normalized in place.

The other PR #175 comparison paths were reviewed. Course/program before-images, profile/config/review fingerprints and origin receipts already use canonical hashes. Scalar identity fields and ordered prerequisite-code lists do not have the associative-record problem. Approval-array de-duplication runs within regenerated source derivation, not persisted-versus-live context comparison: the v5/v6 loader regenerates those definitions before comparing canonical fingerprints. No other verified persisted/live ordering defect was found, and no speculative comparison changes were made.

## Existing artifacts and operator order

Keep the current immutable **v6 bundle**, source files and previous preview/review outputs. If its original manifest hash and actual source fingerprints still pass `CompletionSources::load`, **do not edit or regenerate it for this comparison-only fix**. No new academic/profile revision is required. A source/reconstruction error still needs its own investigation; never change hashes to make a bundle load.

After a separately authorized deployment, run only a **fresh read-only preview** with a new output filename. From the backend directory:

```sh
sha256sum /private/followup/approved-identity-v6-bundle.json
php -d memory_limit=768M artisan academic-catalog:complete --actor=<authorized-catalog-actor-id> --manifest=/private/followup/approved-identity-v6-bundle.json --manifest-hash=<original-immutable-v6-file-sha256> --output=/private/followup/context-comparison-preview-20261018-01.json
```

On PowerShell use `Get-FileHash -Algorithm SHA256 -LiteralPath <existing-bundle-path>` instead of `sha256sum`. Compare with the original recorded manifest hash; a hash recomputation does not itself authorize changed content. Replace the example private path with the existing file's actual path, not a copied/rebuilt artifact. Choose a new output name if this one already exists. The command also writes `context-comparison-preview-20261018-01.json.review.json`; neither file may overwrite an earlier preview.

Read the actual `status`, all blockers, current `review.revision`, source/profile identities and canonical `review_hash`. The production evidence shows a read-only preview at **5320**, not an apply. Fixing false positives does **not** prove the database is unchanged or guarantee READY. Do not automatically reuse 5320 or the old review hash. This repair performs no production connection, preview or apply.

Only a future separately authorized application may use the **new** review file/hash/revision and a **newly generated, privately persisted operation UUID**. There is no shown catalog operation to resume. Following a successful application, run publication verification against the same immutable bundle before obtaining a fresh live student preview. No student application is part of this repair.

## Regression design

`PersistedFollowupCatalogFixture` creates only synthetic database records and tiny synthetic handoff/SQL input files outside the checkout. The SQL is read as data, never imported. Its aggregate synthetic institute subjects keep serialization tests small while still passing the real plan validation rules; they are not proposed academic curriculum changes. The fixture uses all 27 reused-intent references across four programs and multiple independently required university contexts.

`FollowupPersistedContextTest` uses real `CompletionFiles::write`, `CompletionSources::load` and `Artisan::call('academic-catalog:complete', ...)` with a real active scientific actor, university scope and assigned permissions, not a mocked loader/service or super-admin bypass. It checks source hashes, zero database writes/locking statements during preview, unauthorized actor rejection, altered required-record fields (including types), actual changed/missing live relationships and allowed additional live references.

Before the fix, the command regression failed with **27 `course_identity_context_changed` blockers and no other blockers**. After the fix, the same persisted path passes. The changed/missing identity cases continue to block rather than weakening the comparison to any one surviving university reference.

The isolated MariaDB runner additionally persists a v6 bundle, loads it in a separate worker, publishes a stage, introduces an independent catalog revision, rejects stale continuation, then explicitly resumes through the real command using its persisted review. It checks publication verification, pure replay, unchanged artifact hashes, academic content and prior committed stage data. These are synthetic tests only, not an instruction to resume a production operation.

## Verification record

- Focused source/catalog/admission/persistence suites: **126/126 tests, 1,186 assertions**. The new serialization suite alone is **15/15, 478 assertions**. SQLite behavior tests do not prove MariaDB locks.
- Affected plan/catalog/placement/result suites: **91/91 tests, 13,590 assertions** (`AcademicPlanWorkflowTest`, `LegacyAcademicPlanDisplayTest`, `ApprovedCatalogCompletionTest`, `ElectiveAdvisoryPlacementContractTest`, `AcademicProgramManagementContractTest`, `CourseResultSymbolsContractTest`, `IncompleteResultReadBehaviorTest`, `CourseResultSymbolsBehaviorTest`).
- Independently reconstructed the locally retained issued **v3/v4/v5/v6 artifacts** against their original actual sources; every file/profile hash remained unchanged. The local v6 file is still `c396e7fba0dd9028999218fc174d655a16932dee876892c5effa72f5240a80ff`, profile `6b72cc135a22b9497e0ea023e5ced4e7a24be64bf3dec2278c034ccbb22fb2ad`. This is not inspection of the production file or production readiness.
- The complete isolated `tests/mariadb/student-completion.ps1` runner passed on **`SELECT VERSION() = 10.11.18-MariaDB`** with installed catalog/plan revision guards. This includes the existing independent-connection races and the new persisted-v6 command preview, independent post-publication revision conflict, explicit command resume, publication verification and historical replay. All synthetic input hashes, prior stage receipts and academic content remained unchanged. The verified disposable server was stopped; no real SQL snapshot was imported.
- Five independent static/pure contracts passed: approved catalog, academic programs, scientific workspace, and result-symbol phases 1/2. These are not behavioral/browser tests.
- Registration/progression source contracts: **19/22 pass**, the same three inherited `effectivePermissions()` / `ProgramCourse::query()` literal failures reproduced on a clean worktree at **2e40931**. No historical test was weakened or skipped.
- PHP **8.4.14** syntax for all four changed/new PHP files, Composer **2.8.12** validation/installed platform requirements, and `git diff --check` passed. No dependencies installed.

Intermediate test-fixture failures were corrected before the final reruns: cloning a generated `plan_scope_key`, counting an intentionally added shared-course membership outside the original historical owner, and inconsistent synthetic elective labels colliding with an earlier fixture's identities. These were test-data/assertion issues, not relaxed application guards. The original historical-owner count and complete before/after historical-row comparisons remain enforced.

To reproduce the new focused behavior coverage from `backend`, run `php vendor/bin/phpunit tests/Feature/FollowupPersistedContextTest.php`. The isolated MariaDB runner is `powershell -NoProfile -ExecutionPolicy Bypass -File backend/tests/mariadb/student-completion.ps1 -MariaDbHome <existing-MariaDB-10.11.18-directory>` from the repository root; it creates its own marked disposable server and synthetic fixtures. Neither command targets a production database.

No production or real-data application, SQL package, migration, frontend or marks import. No browser, deployment or full CI acceptance is claimed. A fresh operator-run live preview remains required to establish actual readiness.
