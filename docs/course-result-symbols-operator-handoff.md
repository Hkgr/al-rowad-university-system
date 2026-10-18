# Course Result Symbols - operator handoff (not executed on production)

Read the [Phase 3 verdict/evidence](course-result-symbols-final-acceptance.md) first: **BLOCKED** pending live browser/responsive/PDF acceptance and release-owner triage of baseline failures. These instructions describe a future authorized release; they are not authorization to deploy now. No production commands, SQL, cron, grants, worker changes or assets were applied during this work.

## Determine the real serving path, once

Repository evidence, not a guessed hosting path:

- `deploy.sh` sets `PROJECT_ROOT` from its own location, `FRONTEND_DIR=$PROJECT_ROOT/frontend`, `BACKEND_DIR=$PROJECT_ROOT/backend`, **`WEB_ROOT=$BACKEND_DIR/public`**.
- `backend/routes/web.php` serves **`public_path('index.html')`** for non-API SPA paths, with no-cache/no-store HTML headers. `backend/public/.htaccess` forwards non-files/non-directories to Laravel `index.php`.
- Vite's current config builds to **`frontend/dist`** with root `/assets/` links. Merely updating `frontend/dist` does not update what this Laravel route serves.
- The Plesk path in the script's comment is an example, not a verified server setting. The operator must supply the actual release root and verify the active vhost/document root points to that release's **`backend/public`**. Check release symlinks if used. Do not publish the same build into several guessed directories.

In a future maintenance window, an operator can set the verified path and inspect it (POSIX shell examples):

```bash
APP_RELEASE='/absolute/release/path/confirmed/by/operator'
cd "$APP_RELEASE"
pwd -P
test -f backend/public/index.php
test -f backend/public/.htaccess
test -f backend/routes/web.php
```

Stop if the vhost points somewhere else; reconcile the serving configuration through the normal hosting process before copying anything. This report did not inspect the live server.

## Choose the correct starting state

| Existing state | Required future release work | Do NOT do |
|---|---|---|
| Phase1 schema/code installed, runtime check READY | Release the complete compatible Phase2 backend reads/routes plus matching rebuilt frontend from one reviewed commit; run read-only checks and isolated acceptance first | No new SQL, migration, grants, inferred I backfill or table recreation |
| Phase2 code/assets already deployed and READY | This Phase3 PR is tests/docs only: no runtime update is needed solely for these files. Complete missing acceptance/operator checks before any release verdict | Do not rebuild the schema or run expiry just to demonstrate acceptance |
| Database READY but application/backend or frontend assets older | Update the compatible backend and frontend as a coordinated artifact set, then verify the single actual public serving directory and read-only routes | Do not treat absent frontend routes or missing export metadata as schema corruption; do not copy assets into multiple roots |
| Check unexpectedly not READY | Stop affected rollout; retain output, inspect runtime metadata visibility and exact installed/code definitions under separate authorization | Never auto-repair tables/triggers, silently overwrite an unknown trigger, delete history, or rerun SQL on assumptions |

The user already reported `Database: 10.11.18-MariaDB`, `SCHEMA | READY`, `CALENDAR | MANUAL_DEADLINE_REQUIRED`. This is valid readiness evidence, **not** full behavioral acceptance. An already READY installation must **not** apply this feature's SQL/migrations again to install Phase2/3. The original guarded Phase1 manual package remains the only installer for a separately confirmed never-installed environment; no installer is delivered by Phase2/3.

## Compatible artifact requirements

Release the reviewed repository commit as a unit, not selected controller or JS files. It must contain the Phase1 lifecycle writer/guards/schema manifest and existing installed-catalog/supplementary/registration lock integrations; Phase2 controller/routes/read projection, workflow/identity/navigation and shared academic record/PDF presentation must match it. Reuse the repository lockfiles/dependency versions. Do not let stale PHP/queue/scheduler workers execute old code against lifecycle-owned outcomes/nullable marks.

For a future authorized code/assets update, take recoverable backups and pause affected academic writes and old workers/scheduler invocations through the normal maintenance process. Wait for running expiry/grade transactions to finish. Keep the original results, announcements, components, assignments, approvals, cases/events and frozen decisions. This task did not stop any live service.

On a controlled build host, using the operator's approved API base and existing lockfile:

```bash
cd "$APP_RELEASE/frontend"
# Only if dependencies need provisioning on that build host, use the unchanged lockfile:
# npm ci --no-audit --no-fund
VITE_API_BASE_URL='https://operator-confirmed-api-host/api' npm run build
test -f dist/index.html
test -f dist/logo.png
```

For isolated acceptance, the base must instead be the **isolated localhost Laravel API**, never the production URL. `apiClient.js` otherwise falls back to production. Inspect the compiled `dist/index.html` and its hashed JS/CSS/preload references. This acceptance build produced 985 modules and three hashed Cairo Arabic/Latin/Latin-ext WOFF2 files; future hashes may differ. Include **all** generated assets and root public files such as the logo, not just the entry JS. Preserve the existing CSP/auth/API contracts.

