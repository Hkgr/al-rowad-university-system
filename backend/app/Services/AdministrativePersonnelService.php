<?php

namespace App\Services;

use App\Models\AccountStatus;
use App\Models\College;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\EmployeeUnitAssignment;
use App\Models\FacultyMember;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Models\UserActivityLog;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Deliberately separate from generic HR and account CRUD: narrow VP delegation. */
class AdministrativePersonnelService
{
    public const STAFF_VIEW = 'administrative_staff.view';
    public const STAFF_MANAGE = 'administrative_staff.manage';
    public const DEANS_VIEW = 'administrative_deans.view';
    public const DEANS_MANAGE = 'administrative_deans.manage';

    public function __construct(private DataScopeService $scopes) {}

    public function authorize(User $actor, string $permission): void
    {
        if ($actor->accountStatus?->status_code !== 'active'
            || ! $actor->isAdministrativeVicePresident()
            || ! $this->scopes->hasActualUniversityScope($actor)
            || ! $actor->effectivePermissions()->contains($permission)) {
            throw new AccessDeniedHttpException('لا تملك الصلاحية والنطاق الجامعي المطلوبين لهذه العملية.');
        }
    }

    public function colleges(): array
    {
        return College::query()->where('is_active', true)->whereNotNull('organizational_unit_id')
            ->orderBy('college_name')->get(['college_id', 'college_name', 'organizational_unit_id'])->toArray();
    }

    private function college(int $id, bool $lock = false): College
    {
        $query = College::query()->whereKey($id)->where('is_active', true)->whereNotNull('organizational_unit_id');
        $college = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($college === null) {
            throw ValidationException::withMessages(['college_id' => ['اختر كلية نشطة مرتبطة بوحدة تنظيمية.']]);
        }
        return $college;
    }

    private function activeStatus(): EmployeeStatus
    {
        return EmployeeStatus::query()->where('status_code', 'active')->where('is_active', true)->firstOrFail();
    }

    private function employeeType(string $code): EmployeeType
    {
        return EmployeeType::query()->where('type_code', $code)->where('is_active', true)->firstOrFail();
    }

