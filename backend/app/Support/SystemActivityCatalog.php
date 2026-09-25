<?php

namespace App\Support;

/**
 * Labels and sanitization rules for the Technical Office activity feed.
 * Only allowlisted keys leave the server; secret-looking keys and values are
 * dropped for every viewer, and contact values are never shown.
 */
final class SystemActivityCatalog
{
    public const LOGIN_MODULE = 'authentication';

    /** What the technical team may read (super_admin reads every module). */
    public const TECHNICAL_MODULES = ['users_permissions', self::LOGIN_MODULE, 'vice_presidency', 'resources'];

    public const MODULES = [
        'users_permissions' => 'الحسابات والصلاحيات',
        self::LOGIN_MODULE => 'تسجيل الدخول',
        'vice_presidency' => 'النيابة الإدارية (المدرسون والعمداء)',
        'resources' => 'تعديل السجلات العامة',
        'grades' => 'الدرجات',
        'admissions' => 'القبول والمفاضلة',
        'courses' => 'المواد',
        'academic_structure' => 'الهيكل الأكاديمي',
    ];

    public const ACTIONS = [
        'account.created' => ['module' => 'users_permissions', 'label' => 'إنشاء حساب'],
        'account.login_identity_updated' => ['module' => 'users_permissions', 'label' => 'تعديل اسم المستخدم أو البريد'],
        'account.password_reset' => ['module' => 'users_permissions', 'label' => 'إعادة تعيين كلمة المرور'],
        'account.holder_name_corrected' => ['module' => 'users_permissions', 'label' => 'تصحيح اسم صاحب الحساب'],
        'account.role_assigned' => ['module' => 'users_permissions', 'label' => 'إسناد دور'],
        'account.role_revoked' => ['module' => 'users_permissions', 'label' => 'سحب دور'],
        'account.status_changed' => ['module' => 'users_permissions', 'label' => 'تغيير حالة الحساب'],
        'user_identity_linked' => ['module' => 'users_permissions', 'label' => 'ربط الحساب بموظف أو طالب'],
        'login.success' => ['module' => self::LOGIN_MODULE, 'label' => 'تسجيل دخول ناجح'],
        'login.failed' => ['module' => self::LOGIN_MODULE, 'label' => 'محاولة دخول فاشلة'],
        'login.inactive' => ['module' => self::LOGIN_MODULE, 'label' => 'محاولة دخول لحساب غير مفعّل'],
        'login.logout' => ['module' => self::LOGIN_MODULE, 'label' => 'تسجيل خروج'],
        'faculty.profile_created' => ['module' => 'vice_presidency', 'label' => 'إنشاء ملف تدريسي'],
        'faculty.profile_updated' => ['module' => 'vice_presidency', 'label' => 'تعديل ملف تدريسي'],
        'faculty.affiliation_assign' => ['module' => 'vice_presidency', 'label' => 'إسناد انتماء مدرس لكلية'],
        'faculty.affiliation_transfer' => ['module' => 'vice_presidency', 'label' => 'نقل انتماء مدرس'],
        'faculty.affiliation_end' => ['module' => 'vice_presidency', 'label' => 'إنهاء انتماء مدرس'],
        'dean.appointed' => ['module' => 'vice_presidency', 'label' => 'تعيين عميد'],
        'dean.transferred' => ['module' => 'vice_presidency', 'label' => 'نقل عميد'],
        'dean.ended' => ['module' => 'vice_presidency', 'label' => 'إنهاء تكليف عميد'],
        'faculty_roster.import_applied' => ['module' => 'vice_presidency', 'label' => 'تطبيق إدخال قائمة المدرسين'],
        'resource.created' => ['module' => 'resources', 'label' => 'إنشاء سجل'],
        'resource.updated' => ['module' => 'resources', 'label' => 'تعديل سجل'],
        'resource.deleted' => ['module' => 'resources', 'label' => 'حذف سجل'],
    ];

