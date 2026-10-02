<?php

namespace App\Support;

use App\Models\User;

final class UniversityEmailAccess
{
    public const VIEW = 'university_email.view';
    public const MANAGE = 'university_email.manage';
    public const CHECK = 'university_email.check_connection';
    public const CREATE = 'university_email.provision';
    public const RECEIPT = 'university_email.issue_receipt';
    public const RECOVER = 'university_email.reissue_initial_password';
    public const RESET = 'university_email.reset_password';
    public const SUSPEND = 'university_email.suspend';
    public const ACTIVATE = 'university_email.activate';
    public const LINK = 'university_email.link_existing';
    public const DELETE = 'university_email.delete';
    public const PHASE3_PERMISSIONS = [self::RESET => 'إعادة تعيين كلمة مرور بريد مرتبط', self::SUSPEND => 'إيقاف بريد جامعي مرتبط',
        self::ACTIVATE => 'تفعيل بريد جامعي مرتبط', self::LINK => 'التحقق وربط بريد جامعي سابق', self::DELETE => 'حذف صندوق البريد الجامعي مع حفظ التاريخ'];

    public static function operationPermission(string $kind): string
    {
        return match ($kind) {
            'create' => self::CREATE, 'reset' => self::RECOVER, 'password_reset' => self::RESET,
            'suspend' => self::SUSPEND, 'activate' => self::ACTIVATE, 'link' => self::LINK, 'delete' => self::DELETE,
            default => abort(403),
        };
    }
    public const PERMISSIONS = [self::VIEW => 'عرض البريد الجامعي', self::MANAGE => 'تجهيز مسودات البريد الجامعي', self::CHECK => 'فحص اتصال البريد الجامعي'];
    public const PHASE2_PERMISSIONS = [self::CREATE => 'إنشاء صندوق البريد الجامعي', self::RECEIPT => 'إصدار إيصال بيانات دخول البريد الجامعي',
        self::RECOVER => 'إعادة إصدار كلمة أولية لبريد جامعي غير مسلّم'];

    public static function authorize(?User $user, string $permission): void
    {
        abort_unless(self::allows($user, $permission), 403);
    }

    public static function allows(?User $user, string $permission): bool
    {
        if ($user?->isSuperAdmin()) return true;
        if (! $user || $user->accountStatus?->status_code !== 'active'
            || ! $user->effectiveRoles()->contains(AccountAdministration::ROLE_TECHNICAL_TEAM)) return false;
        $assigned = $user->effectivePermissions();
        return $assigned->contains(AccountAdministration::PORTAL_ACCESS) && $assigned->contains($permission);
    }
}
