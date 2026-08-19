# Production academic-core deployment checklist

This is a **manual operator gate**. Passing Phase 11 PHPUnit tests does **not**
make the system production-ready.

- **CODE HARDENING READY** after the Phase 11 pull request is reviewed and deployed
- **DATABASE DEPLOYMENT VERIFICATION PENDING** until every verifier below is
  run by a DBA and returns `OVERALL = PASS`

Do **not** claim "production ready" until those verifier outputs are supplied.

## 1. Database verification gate (mandatory, manual)

Do **not** run these SQL files from the application, seeders, or
`php artisan migrate`.

Exact order:

1. Confirm a **database backup** exists immediately before any schema apply.
2. If Phase 8 was never applied, run that phase's own:
   `backend/database/sql/teaching-assignment-lifecycle/00_preflight.sql`
   then `01_apply.sql` then `02_verify.sql`.
3. If Phase 9 was never applied, run:
   `backend/database/sql/student-registration-lifecycle/00_preflight.sql`
   then `01_apply.sql` then `02_verify.sql`.
4. If Phase 10 was never applied, run:
   `backend/database/sql/student-academic-progression/00_preflight.sql`
   then `01_apply.sql` then `02_verify.sql`.
5. Phase 11 is **read-only**. Never use it as a substitute for missing
   earlier schema. There is no `01_apply.sql`.

Required verifier outputs (all must be `OVERALL = PASS`):

| Phase | File | Expected |
|---|---|---|
| 8 | `backend/database/sql/teaching-assignment-lifecycle/02_verify.sql` | `OVERALL = PASS` |
| 9 | `backend/database/sql/student-registration-lifecycle/02_verify.sql` | `OVERALL = PASS` |
| 10 | `backend/database/sql/student-academic-progression/02_verify.sql` | `OVERALL = PASS` |
| 11 | `backend/database/sql/system-hardening-audit/00_audit.sql` | `OVERALL = PASS` |

Record the date, operator, and result of each verifier before opening
registration, grading, progression, or graduation to production traffic.

## 2. Application configuration

Required production values (environment, not hardcoded):

- `APP_ENV=production`
- `APP_DEBUG=false`
- A valid `APP_KEY`
- Database credentials only in the server environment, never in git
- CORS origins remain environment/deployment configuration
  (`backend/config/cors.php` currently lists explicit origins and
  `supports_credentials` is `false`)

Do **not** hardcode `APP_DEBUG=true`.

## 3. Deploy process

- Composer production dependencies installed (`composer install --no-dev` per
  the current Plesk/deploy process)
- Laravel caches rebuilt according to the current deploy process
  (`config:cache` / `route:cache` / `view:cache` as already used)
- Frontend bundle rebuilt
- Web root remains `backend/public`
- `storage/` and `storage/logs` permissions valid for the PHP worker
- **No Laravel migration command is required or permitted** for academic-core
  schema
- Do not modify the existing Plesk deployment architecture unless a verified
  problem is found

## 4. Maintenance window

Use a maintenance/deployment window when applying any remaining Phase 8–10
SQL. Academic mutations (opening, closure, teaching assignment, registration,
grades, progression, graduation) must not run against a half-applied schema.

## 5. Authentication / token notes

- `POST /api/login` is throttled: 5 attempts per minute per normalized
  email + client IP (`throttle:login`)
- Inactive accounts cannot obtain a token; `EnsureActiveAccount` revokes the
  current token if an already-issued token is used after deactivation
- Logout revokes the **current** token only

## 6. Out of scope for this gate

Financial clearance, library clearance, graduation certificates, Ministry
integration, 2FA/SSO, Redis/Kubernetes, and new university workflows are
future projects and are not required for this academic-core gate.
