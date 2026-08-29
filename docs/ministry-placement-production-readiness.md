# Ministry Placement production readiness

The Ministry Placement workflow has five deliberately separate stages:

1. Import the Ministry workbook into `ministry_placement_batches` and `ministry_placement_records`.
2. Match each staging record to an academic program through explicit operator decisions.
3. Convert a coherent matched record into one Applicant and one exact-context Admission Application.
4. Decide the application and, after acceptance, create the linked Student record without creating a user account or course registration.
5. Reconcile the complete persisted chain through read-only reports before production acceptance.

## Deployment and verification order

The operator runs the Ministry SQL reports manually through phpMyAdmin, in filename order:

1. `00_preflight.sql`
2. `10_phase2_preflight.sql`
3. `20_phase3_preflight.sql`
4. `30_phase4_preflight.sql`
5. `40_phase5_reconciliation.sql`

Continue only when each report finishes with a visible `OVERALL | READY`. The Phase 5 SQL result is the relational/structural gate. It is not the final identity gate.

Afterward, an authorized operator opens the Ministry portal's **التدقيق النهائي** view and requires the application response `production_gate=READY`. This PHP gate is authoritative for identity normalization because it uses `MinistryPlacementNormalizer::duplicateKey()` across all batches.

## Warning and blocker review

- Warnings preserve the derived pipeline state and permit `READY`. Examples include a coherent historical terminal chain whose hierarchy or Student reference definition later became inactive.
- Any blocker changes the record state to `inconsistent` and changes its affected batch and global production gate to `BLOCKED`.
- One coherent terminal record sharing an identity with nonterminal records receives a terminal warning; the nonterminal records are blockers.
- Two or more coherent terminal records sharing one canonical identity are all blocked with `identity_conflict_multiple_terminal_records`. Enrolled/enrolled, enrolled/rejected, and rejected/rejected groups therefore require manual investigation and can never pass the production gate.

Checksums contain only internal identifiers, derived state/severity, and issue codes. They do not contain names, Ministry identity values, applicant/student numbers, contact details, or birth dates. Audit coverage reports action counts only and is informational.

An Applicant whose deterministic `MP-R{placement_record_id}` number exists while `ministry_placement_records.applicant_id` is null is reported as an orphan, together with its orphan Application and Student IDs when present. The deterministic number is never adopted as the durable Ministry link. Checksum material includes sorted safe relationship IDs from these and other issues so a changed relationship produces a changed checkpoint without including PII.

## Intentional boundaries

Phase 5 performs no repair, merge, relink, override, deletion, account provisioning, academic-term creation, course offering creation, or course registration. It adds no mutation route and makes no schema change.

The database currently cannot enforce canonical Ministry identity uniqueness under the Unicode and Arabic/Eastern-digit normalization rules. Exact SQL equality is therefore reported only as `INFORMATIONAL_NON_AUTHORITATIVE`. The PHP application gate remains the canonical identity authority. If staging identity/profile fields ever become mutable through a future Ministry API, the prior bulk snapshot assumptions and reconciliation checksums must be reviewed before deployment.