    private function staffQuery(array $filters)
    {
        $query = FacultyMember::query()->with(['employee.employeeStatus', 'employee.employeeUnitAssignments' => fn ($q) => $q->where('is_active', true)]);
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('specialization', 'like', $like)
                    ->orWhereHas('employee', fn ($e) => $e->where(fn ($names) => $names
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)->orWhere('employee_number', 'like', $like)
                        ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", [$like])));
            });
        }
        if (! empty($filters['college_id'])) {
            $unitId = $this->college((int) $filters['college_id'])->organizational_unit_id;
            $query->whereHas('employee', fn ($e) => $e->where(fn ($membership) => $membership
                ->where('organizational_unit_id', $unitId)
                ->orWhereHas('employeeUnitAssignments', fn ($a) => $a->where('organizational_unit_id', $unitId)->where('is_active', true))));
        }
        return $query;
    }

    public function faculty(array $filters): array
    {
        $rows = $this->staffQuery($filters)->orderBy('faculty_member_id')
            ->paginate(min(50, (int) ($filters['per_page'] ?? 20)));
        return ['data' => collect($rows->items())->map(fn ($row) => $this->facultySummary($row))->all(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }

    public function availableEmployees(array $filters): array
    {
        $academicType = $this->employeeType('academic')->employee_type_id;
        $query = Employee::query()->where('employee_type_id', $academicType)->whereDoesntHave('facultyMembers')
            ->whereHas('employeeStatus', fn ($s) => $s->where('status_code', 'active')->where('is_active', true));
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->where('employee_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        return $query->orderBy('employee_id')->limit(50)
            ->get(['employee_id', 'employee_number', 'first_name', 'last_name'])->toArray();
    }

    public function deanCandidates(array $filters): array
    {
        $query = Employee::query()->with(['users.userRoleRecords.role', 'users.accessScopes'])
            ->whereHas('employeeStatus', fn ($s) => $s->where('status_code', 'active')->where('is_active', true));
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->where('employee_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        return $query->orderBy('employee_id')->limit(50)->get()
            ->filter(fn ($e) => $e->users->count() < 2 && $e->users->every(fn ($u) =>
                $u->userRoleRecords->every(fn ($r) => $r->role?->role_code === 'dean')
                && $u->accessScopes->every(fn ($s) => ! $s->is_active)))
            ->map(fn ($e) => ['employee_id' => $e->employee_id,
                'employee_number' => $e->employee_number,
                'full_name' => trim($e->first_name.' '.$e->last_name),
                'user_id' => $e->users->first()?->user_id,
                'username' => $e->users->first()?->username])->values()->all();
    }

    public function facultySummary(FacultyMember $faculty): array
    {
        $faculty->loadMissing(['employee', 'employee.employeeUnitAssignments' => fn ($q) => $q->where('is_active', true)]);
        $employee = $faculty->employee;
        $units = collect([$employee?->organizational_unit_id])
            ->merge($employee?->employeeUnitAssignments?->pluck('organizational_unit_id') ?? collect())
            ->filter()->unique()->all();
        return [
            'faculty_member_id' => $faculty->faculty_member_id,
            'employee_id' => $employee?->employee_id,
            'employee_number' => $employee?->employee_number,
            'first_name' => $employee?->first_name,
            'last_name' => $employee?->last_name,
            'full_name' => trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
            'email' => $employee?->email,
            'academic_rank' => $faculty->academic_rank,
            'specialization' => $faculty->specialization,
            'office_location' => $faculty->office_location,
            'is_active' => (bool) $faculty->is_active,
            'college_ids' => College::query()->whereIn('organizational_unit_id', $units)->pluck('college_id')->all(),
        ];
    }

    public function saveFaculty(User $actor, array $data, ?FacultyMember $faculty, ?string $ip): array
    {
        $this->authorize($actor, self::STAFF_MANAGE);
        return DB::transaction(function () use ($actor, $data, $faculty, $ip): array {
            $college = $this->college((int) $data['college_id'], true);
            if ($faculty !== null) {
                $faculty = FacultyMember::query()->lockForUpdate()->findOrFail($faculty->faculty_member_id);
                $employee = Employee::query()->lockForUpdate()->findOrFail($faculty->employee_id);
            } elseif (! empty($data['employee_id'])) {
                $employee = Employee::query()->lockForUpdate()->findOrFail((int) $data['employee_id']);
                if (FacultyMember::query()->where('employee_id', $employee->employee_id)->exists()) {
                    throw ValidationException::withMessages(['employee_id' => ['الموظف لديه ملف تدريسي. افتح الملف الموجود لتعديله.']]);
                }
            } else {
                $employee = Employee::query()->create([
                    'employee_number' => $data['employee_number'],
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? null,
                    'employee_type_id' => $this->employeeType('academic')->employee_type_id,
                    'employee_status_id' => $this->activeStatus()->employee_status_id,
                    'organizational_unit_id' => $college->organizational_unit_id,
                ]);
            }
            if ($employee->employeeStatus?->status_code !== 'active'
                && (int) $employee->employee_status_id !== (int) $this->activeStatus()->employee_status_id) {
                throw ValidationException::withMessages(['employee_id' => ['الموظف غير نشط.']]);
            }
            if ((int) $employee->employee_type_id !== (int) $this->employeeType('academic')->employee_type_id) {
                throw ValidationException::withMessages(['employee_id' => ['الملف التدريسي يتطلب موظفًا من النوع الأكاديمي.']]);
            }
            if ($faculty !== null) {
                $employee->fill(collect($data)->only(['first_name', 'last_name', 'email'])->all())->save();
            }
            $oldCollegeUnits = College::query()->whereNotNull('organizational_unit_id')->pluck('organizational_unit_id')->all();
            EmployeeUnitAssignment::query()->where('employee_id', $employee->employee_id)
                ->where('is_active', true)->whereIn('organizational_unit_id', $oldCollegeUnits)
                ->where('organizational_unit_id', '!=', $college->organizational_unit_id)
                ->update(['is_active' => false, 'end_date' => now()->toDateString()]);
            if (in_array($employee->organizational_unit_id, $oldCollegeUnits, false)
                && (int) $employee->organizational_unit_id !== (int) $college->organizational_unit_id) {
                $employee->forceFill(['organizational_unit_id' => $college->organizational_unit_id])->save();
            }
            if ((int) $employee->organizational_unit_id !== (int) $college->organizational_unit_id
                && ! EmployeeUnitAssignment::query()->where('employee_id', $employee->employee_id)
                    ->where('organizational_unit_id', $college->organizational_unit_id)->where('is_active', true)->exists()) {
                EmployeeUnitAssignment::query()->create(['employee_id' => $employee->employee_id,
                    'organizational_unit_id' => $college->organizational_unit_id, 'start_date' => now()->toDateString(),
                    'is_active' => true]);
            }
            if ($faculty === null) {
                $faculty = FacultyMember::query()->create(['employee_id' => $employee->employee_id, 'is_active' => true,
                    'academic_rank' => $data['academic_rank'] ?? null, 'specialization' => $data['specialization'] ?? null,
                    'office_location' => $data['office_location'] ?? null]);
            } else {
                $faculty->fill(collect($data)->only(['academic_rank', 'specialization', 'office_location'])->all())->save();
            }
            $this->audit($actor, 'administrative.faculty_saved', ['faculty_member_id' => $faculty->faculty_member_id,
                'employee_id' => $employee->employee_id, 'college_id' => $college->college_id], $ip);
            return $this->facultySummary($faculty->fresh());
        });
    }

    public function deans(): array
    {
        $roleId = Role::query()->where('role_code', 'dean')->where('is_active', true)->value('role_id');
        $accounts = UserAccessScope::query()->where('scope_type', 'college')->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->whereHas('userRoleRecords', fn ($r) => $r
                ->where('role_id', $roleId)->where('is_active', true)))
            ->with('user.employee')->get()->groupBy('scope_id');
        return College::query()->where('is_active', true)->orderBy('college_name')
            ->get(['college_id', 'college_name'])->map(fn ($c) => [
                'college_id' => $c->college_id, 'college_name' => $c->college_name,
                'deans' => ($accounts->get($c->college_id) ?? collect())->map(fn ($s) => [
                    'user_id' => $s->user_id, 'username' => $s->user?->username,
                    'employee_id' => $s->user?->employee_id,
                    'full_name' => trim(($s->user?->employee?->first_name ?? '').' '.($s->user?->employee?->last_name ?? '')),
                ])->values()->all(),
            ])->all();
    }

    public function appointDean(User $actor, array $data, ?string $ip): array
    {
        $this->authorize($actor, self::DEANS_MANAGE);
        return DB::transaction(function () use ($actor, $data, $ip): array {
            $college = $this->college((int) $data['college_id'], true);
            $role = Role::query()->where('role_code', 'dean')->where('is_active', true)->firstOrFail();
            $existing = UserAccessScope::query()->where('scope_type', 'college')->where('scope_id', $college->college_id)
                ->where('is_active', true)->whereHas('user', fn ($q) => $q->whereHas('userRoleRecords', fn ($r) => $r
                    ->where('role_id', $role->role_id)->where('is_active', true)))->first();
            if ($existing && (empty($data['user_id']) || (int) $existing->user_id !== (int) $data['user_id'])) {
                throw ValidationException::withMessages(['college_id' => ['للكلية عميد فعّال. أنهِ تكليفه قبل تعيين بديل.']]);
            }
            $employee = ! empty($data['employee_id'])
                ? Employee::query()->lockForUpdate()->findOrFail((int) $data['employee_id'])
                : Employee::query()->create(['employee_number' => $data['employee_number'],
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'email' => $data['email'], 'employee_type_id' => $this->employeeType('administrative')->employee_type_id,
                    'employee_status_id' => $this->activeStatus()->employee_status_id,
                    'organizational_unit_id' => $college->organizational_unit_id]);
            if ((int) $employee->employee_status_id !== (int) $this->activeStatus()->employee_status_id) {
                throw ValidationException::withMessages(['employee_id' => ['اختر موظفًا نشطًا.']]);
            }
            if (! empty($data['user_id'])) {
                $account = User::query()->lockForUpdate()->findOrFail((int) $data['user_id']);
                if ((int) $account->employee_id !== (int) $employee->employee_id || (int) $account->user_id === (int) $actor->user_id
                    || $account->userRoleRecords()->whereHas('role', fn ($q) => $q->where('role_code', '!=', 'dean'))->exists()
                    || $account->accountStatus?->status_code !== 'active'
                    || $account->accessScopes()->where('is_active', true)->where(fn ($q) => $q
                        ->where('scope_type', '!=', 'college')->orWhere('scope_id', '!=', $college->college_id))->exists()) {
                    throw ValidationException::withMessages(['user_id' => ['الحساب مرتبط بهوية أو دور أو نطاق آخر، ولا يمكن تغييره عبر هذا المسار.']]);
                }
            } else {
                if (User::query()->where('employee_id', $employee->employee_id)->exists()) {
                    throw ValidationException::withMessages(['employee_id' => ['للموظف حساب قائم؛ اختره للربط بدل إنشاء حساب ثانٍ.']]);
                }
                $account = new User();
                $account->forceFill(['username' => $data['username'], 'email' => $data['email'],
                    'password_hash' => Hash::make($data['password']), 'employee_id' => $employee->employee_id,
                    'account_status_id' => AccountStatus::query()->where('status_code', 'active')->where('is_active', true)->firstOrFail()->account_status_id,
                    'created_by_user_id' => $actor->user_id, 'failed_login_attempts' => 0])->save();
            }
            $accountRole = UserRole::query()->where('user_id', $account->user_id)
                ->where('role_id', $role->role_id)->lockForUpdate()->first();
            if ($accountRole === null) {
                UserRole::query()->create(['user_id' => $account->user_id, 'role_id' => $role->role_id,
                    'assigned_at' => now(), 'assigned_by_user_id' => $actor->user_id, 'is_active' => true]);
            } elseif (! $accountRole->is_active) {
                $accountRole->forceFill(['is_active' => true, 'assigned_at' => now(), 'assigned_by_user_id' => $actor->user_id])->save();
            }
            $scope = UserAccessScope::query()->where('user_id', $account->user_id)->where('scope_type', 'college')
                ->where('scope_id', $college->college_id)->lockForUpdate()->first();
            if ($scope === null) {
                $scope = new UserAccessScope();
                $scope->user_id = $account->user_id;
                $scope->scope_type = 'college';
                $scope->scope_id = $college->college_id;
            }
            $scope->is_active = true;
            $scope->save();
            $this->audit($actor, 'administrative.dean_appointed', ['user_id' => $account->user_id,
                'employee_id' => $employee->employee_id, 'college_id' => $college->college_id], $ip);
            return ['user_id' => $account->user_id, 'employee_id' => $employee->employee_id,
                'college_id' => $college->college_id, 'username' => $account->username];
        });
    }

    public function retireDean(User $actor, int $userId, int $collegeId, ?string $ip): void
    {
        $this->authorize($actor, self::DEANS_MANAGE);
        DB::transaction(function () use ($actor, $userId, $collegeId, $ip): void {
            $account = User::query()->lockForUpdate()->findOrFail($userId);
            if ($account->user_id === $actor->user_id || $account->userRoleRecords()
                ->whereHas('role', fn ($q) => $q->where('role_code', '!=', 'dean'))->exists()) {
                throw new AccessDeniedHttpException('هذا الحساب خارج نطاق إدارة العمداء.');
            }
            $roleId = Role::query()->where('role_code', 'dean')->where('is_active', true)->value('role_id');
            $role = UserRole::query()->where('user_id', $userId)->where('role_id', $roleId)->where('is_active', true)
                ->lockForUpdate()->firstOrFail();
            $scope = UserAccessScope::query()->where('user_id', $userId)->where('scope_type', 'college')
                ->where('scope_id', $collegeId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if ($account->accessScopes()->where('is_active', true)->where(fn ($q) => $q
                ->where('scope_type', '!=', 'college')->orWhere('scope_id', '!=', $collegeId))->exists()) {
                throw new AccessDeniedHttpException('للحساب نطاقات إضافية؛ راجعها مع مدير النظام.');
            }
            $scope->forceFill(['is_active' => false])->save();
            $role->forceFill(['is_active' => false])->save();
            $this->audit($actor, 'administrative.dean_retired', ['user_id' => $userId, 'college_id' => $collegeId], $ip);
        });
    }

    private function audit(User $actor, string $action, array $details, ?string $ip): void
    {
        UserActivityLog::query()->create(['user_id' => $actor->user_id, 'module_code' => 'hr',
            'action_code' => $action, 'description' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip]);
    }
}
