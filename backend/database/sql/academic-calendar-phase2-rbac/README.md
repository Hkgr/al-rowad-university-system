# Academic Calendar Phase 2 RBAC

This manual SQL package adds only the `academic_calendar.manage` permission under the existing `vice_presidency` module and assigns it only to the existing `vice_president_scientific` role. It makes no schema changes and does not modify the Phase 1 calendar package.

In phpMyAdmin, run `00_preflight.sql` and continue only when the visible final row is `OVERALL | READY`. Then run `01_apply.sql`, followed by `02_verify.sql`; accept deployment only when its final row is `OVERALL | PASS`. Run `03_rollback.sql` only before the permission is intentionally used. Rollback removes rows carrying this package's ownership marker and otherwise fails closed.

Phase 1 must already be deployed and verified. Application users must refresh or re-authenticate after applying the permission so their identity payload reflects the assignment. Phase 2 does not make calendar dates enforce registration, grading, supplementary examinations, or any other workflow.
