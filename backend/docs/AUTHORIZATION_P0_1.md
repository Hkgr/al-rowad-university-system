# P0-1 Authorization and Identity

## Identity

Login rejects every account whose related `account_statuses.status_code` is not `active` before issuing a Sanctum token. Login and `GET /api/user` return the same sanitized identity shape: public user identifiers plus effective role codes and permission codes derived through active user-role, role, role-permission, and permission records. Password hashes, reset secrets, and token records are never serialized.

The active-account middleware covers both the identity/logout group and `/api/v1`. If an account is disabled after login, its presented persistent token is revoked and the request returns `403`.

## Authorization enforcement

- `RequirePermission` provides route-level permission enforcement.
- `StudentPolicy` enforces list, ownership, create, update, archive, restore, and force-delete rules.
- `StudentDocumentPolicy` enforces student ownership for reads/uploads and Student Affairs authority for administrative mutations.
- `StudentCourseResultPolicy` explicitly denies raw result mutations while retaining authorized reads.
- `HandlesApiCrud` invokes registered policies and otherwise maps each model table to the existing module `view` or `manage` permission, preventing inherited CRUD actions from being implicitly public to every authenticated user.
- `AcademicAuthorizationService` retains assigned-section ownership and separates instructor, Student Affairs, and Examination Committee authority. Final examination operations require the existing `exams.manage` permission.
- Login-audit and password-reset-token resources remain read-only and restricted to system administrators.

## React

The frontend stores only the backend identity and bearer token, refreshes identity from `/api/user`, and uses centralized role/permission helpers. Protected routes redirect to the existing-style `403` page, navigation filters inaccessible entries, and sensitive pages such as grade entry, approvals, deprivation, student administration, and HR creation have explicit permission requirements. Frontend checks improve the interaction only; Laravel remains authoritative.

## Database impact

No database structure or production data was modified.
