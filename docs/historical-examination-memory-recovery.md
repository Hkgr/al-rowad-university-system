# Historical examination completion: bounded-memory recovery

This fix is backend/CLI only. It changes transport/storage residency, not academic classification, marks, selected sources, source/profile/review contracts or hashes, credits, permissions, locks, adoption or publication. It adds no schema, SQL, migration or dependency. No production connection or real-data application was performed during implementation.

## Cause and implementation

The previous CLI decoded an entire review while retaining the bundle. `CompletionReceipts::find()` then fetched every chunk as an Eloquent model, concatenated all base64, decoded another complete JSON string, and decoded the review again. Initial validation also retained a full fresh projection. `status`, `verify` and resume repeated the same receipt hydration. Increasing `memory_limit` merely moved the failure.

- `CompletionJson` parses envelopes incrementally. Large action/source lists are private, process-lifetime `CompletionRecords` spools, not resident decoded populations. Only an offset index and one record are decoded at once. Small populations remain array-compatible only below both 128 records and 4 MiB encoded content.
- Both projection contracts spill observations/actions while retaining the same selection/group ordering and canonical bytes. The approved projection's supplementary lookup retains reference IDs rather than copies of complete actions. Counts use bounded passes, not `collect()`/`array_map()` materialization of all actions.
- The canonical token encoder recognizes spooled lists; buffered canonical blocks preserve the exact existing bytes/hash. Existing immutable bundles and reviews do **not** need regeneration or editing.
- Audit receipt writing streams canonical JSON to a temporary file, hashing incrementally, then emits the **same** 24,000-byte / 32,000-character base64 chunks and manifest. Receipt reading uses bounded keyset pages, checks every ordinal, count, encoding and whole-content SHA-256, then spills the large nested lists. Old chunk records/manifests remain readable and unchanged. Missing/reordered/corrupt/extra chunks reject; they are never repaired or deleted.
- Existing receipt envelopes must remain structurally valid and match their chunk manifest. An invalid/empty envelope is a controlled conflict, not an absent operation that can be restarted.
- Apply/result/status/verify/resume use bounded action lists. Existing authorization, scope, catalog-first locking, expected revision, fresh revalidation, preserved evidence, per-action UUID receipts and explicit same-operation replay/resume remain in place. A stored action is revalidated before reuse, not written again. Drafts still require the existing normal approval path; staged proposals are not adopted.
- `historical-exams:status --recovery-check` adds an authorized **read-only** diagnostic: stored contract/hash/bundle hash/review revision, current catalog revision, original start log ID, result presence, action-receipt header count and operation-owned staging/credit counts. It grants no right to resume and does not replace verification.

Private spools require a **disk-backed**, writable, owner-private temporary directory with several GiB free, not a public web path, shared-readable directory or a RAM-backed `tmpfs`. PHP uses its configured system temporary directory. Set `TMPDIR` (Linux) or `TEMP`/`TMP` (Windows) before starting the process; confirm PHP's `sys_get_temp_dir()` and the mount/filesystem. Files are mode 0600 where supported and close/delete with the process. Disk/read/write failures stop the operation; do not infer rollback or repeat a write automatically. Retain the durable audit receipts and all immutable input/output files.

Spilled lists are internal iterables: use `CompletionData`/`CompletionFiles`, not `json_encode()` or `iterator_to_array()` on a full population. JSON records have a 64 MiB individual-value limit and JSON nesting is validated; an unsupported oversized/corrupt record fails explicitly, never by dropping observations. Canonical database snapshots and original-source reconstruction still use the existing readers; this is not a promise of constant memory for arbitrarily large databases/workbooks. Acceptance must measure the actual complete path at representative size.

## Incident-specific operator sequence (NOT executed on production)

The supplied incident is operation `a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52`, actor `15`, start log `12331`, reviewed revision `6243`, contract `historical-exam-completion.review.v5`. The supplied fingerprints below are **conditions to check**, not evidence that the current live state is unchanged. No fresh production preview/apply has been performed by this implementation.

