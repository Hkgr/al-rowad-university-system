<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrOffice
{
    public const VIEW = 'administrative_hr.view';

    public const RECRUIT = 'administrative_hr.recruitment.manage';

    public const CLASSIFY = 'administrative_hr.classification.manage';

    public const PREPARE = 'administrative_hr.relationships.prepare';

    public const REVIEW = 'administrative_hr.relationships.review';

    public const PAYROLL_ACCESS = 'administrative_hr.payroll.access';

    public const PAYROLL_LINK = 'administrative_hr.payroll.link';

    public const CORRECT = 'administrative_hr.relationships.correct';

    public const CANCEL = 'administrative_hr.relationships.cancel';

    public const WORKER_EXPORT = 'administrative_hr.workers.export';

    public const PERMISSIONS = [self::VIEW => 'عرض الموارد البشرية', self::RECRUIT => 'إدارة الاحتياجات والمقابلات', self::CLASSIFY => 'استكمال تصنيف العاملين', self::PREPARE => 'إعداد العلاقات الوظيفية', self::REVIEW => 'اعتماد العلاقات الوظيفية', self::PAYROLL_ACCESS => 'دخول المحاسبة إلى الرواتب', self::PAYROLL_LINK => 'ربط ملف الموظف بالرواتب', self::CORRECT => 'تصحيح علاقة وظيفية معتمدة مع حفظ الأصل', self::CANCEL => 'إلغاء نفاذ علاقة وظيفية مع حفظ التاريخ', self::WORKER_EXPORT => 'تصدير ملف العامل ضمن صلاحيات القراءة'];

    public function __construct(private readonly DataScopeService $scope) {}

    public function allows(?User $actor, string $permission): bool
    {
        if ($actor?->isSuperAdmin()) {
            return true;
        }
        if (! $actor || $actor->accountStatus?->status_code !== 'active' || ! $actor->effectivePermissions()->contains($permission)) {
            return false;
        }
        $roles = $actor->effectiveRoles();
        if ($permission === self::PAYROLL_LINK && (! $actor->effectivePermissions()->contains(self::PAYROLL_ACCESS) || ! $actor->effectivePermissions()->contains(OwnerPortal::EMPLOYEES_MANAGE))) {
            return false;
        }
        if (in_array($permission, [self::PAYROLL_ACCESS, self::PAYROLL_LINK], true)) {
            return $roles->intersect(['finance_officer', 'hr_officer', 'vice_president_administrative'])->isNotEmpty() && $this->scope->hasActualUniversityScope($actor);
        }
        if (in_array($permission, [self::REVIEW, self::CORRECT, self::CANCEL], true)) {
            return $roles->contains('vice_president_administrative') && $this->scope->hasActualUniversityScope($actor);
        }

        return ($roles->contains('hr_officer') || $roles->contains('vice_president_administrative')) && ($this->scope->hasActualUniversityScope($actor) || $this->collegeUnitIds($actor) !== []);
    }

    public function collegeUnitIds(User $actor): array
    {
        // Academic child scopes can locate a college, but never authorize its entire workforce.
        $ids = collect($this->scope->scopes($actor))->where('type', 'college')->pluck('id');

        return DB::table('colleges')->whereIn('college_id', $ids)->whereNotNull('organizational_unit_id')->pluck('organizational_unit_id')->map(fn ($id) => (int) $id)->all();
    }

    public function authorize(?User $actor, string $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw AdministrativeGovernanceException::denied('hr_forbidden', 'لا تملك الدور والصلاحية والنطاق المطلوبين.');
        }
    }

    public function scopeUnits(Builder $q, User $actor, string $column): Builder
    {
        if ($actor->isSuperAdmin() || $this->scope->hasActualUniversityScope($actor)) {
            return $q;
        }

        return $q->whereIn($column, $this->collegeUnitIds($actor));
    }

    public function unit(User $actor, int $id): void
    {
        if (! $this->scopeUnits(DB::table('organizational_units'), $actor, 'organizational_unit_id')->where('organizational_unit_id', $id)->exists()) {
            throw AdministrativeGovernanceException::denied('hr_scope_denied', 'العنصر خارج نطاقك أو غير موجود.');
        }
    }

    public function employee(User $actor, object $employee): void
    {
        if ($actor->isSuperAdmin() || $this->scope->hasActualUniversityScope($actor)) {
            return;
        }
        $units = $this->collegeUnitIds($actor);
        $today = now()->toDateString();
        $relationships = DB::table('hr_employment_relationships')->where('employee_id', $employee->employee_id)->whereIn('organizational_unit_id', $units)
            ->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->where(fn ($q) => $q->whereNull('superseded_from')->orWhere('superseded_from', '>', $today));
        if (Schema::hasColumn('hr_employment_relationships', 'cancelled_from')) {
            $relationships->where(fn ($q) => $q->whereNull('cancelled_from')->orWhere('cancelled_from', '>', $today));
        }
        $allowed = in_array((int) $employee->organizational_unit_id, $units, true)
            || DB::table('employee_unit_assignments')->where('employee_id', $employee->employee_id)->whereIn('organizational_unit_id', $units)
                ->where('is_active', true)->where('start_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))->exists()
            || $relationships->exists();
        if (! $allowed) {
            throw AdministrativeGovernanceException::denied('hr_scope_denied', 'الموظف خارج نطاقك.');
        }
    }

    public function payroll(?User $actor, string $permission): void
    {
        if (! in_array($permission, array_keys(OwnerPortal::PERMISSIONS), true) || ! str_starts_with($permission, 'owner_payroll.')) {
            abort(403);
        }
        $this->authorize($actor, self::PAYROLL_ACCESS);
        // Payroll is a university-wide working sheet; college-only HR scope cannot expose it.
        if (! $actor->isSuperAdmin() && (! $this->scope->hasActualUniversityScope($actor) || ! $actor->effectivePermissions()->contains($permission))) {
            abort(403);
        }
    }
}