    /** Keys that may leave the server as-is (scalars or small lists). */
    private const SAFE_KEYS = [
        'target_user_id', 'user_id', 'username', 'status', 'from', 'to', 'roles', 'role_code', 'reactivated', 'tokens_revoked',
        'error_code', 'http_status', 'fields', 'person_type', 'person_id', 'resource', 'record_id', 'employee_id', 'faculty_member_id',
        'college_id', 'from_college_id', 'to_college_id', 'mode', 'effective_date', 'rows', 'applied', 'accounts_created', 'by_status',
        'reason', 'replaced_user_ids', 'account_mode', 'employee_mode', 'student_id', 'course_offering_id', 'course_id', 'academic_program_id',
    ];

    /** Changed fields whose previous/new values are safe to show. */
    private const VALUE_VISIBLE = [
        'username', 'email', 'first_name', 'last_name', 'father_name', 'mother_name', 'roles', 'scopes', 'employee_id', 'student_id', 'board_member_id',
        'profile.academic_rank', 'profile.specialization', 'profile.office_location', 'profile.is_active',
    ];

    private const FIELD_LABELS = [
        'username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'first_name' => 'الاسم الأول', 'last_name' => 'الكنية',
        'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم', 'roles' => 'الأدوار', 'scopes' => 'النطاقات', 'employee_id' => 'الموظف المرتبط',
        'profile.academic_rank' => 'الرتبة العلمية', 'profile.specialization' => 'الاختصاص', 'profile.office_location' => 'المكتب',
        'profile.is_active' => 'تفعيل الملف', 'contact.phone_number' => 'الهاتف', 'contact.email' => 'بريد الموظف',
    ];

    public const ERROR_LABELS = [
        'self_change_forbidden' => 'محاولة تعديل الحساب الشخصي',
        'protected_account' => 'حساب محمي',
        'role_not_assignable' => 'دور غير مسموح',
        'role_carries_restricted_permission' => 'دور يحمل صلاحية محجوزة',
        'role_inactive' => 'دور غير مفعّل',
        'last_super_admin' => 'آخر مدير نظام',
        'role_already_assigned' => 'الدور مسند مسبقًا',
        'role_not_assigned' => 'الدور غير مسند',
        'status_unchanged' => 'الحالة لم تتغير',
        'account_unchanged' => 'لا تغيير في بيانات الدخول',
        'holder_name_unchanged' => 'لا تغيير في الاسم',
        'holder_not_linked' => 'حساب غير مرتبط بشخص',
        'holder_link_ambiguous' => 'ربط مزدوج',
        'holder_name_permission_missing' => 'صلاحية تصحيح الاسم غير متوفرة',
        'employee_has_account' => 'للموظف حساب قائم',
        'validation_failed' => 'بيانات غير صالحة أو مكررة',
    ];

    public static function moduleLabel(?string $code): string
    {
        return self::MODULES[$code] ?? ($code ?: 'غير محدد');
    }