Publish a coherent `dist` artifact through the normal staged/atomic hosting procedure to the single verified **`$APP_RELEASE/backend/public`** frontend serving area, while preserving Laravel **`index.php`**, **`.htaccess`**, uploads, storage links and unrelated server files. Do not run a destructive whole-public-directory sync. `deploy.sh` shows the intended target but also installs dependencies and clears caches; **do not invoke it blindly** during acceptance or while old workers are running. It was inspected, not executed here.

From `backend`, use the compatible existing PHP runtime/dependencies:

```bash
cd "$APP_RELEASE/backend"
composer validate --no-check-publish
composer check-platform-reqs
# If the approved release changes optimized autoload metadata:
composer dump-autoload --optimize --no-scripts
# Refresh only relevant code/config/route/view caches using the site's established policy:
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Any dependency provisioning belongs to the site's separately reviewed lockfile deployment, not a new dependency upgrade for this feature. Rebuild existing config/route caches only if that is the site's established procedure. Reload/restart only the compatible PHP/long-running worker processes according to hosting policy before resuming writes. None of these publication/restart/cache commands ran on production in this task.

Avoid indiscriminate `cache:clear` / `optimize:clear` while expiry runs: the default cache contains the command's atomic lock and durable progress cursor (and may contain other workflows' locks). Clearing it mid-run is not a code-cache operation. If a controlled cache reset is operationally necessary, stop affected workers first, document it and verify lock/progress behavior after resumption. Losing the cursor may cause earlier blocked cases to be revisited; it is never permission to alter their evidence.

## Read-only readiness and route checks

After a future coordinated code release, before resuming affected traffic:

```bash
cd "$APP_RELEASE/backend"
php artisan course-results:check
php artisan route:list --path=exams/incomplete-results
php artisan schedule:list
```

- Expect actual database version and **SCHEMA READY**; runtime identity needs visibility of required metadata including full protective trigger bodies. A metadata error fails closed. Do not grant privileges automatically in response.
- **CALENDAR MANUAL_DEADLINE_REQUIRED is legitimate** when no unambiguous usable published next-term study period exists. It is not a failed installation. Individual grants require an explicit manual deadline in that case. `PER_CASE_VALIDATION_REQUIRED` means the service must still validate the exact next year/term; it is not proof that every student has a calendar suggestion.
- Confirm the same six API endpoints and correct verbs: GET list, GET registration, POST grant, GET case, **PATCH deadline**, POST resolve. Authenticated authorized and denied reads must be checked in isolated/staging acceptance; never fabricate a live grant to test routes.
- Confirm non-API deep links serve the expected current HTML and all referenced assets/fonts/logo return successfully from the verified public directory. Rendered Cairo/Arabic still needs a real browser, not only computed CSS/document.fonts.ready.

## Durable cache, scheduler and blocked cases

`backend/config/cache.php` defaults to the configured existing store (default `database`); do not assume the operator uses a local file store. `course-results:expire-incomplete --batch=100` requires an existing persistent writable store supporting atomic locks, shared by scheduler hosts where applicable. Array/null/session/Octane/failover stores are rejected. If using database/Redis/file, verify the corresponding configured cache/lock tables or path/connection are already installed, writable and shared appropriately. No cache table, permission or cron is created by this feature.

`backend/routes/console.php` already schedules the command **every minute** with `withoutOverlapping()`. Verify the existing site's scheduler invokes Laravel `schedule:run` each minute from the verified `backend` directory with the intended PHP binary/user/configuration. `schedule:list` is read-only; do not add a second cron entry if one already exists. No production cron was read or changed here.

The command has its own 3,600-second atomic lock and constant-size durable `(deadline_at, case_id)` progress cursor, tied to the DB connection identity. Batch defaults to 100 and is limited to 1..1000; every examined case commits/rolls back independently. A blocked oldest case advances only the operational cursor, not academic state; later valid cases are reached and errors are revisited after wrapping. Do not run it inside an outer DB transaction.

Retain normal scheduler stdout/stderr and the configured Laravel logs. `Incomplete expiration blocked` warnings identify case ID and error code/class; the command returns failure when a case is blocked. Cache/lock/cursor failures also remain visible. Operational response: inspect the specific evidence and authorized workflow, preserve logs/case/result/events and escalate the discrepancy. Never mark a case settled, alter a component, extend/reopen it, or delete an event automatically to make the scheduler green. Test the scheduler/cache identity only with synthetic isolated cases, not real students.

## Recovery constraints

The original `03_rollback.sql` deliberately refuses a destructive downgrade. Do not delete cases/events/old decisions, replace NULL with invented zeros, or roll application code back to an incompatible writer. Restore a tested backup only through a separately authorized recovery plan that includes the entire compatible academic state and code. An unconfirmed mutation is resolved through current authorized reads and deliberate exact replay/review rules, not direct SQL or automatic replacement UUIDs.

After the release gates are actually closed, record commit/artifact hash, read-only check outputs, scheduler/cache verification, representative allowed/denied cases and the inspected synthetic screenshots/PDFs before authorizing release. This document alone does not authorize it.
