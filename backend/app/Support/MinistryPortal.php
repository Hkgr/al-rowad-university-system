<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ministry of Education follow-up portal (بوابة وزارة التربية والتعليم).
 *
 * Read-only, university-wide view of allowlisted data. Access requires ALL of:
 *  - an active account,
 *  - an active user_roles row for the active role `ministry_observer`,
 *  - the permission granted by THAT role (no super_admin bypass, no permission
 *    inherited from another role),
 *  - the role itself carrying only `ministry_portal.*` permissions: if anyone ever
 *    maps a write or other-module permission to it, the portal fails closed.
 *
 * The data scope is fixed to the whole university by design; it does not use
 * user_access_scopes and grants nothing outside the /v1/ministry endpoints.
 */
final class MinistryPortal
{
    public const ROLE = 'ministry_observer';

    public const MODULE = 'ministry_portal';

    public const PERMISSION_PREFIX = 'ministry_portal.';

    public const ACCESS = 'ministry_portal.access';

    public const DASHBOARD = 'ministry_portal.dashboard.view';

    public const DEANS = 'ministry_portal.deans.view';

    public const STUDENTS = 'ministry_portal.students.view';

    public const COLLEGES = 'ministry_portal.colleges.view';

    public const COURSES = 'ministry_portal.courses.view';

    public const FACULTY = 'ministry_portal.faculty.view';

    public const LEADERSHIP = 'ministry_portal.leadership.view';

    public const PERMISSIONS = [self::ACCESS, self::DASHBOARD, self::DEANS, self::STUDENTS, self::COLLEGES, self::COURSES, self::FACULTY, self::LEADERSHIP];

    public const FORBIDDEN_MESSAGE = 'لا يملك حسابك صلاحية الاطلاع على هذا القسم من بوابة وزارة التربية والتعليم.';

    public function allows(?User $user, string $permission): bool
    {
        if ($user === null || $user->accountStatus?->status_code !== 'active' || ! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }
        $granted = $this->roleGrants();
        if ($granted === null || ! $this->holdsRole($user)) {
            return false;
        }
        if ($granted->contains(fn (string $code) => ! str_starts_with($code, self::PERMISSION_PREFIX))) {
            return false;
        }

        return $granted->contains(self::ACCESS) && $granted->contains($permission);
    }

    /** Permission codes (active) that the active ministry role grants; null when the role is missing or inactive. */
    public function roleGrants(): ?Collection
    {
        $role = DB::table('roles')->where('role_code', self::ROLE)->where('is_active', 1)->first(['role_id']);
        if ($role === null) {
            return null;
        }

        return DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role->role_id)
            ->where('p.is_active', 1)
            ->pluck('p.permission_code')
            ->map(fn ($code) => (string) $code)
            ->values();
    }

    private function holdsRole(User $user): bool
    {
        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->user_id)
            ->where('ur.is_active', 1)
            ->where('r.role_code', self::ROLE)
            ->where('r.is_active', 1)
            ->exists();
    }
}
