<?php

namespace App\Support;

use App\Models\User;

final class UniversityEmailAccess
{
    public const VIEW = 'university_email.view';
    public const MANAGE = 'university_email.manage';
    public const CHECK = 'university_email.check_connection';
    public const PERMISSIONS = [self::VIEW => 'عرض البريد الجامعي', self::MANAGE => 'تجهيز مسودات البريد الجامعي', self::CHECK => 'فحص اتصال البريد الجامعي'];

    public static function authorize(?User $user, string $permission): void
    {
        abort_unless($user && $user->accountStatus?->status_code === 'active'
            && $user->effectiveRoles()->contains(AccountAdministration::ROLE_TECHNICAL_TEAM), 403);
        $assigned = $user->effectivePermissions();
        abort_unless($assigned->contains(AccountAdministration::PORTAL_ACCESS) && $assigned->contains($permission), 403);
    }
}
