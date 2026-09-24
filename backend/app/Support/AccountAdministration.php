<?php

namespace App\Support;

/**
 * Account administration contract for the Technical Office portal.
 *
 * Organizational placement (المكتب التقني under مديرية الشؤون الإدارية) grants
 * nothing by itself: access comes only from active roles and their permissions.
 */
final class AccountAdministration
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_TECHNICAL_TEAM = 'technical_team';

    public const PORTAL_ACCESS = 'technical_portal.access';

    public const VIEW = 'user_accounts.view';

    public const MANAGE = 'user_accounts.manage';

    public const MODULE_CODE = 'users_permissions';

    /**
     * Explicit allowlist: the only roles a non-super_admin account manager may
     * assign or revoke. Every other role, including roles created later, is
     * reserved to super_admin. Never derive this list from permissions.
     */
    public const TECHNICAL_ASSIGNABLE_ROLES = [
        'doctor_instructor',
        'academic_advisor',
        'registration_officer',
        'exam_officer',
        'finance_officer',
        'hr_officer',
        'librarian',
    ];

    /**
     * Fail-closed second check: an allowlisted role that has since been granted
     * one of these permissions is no longer assignable by non-super_admin actors.
     */
    public const RESTRICTED_PERMISSION_PREFIXES = ['users_permissions.', 'user_accounts.'];

    public const RESTRICTED_PERMISSIONS = [self::PORTAL_ACCESS, 'system_settings.manage'];

    /** Account statuses this portal may set. locked/pending stay outside its scope. */
    public const STATUSES = ['active', 'disabled'];

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