    public static function actionLabel(string $code): string
    {
        return self::ACTIONS[$code]['label'] ?? $code;
    }

    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match('/pass|token(?!s_revoked)|secret|hash|remember|otp|api_key|authorization/i', $key);
    }

    private static function redactValue(mixed $value): mixed
    {
        if (is_string($value) && (preg_match('/^\$2[aby]\$/', $value) || preg_match('/^[a-f0-9]{40,}$/i', $value) || preg_match('/^\d+\|[A-Za-z0-9]{20,}$/', $value))) {
            return '[محجوب]';
        }

        return $value;
    }

    /** Scalars, or lists/maps of scalars (depth ≤ 2), with secrets removed. */
    private static function safeValue(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return self::redactValue($value);
        }
        if (! is_array($value) || $depth >= 2) {
            return null;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSecretKey($key)) {
                continue;
            }
            $clean[$key] = self::safeValue($item, $depth + 1);
        }

        return $clean;
    }

    /** @return array{fields: array<string, mixed>, changes: list<array<string, mixed>>, outcome: string, notes: list<string>} */
    public static function sanitize(string $action, array $data): array
    {
        $fields = [];
        foreach (self::SAFE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = self::safeValue($data[$key]);
            }
        }
        $changes = [];
        if (isset($data['changes']) && is_array($data['changes'])) {
            foreach ($data['changes'] as $field => $change) {
                if (! is_string($field) || self::isSecretKey($field)) {
                    continue;
                }
                $changes[] = self::change($field, is_array($change) ? ($change['from'] ?? null) : null, is_array($change) ? ($change['to'] ?? null) : null);
            }
        }
        if (isset($data['before'], $data['after']) && is_array($data['before']) && is_array($data['after'])) {
            $before = self::flatten($data['before']);
            $after = self::flatten($data['after']);
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
                if (self::isSecretKey($field) || ($before[$field] ?? null) === ($after[$field] ?? null)) {
                    continue;
                }
                $changes[] = self::change($field, $before[$field] ?? null, $after[$field] ?? null);
            }
        }
        $notes = [];
        if ($action === 'account.password_reset') {
            $notes[] = 'عُيّنت كلمة مرور جديدة؛ لا تُحفظ قيمتها ولا تُعرض، وكلمة المرور السابقة غير قابلة للاسترجاع.';
        }

        return [
            'fields' => $fields,
            'changes' => $changes,
            'outcome' => ($data['outcome'] ?? 'success') === 'failed' ? 'failed' : 'success',
            'notes' => $notes,
        ];
    }

    /** @param array<string, mixed> $fields */
    public static function summary(string $action, array $fields, string $outcome): string
    {
        $label = self::actionLabel($action);
        if ($outcome === 'failed' && ! str_starts_with($action, 'login.')) {
            $reason = self::ERROR_LABELS[$fields['error_code'] ?? ''] ?? ($fields['error_code'] ?? 'رفض');

            return "محاولة مرفوضة: {$label} ({$reason})";
        }

        return match ($action) {
            'account.created' => 'إنشاء الحساب '.($fields['username'] ?? '').(empty($fields['roles']) ? '' : ' بالأدوار: '.implode('، ', (array) $fields['roles'])),
            'account.password_reset' => 'إعادة تعيين كلمة المرور وإنهاء '.((int) ($fields['tokens_revoked'] ?? 0)).' جلسة',
            'account.role_assigned' => 'إسناد الدور '.($fields['role_code'] ?? ''),
            'account.role_revoked' => 'سحب الدور '.($fields['role_code'] ?? ''),
            'account.status_changed' => 'تغيير الحالة من '.($fields['from'] ?? '—').' إلى '.($fields['to'] ?? '—'),
            'resource.created', 'resource.updated', 'resource.deleted' => $label.' في '.($fields['resource'] ?? '—').' #'.($fields['record_id'] ?? '—'),
            'login.failed', 'login.inactive' => $label.(empty($fields['identifier']) ? '' : ' بالمعرّف '.$fields['identifier']),
            default => $label,
        };
    }

    /** Masks a login identifier: keeps the first two characters and the domain. */
    public static function maskIdentifier(?string $identifier): ?string
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }
        [$local, $domain] = array_pad(explode('@', $identifier, 2), 2, null);

        return mb_substr($local, 0, 2).str_repeat('•', max(1, min(6, mb_strlen($local) - 2))).($domain !== null ? '@'.$domain : '');
    }

    private static function change(string $field, mixed $from, mixed $to): array
    {
        $visible = in_array($field, self::VALUE_VISIBLE, true);

        return [
            'field' => $field,
            'label' => self::FIELD_LABELS[$field] ?? $field,
            'from' => $visible ? self::display(self::safeValue($from)) : null,
            'to' => $visible ? self::display(self::safeValue($to)) : null,
            'values_hidden' => ! $visible,
        ];
    }

    private static function display(mixed $value): mixed
    {
        if (is_array($value)) {
            return implode('، ', array_map(fn ($item) => is_array($item) ? implode(':', array_map(fn ($v) => is_bool($v) ? ($v ? 'نشط' : 'غير نشط') : (string) $v, $item)) : (string) $item, $value));
        }

        return is_bool($value) ? ($value ? 'نعم' : 'لا') : $value;
    }

    /** @return array<string, mixed> one level of nesting → "parent.child" */
    private static function flatten(array $data): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                foreach ($value as $child => $item) {
                    $flat[$key.'.'.$child] = $item;
                }
            } else {
                $flat[(string) $key] = $value;
            }
        }

        return $flat;
    }
}
