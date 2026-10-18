# Academic Calendar Phase 2 RBAC

This manual SQL package adds only the `academic_calendar.manage` permission under the existing `vice_presidency` module and assigns it only to the existing `vice_president_scientific` role. It makes no schema changes and does not modify the Phase 1 calendar package.

In phpMyAdmin, run `00_preflight.sql` and continue only when the visible final row is `OVERALL | READY`. Then run `01_apply.sql` and require `OVERALL | APPLIED`, followed by `02_verify.sql`; accept deployment only when its final row is `OVERALL | PASS`. The preflight and verification reports are ordinary visible `SELECT` result sets with no dynamic reporting.

An absent permission is created with the `[academic-calendar-phase2-rbac]` ownership marker, and only that owned permission may receive a package-created Scientific VP mapping. A compatible externally owned permission is accepted only when its required Scientific VP mapping already exists; if that mapping is absent, preflight returns `BLOCKED`. Apply never creates an unowned mapping. Rollback removes only the mapping and permission carrying this package's marker, while an externally owned compatible permission and mapping are preserved.

Run `03_rollback.sql` only before the package-owned permission is intentionally used. It fails closed when an owned permission cannot be removed safely.

Phase 1 must already be deployed and verified. Application users must refresh or re-authenticate after applying the permission so their identity payload reflects the assignment. Phase 2 does not make calendar dates enforce registration, grading, supplementary examinations, or any other workflow.