After separately reviewing/deploying the fix through the normal release process, use the original, unchanged bundle and the **companion `.review.json`**, not the outer report. Choose a fresh private output directory and filenames. Do not overwrite old artifacts. Do not raise the memory limit beyond the tested 2 GiB budget.

Use a fresh CLI process from one consistent release/autoload tree. Do not replace PHP files beneath a running import: lazy class loading can otherwise mix old and new method definitions. Check for any still-running affected import/worker before an operator-authorized code rollout/recovery; no production process or service was stopped by this implementation.

```bash
umask 077
export TMPDIR=/absolute/private/disk-backed-exam-scratch
export EXAM_RECOVERY_DIR=/absolute/private/fresh-recovery-directory
export EXAM_BUNDLE=/absolute/private/original-immutable-bundle.json
export EXAM_REVIEW=/absolute/private/original-immutable-report.json.review.json

# These directories must already exist with owner-only permissions and adequate disk space.
php -d sys_temp_dir="$TMPDIR" -r 'echo sys_get_temp_dir(), PHP_EOL;'
df -hT "$TMPDIR"

# FIRST: read the same operation, after current account/scope authorization. No write or resume.
php -d sys_temp_dir="$TMPDIR" -d memory_limit=2048M artisan historical-exams:status \
  --actor=15 --operation-id=a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52 \
  --recovery-check --output="$EXAM_RECOVERY_DIR/status-before-01.json"

sha256sum "$EXAM_BUNDLE"
```

Before following the incident-specific resume below, inspect the diagnostic and independently confirm **all**:

| Check | Required unchanged start-only incident state |
| --- | --- |
| Actor / operation / start log | `15` / `a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52` / `12331` |
| Status / final receipt | `INTERRUPTED` / `exam_result_present=false` |
| Stored review contract | `historical-exam-completion.review.v5` |
| Stored canonical review hash | `b3fbe461060955490341765768c6833490bd07e4f614b19cc48c0a50f5c3e190` |
| Stored bundle hash AND actual bundle file SHA-256 | `23ae2e784650c5e93bbf3cd01aa8b337e2df740f565a5b2790f71b13dcd5e2b6` |
| Stored review revision AND current catalog revision | Both `6243` |
| Saved action receipt headers / staged work / credit evidence | All zero for this start-only incident |

The **canonical review hash is not the file SHA-256**: canonical hashing excludes the writer's trailing newline. Do not edit the companion review to make it match. The apply command verifies its canonical content and the unchanged bundle/source reconstruction itself.

If any field differs, the account/scope is no longer authorized, evidence/chunks fail integrity, original sources are missing/changed, or the revision advanced: **STOP**. Retain the diagnostic. Do not substitute the new revision, create another UUID, delete the start/chunks or regenerate/modify the review to bypass a guard. If a final receipt already exists, use status and verify; do not issue a replacement write. If actions have committed since the supplied incident evidence, inspect their immutable receipts and run read-only verification before deciding a separately reviewed recovery path; the start-only command below is not authorization for that changed state.

Only after the unchanged-state conditions are established:

