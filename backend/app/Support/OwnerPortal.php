<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * University owner portal (بوابة مالك الجامعة) — Home and Payroll.
 *
 * Access is deliberately NOT inherited from any existing office. An account may use the portal only when:
 *  - it is the central administrative authority (active super_admin; existing, unchanged behaviour), OR
 *  - it is active, holds an active `university_owner` role assignment, and THAT role itself grants both
 *    `owner_portal.access` and the specific permission.
 *
 * The president, vice presidents, HR and technical team have no owner permission by default, and an owner
 * permission mapped to any other role does not open the portal (the role must be `university_owner`).
 */
final class OwnerPortal
{
    public const ROLE = 'university_owner';

    public const MODULE = 'owner_portal';

    public const ACCESS = 'owner_portal.access';

    public const HOME_VIEW = 'owner_portal.home.view';

    public const PAYROLL_VIEW = 'owner_payroll.view';

    public const EMPLOYEES_MANAGE = 'owner_payroll.employees.manage';

    public const BODIES_MANAGE = 'owner_payroll.bodies.manage';

    public const AMOUNTS_EDIT = 'owner_payroll.amounts.edit';

    public const EXPORT = 'owner_payroll.export';

    public const CONFIG_MANAGE = 'owner_payroll.config.manage';

    public const PAYMENTS_MANAGE = 'owner_payroll.payments.manage';

    public const PERIODS_MANAGE = 'owner_payroll.periods.manage';

    public const PERIODS_CORRECT = 'owner_payroll.periods.correct';

    /** @var array<string, string> code => Arabic label */
    public const PERMISSIONS = [
        self::ACCESS => 'الدخول إلى بوابة مالك الجامعة',
        self::HOME_VIEW => 'عرض الرئيسية (ملخص الرواتب)',
        self::PAYROLL_VIEW => 'عرض ورقة الرواتب والهيئات',
        self::EMPLOYEES_MANAGE => 'إضافة موظفي الرواتب وتعديل بياناتهم',
        self::BODIES_MANAGE => 'إدارة هيئات الرواتب',
        self::AMOUNTS_EDIT => 'تعديل مبالغ الرواتب',
        self::CONFIG_MANAGE => 'إدارة أعمدة الرواتب والمعادلات والإعدادات العامة',
        self::EXPORT => 'تصدير ورقة الرواتب (Excel وPDF)',
        self::PAYMENTS_MANAGE => 'تسجيل صرف الرواتب وتوثيق الاستلام وإلغاء السجل الخاطئ',
        self::PERIODS_MANAGE => 'إعداد الشهر واعتماد مستحقاته دون صرف تلقائي',
        self::PERIODS_CORRECT => 'تصحيح مستحقات شهر معتمد مع حفظ الإصدار السابق',
    ];

    public const FORBIDDEN_MESSAGE = 'لا يملك حسابك صلاحية هذا القسم من بوابة مالك الجامعة.';

    public function allows(?User $user, string $permission): bool
    {
        if ($user === null || $user->accountStatus?->status_code !== 'active' || ! array_key_exists($permission, self::PERMISSIONS)) {
            return false;
        }
        // Central administrative authority, as in the other portals.
        if ($user->isSuperAdmin()) {
            return true;
        }
        $granted = $this->roleGrants();
        if ($granted === null || ! $this->holdsRole($user)) {
            return false;
        }

        return $granted->contains(self::ACCESS) && $granted->contains($permission);
    }

    /** Active permission codes granted by the active owner role; null when the role is missing or inactive. */
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
