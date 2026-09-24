<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

/**
 * Administrative Vice-Presidency governance: teacher profiles, college affiliation
 * and college deans. Access = active account + actual university scope + either
 * (the administrative VP role AND the assigned permission) or super_admin.
 * Organizational-chart placement grants nothing by itself.
 */
final class AdministrativeGovernance
{
    public const FACULTY_VIEW = 'vice_presidency.administrative.faculty.view';

    public const FACULTY_MANAGE = 'vice_presidency.administrative.faculty.manage';

    public const DEANS_VIEW = 'vice_presidency.administrative.deans.view';

    public const DEANS_MANAGE = 'vice_presidency.administrative.deans.manage';

    public const MODULE_CODE = 'vice_presidency';

    public const ROLE_DEAN = 'dean';

    public const POSITION_DEAN = 'DEAN';

    /** Roles an existing account may already hold and still be linked as a dean. */
    public const DEAN_COMPATIBLE_ROLES = ['dean', 'doctor_instructor', 'academic_advisor'];

    /** A dean role carrying any of these is refused (fail closed). */
    public const RESTRICTED_PERMISSION_PREFIXES = ['users_permissions.', 'user_accounts.', 'vice_presidency.', 'technical_portal.'];

    public const RESTRICTED_PERMISSIONS = ['system_settings.manage'];

    public function __construct(private readonly DataScopeService $scope) {}

    public function allows(?User $actor, string $permission): bool
    {
        if ($actor === null || $actor->accountStatus?->status_code !== 'active' || ! $this->scope->hasActualUniversityScope($actor)) {
            return false;
        }
        if ($actor->hasRoleCode('super_admin')) {
            return true;
        }

        return $actor->isAdministrativeVicePresident() && $actor->effectivePermissions()->contains($permission);
    }

    public function authorize(?User $actor, string $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw AdministrativeGovernanceException::forbidden();
        }
    }

    /** Home dashboard: administrative VP executive access, or super_admin, both with university scope. */
    public function allowsDashboard(?User $actor): bool
    {
        if ($actor === null || $actor->accountStatus?->status_code !== 'active' || ! $this->scope->hasActualUniversityScope($actor)) {
            return false;
        }

        return $actor->hasRoleCode('super_admin')
            || ($actor->isAdministrativeVicePresident() && $actor->effectivePermissions()->contains(VicePresidency::PERMISSION_ADMINISTRATIVE_ACCESS));
    }

    public static function isRestrictedPermission(string $code): bool
    {
        if (in_array($code, self::RESTRICTED_PERMISSIONS, true)) {
            return true;
        }
        foreach (self::RESTRICTED_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