```bash
# Explicit same-operation resume, original intent and revision. This does NOT adopt corrections.
php -d sys_temp_dir="$TMPDIR" -d memory_limit=2048M artisan historical-exams:apply \
  --actor=15 \
  --bundle="$EXAM_BUNDLE" \
  --bundle-hash=23ae2e784650c5e93bbf3cd01aa8b337e2df740f565a5b2790f71b13dcd5e2b6 \
  --review="$EXAM_REVIEW" \
  --review-hash=b3fbe461060955490341765768c6833490bd07e4f614b19cc48c0a50f5c3e190 \
  --expected-revision=6243 \
  --operation-id=a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52 \
  --resume --output="$EXAM_RECOVERY_DIR/resume-result-01.json"

# Then retrieve the durable result without another write.
php -d sys_temp_dir="$TMPDIR" -d memory_limit=2048M artisan historical-exams:status \
  --actor=15 --operation-id=a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52 \
  --recovery-check --output="$EXAM_RECOVERY_DIR/status-after-01.json"

php -d sys_temp_dir="$TMPDIR" -d memory_limit=2048M artisan historical-exams:verify \
  --actor=15 --operation-id=a1a5a722-9b2f-49c6-9f0c-0044cb9f8c52 \
  --bundle="$EXAM_BUNDLE" \
  --bundle-hash=23ae2e784650c5e93bbf3cd01aa8b337e2df740f565a5b2790f71b13dcd5e2b6 \
  --output="$EXAM_RECOVERY_DIR/verification-01.json"
```

`PARTIAL` / exit 2 can describe retained blocked/deferred/staged work; it is not automatic evidence of a failed transaction or complete import. Inspect every actual action outcome and verification report. The reported 1,854 draft candidates and 1,409 staged candidates are not a promise of live success, approval or adoption. A lost response calls for status/verify and deliberate same-operation recovery, never an automatic retry or a replacement UUID.

## Reproducible verification

Focused synthetic unit/HTTP/service/CLI tests are in `CompletionStreamingJsonTest` and `HistoricalExamMemoryRecoveryTest`. They cover typed/canonical round trips, buffer boundaries, malformed/truncated JSON, legacy chunk integrity, actual persisted CLI artifacts, authorization, stale revisions, changed intent/history, committed interruption, explicit resume and byte-identical replay without duplicate marks/audit.

The opt-in isolated MariaDB acceptance extends the existing guarded runner:

```powershell
# Optional: provide an exact exported, hash-pinned copy of the public pre-fix CompletionReceipts.php.
# No private source or database dump is used. The runner creates/guards/stops its own server.
& backend/tests/mariadb/approved-exam-reconciliation.ps1 `
  -MariaDbHome '<isolated MariaDB 10.11.18 distribution>' `
  -MemoryAcceptance -BaselineReceiptFile '<private>/baseline-cd8a48b-CompletionReceipts.php'
```

The full fixture targets 16,461 synthetic observations, 1,854 draft writes and 1,409 staging writes, plus retained competing observations and unrelated historical academic evidence. Every phase runs in a fresh process with `memory_limit=2048M`; the apply/resume phase includes actual source reconstruction, review/receipt loading, all writes and final verification. It records PHP peak allocation and OS peak working set/RSS separately. It tests the start-only interruption, original full-decode/chunk reader, interruption after a durable action, same-UUID resume, status, verify and exact replay. Sources/reviews remain unchanged and official results/approval/history contents are fingerprinted before/after. SQLite alone is not evidence of MariaDB concurrency or production-scale memory.

`-RepresentativeMemoryActions` explicitly selects the same 16,461-observation artifact-size run with 32 draft writes and 32 staging writes; the other observations remain retained source history. It is not equivalent to completing all 3,263 incident candidates. The verification summary names the scale and exact expected write counts. Do not describe a representative-write pass, a smoke pass or an interrupted full-candidate run as proof that all incident candidates have been applied successfully.

For a separate, database-free read of an existing private report:

```bash
php -d memory_limit=512M backend/tests/tools/historical-exam-artifact-memory.php /private/existing-report.json

# Original bundle AND original-source fingerprint/interpretation reconstruction, still without a database:
php -d memory_limit=2048M backend/tests/tools/historical-exam-artifact-memory.php \
  --bundle /private/existing-bundle.json <recorded-bundle-file-sha256>
```

This emits only size/count/hash-preservation/runtime/memory metadata, never student rows. It is not an apply, a fresh review or evidence of live readiness. Actual measured acceptance results are recorded in the PR verification report; unexecuted checks must remain explicitly unverified.
