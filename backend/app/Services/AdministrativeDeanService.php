<?php

namespace App\Services;

use App\Models\College;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\AdministrativeGovernance;
use App\Support\AdministrativeGovernanceException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Dedicated, narrow path for college deans: it may only grant the `dean` role plus
 * ONE active college scope, link the account to its employee record, and record a
 * DEAN position at the college's organizational unit. It never touches other roles,
 * role permissions, university scopes, super_admin accounts or unrelated accounts.
 * Ending/transfer deactivates the old college scope in the same transaction; the
 * account and its history are kept.
 */
class AdministrativeDeanService
{
    public function __construct(private readonly AdministrativeFacultyService $faculty) {}

    /** @return array<string, mixed> */
    public function list(): array
    {
        $role = $this->deanRole(false);
        $deans = $role === null ? collect() : $this->activeDeanRows($role)->get();
        $positions = $this->openDeanPositions($deans->pluck('employee_id')->filter()->all());
        $byCollege = $deans->groupBy('college_id');

        $colleges = DB::table('colleges')->orderBy('college_name')
            ->get(['college_id', 'college_name', 'college_code', 'is_active', 'organizational_unit_id'])
            ->map(function ($college) use ($byCollege, $positions) {
                $rows = $byCollege->get($college->college_id, collect());

                return [
                    'college_id' => (int) $college->college_id,
                    'college_name' => $college->college_name,
                    'college_code' => $college->college_code,
                    'is_active' => (bool) $college->is_active,
                    'has_organizational_unit' => $college->organizational_unit_id !== null,
                    'deans' => $rows->map(fn ($row) => [
                        'user_id' => (int) $row->user_id,
                        'username' => $row->username,
                        'email' => $row->email,
                        'account_status' => $row->status_code,
                        'employee_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                        'employee_number' => $row->employee_number,
                        'full_name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: null,
                        'position_start_date' => $positions[(int) $row->employee_id][(int) $college->organizational_unit_id] ?? null,
                    ])->values()->all(),
                    'warning' => $rows->count() > 1 ? 'multiple_deans' : ($rows->isEmpty() ? 'no_dean' : null),
                ];
            })->values()->all();

        return [
            'dean_role_available' => $role !== null,
            'colleges' => $colleges,
        ];
    }

    public function appoint(User $actor, array $data, ?string $ip): array
    {
        try {
            return DB::transaction(function () use ($actor, $data, $ip): array {
                $college = $this->lockCollege((int) $data['college_id']);
                $role = $this->deanRole(true);
                $current = $this->currentDeanIds($role, (int) $college->college_id);
                $this->assertExpected($current, $data['expected_current_dean_user_id'] ?? null);

                $employee = $this->resolveEmployee($data['employee'] ?? [], $college);
                $user = $this->resolveAccount($actor, $data['account'] ?? [], $employee);

                if ($current->contains((int) $user->user_id) && $this->deanCollegeIds($role, $user)->all() === [(int) $college->college_id]) {
                    return ['changed' => false, 'user_id' => (int) $user->user_id, 'college_id' => (int) $college->college_id];
                }

                $otherColleges = $this->deanCollegeIds($role, $user)->reject(fn ($id) => $id === (int) $college->college_id);
                if ($otherColleges->isNotEmpty()) {
                    throw AdministrativeGovernanceException::conflict('dean_of_other_college', 'هذا الحساب عميد لكلية أخرى؛ استخدم «نقل العميد» بدل التعيين.');
                }

                $others = $current->reject(fn ($id) => $id === (int) $user->user_id);
                if ($others->isNotEmpty() && empty($data['replace_current'])) {
                    throw AdministrativeGovernanceException::conflict('college_has_dean', 'للكلية عميد حالي. أكّد استبداله صراحة؛ سيُنهى تكليفه ويُسحب نطاقه.');
                }

                $start = Carbon::parse($data['start_date'] ?? now()->toDateString());
                foreach ($others as $previousId) {
                    $previous = $this->lockUser((int) $previousId);
                    $this->assertManageableAccount($actor, $previous);
                    $this->endLocked($actor, $role, $college, $previous, $start->copy()->subDay(), $ip, 'replaced');
                }

                $before = $this->snapshot($user);
                $this->grantDean($actor, $role, $user, $college, $employee, $start);
                $this->faculty->audit($actor, 'dean.appointed', $ip, [
                    'college_id' => (int) $college->college_id,
                    'user_id' => (int) $user->user_id,
                    'employee_id' => (int) $employee->employee_id,
                    'account_mode' => $data['account']['mode'] ?? null,
                    'employee_mode' => $data['employee']['mode'] ?? null,
                    'replaced_user_ids' => $others->values()->all(),
                    'before' => $before,
                    'after' => $this->snapshot($user),
                ]);

                return ['changed' => true, 'user_id' => (int) $user->user_id, 'college_id' => (int) $college->college_id];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000' || str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw AdministrativeGovernanceException::conflict('dean_duplicate_request', 'تعارضت العملية مع طلب متزامن أو بيانات مكررة. أعد تحميل الصفحة وتحقق من الحالة.');
            }
            throw $exception;
        }
    }

    public function transfer(User $actor, int $fromCollegeId, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $fromCollegeId, $data, $ip): array {
            $toCollegeId = (int) $data['to_college_id'];
            if ($toCollegeId === $fromCollegeId) {
                throw AdministrativeGovernanceException::invalid('dean_transfer_same_college', 'الكلية الجديدة هي نفسها الكلية الحالية.');
            }
            $locked = [];
            foreach (collect([$fromCollegeId, $toCollegeId])->sort()->values() as $id) {
                $locked[$id] = $this->lockCollege($id);
            }
            $from = $locked[$fromCollegeId];
            $to = $locked[$toCollegeId];
            $role = $this->deanRole(true);
            $user = $this->lockUser((int) $data['dean_user_id']);
            $this->assertManageableAccount($actor, $user);

            if (! $this->currentDeanIds($role, $fromCollegeId)->contains((int) $user->user_id)) {
                throw AdministrativeGovernanceException::conflict('dean_state_stale', 'الحساب المحدد ليس عميد الكلية الحالية بعد الآن. أعد تحميل الصفحة.');
            }
            $targetCurrent = $this->currentDeanIds($role, $toCollegeId);
            $this->assertExpected($targetCurrent, $data['expected_target_dean_user_id'] ?? null);
            if ($targetCurrent->isNotEmpty() && empty($data['replace_current'])) {
                throw AdministrativeGovernanceException::conflict('college_has_dean', 'للكلية الجديدة عميد حالي. أكّد استبداله صراحة.');
            }

            $start = Carbon::parse($data['start_date'] ?? now()->toDateString());
            foreach ($targetCurrent as $previousId) {
                $previous = $this->lockUser((int) $previousId);
                $this->assertManageableAccount($actor, $previous);
                $this->endLocked($actor, $role, $to, $previous, $start->copy()->subDay(), $ip, 'replaced');
            }
            $before = $this->snapshot($user);
            $employee = Employee::query()->lockForUpdate()->findOrFail($user->employee_id);
            $this->closeScope($user, $fromCollegeId);
            $this->closeDeanPosition($employee, $from, $start->copy()->subDay());
            $this->grantDean($actor, $role, $user, $to, $employee, $start);
            $this->faculty->audit($actor, 'dean.transferred', $ip, [
                'user_id' => (int) $user->user_id,
                'from_college_id' => $fromCollegeId,
                'to_college_id' => $toCollegeId,
                'replaced_user_ids' => $targetCurrent->values()->all(),
                'before' => $before,
                'after' => $this->snapshot($user),
            ]);

            return ['changed' => true, 'user_id' => (int) $user->user_id, 'college_id' => $toCollegeId];
        });
    }

    public function end(User $actor, int $collegeId, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $collegeId, $data, $ip): array {
            $college = $this->lockCollege($collegeId, false);
            $role = $this->deanRole(true);
            $user = $this->lockUser((int) $data['dean_user_id']);
            $this->assertManageableAccount($actor, $user);
            if (! $this->currentDeanIds($role, $collegeId)->contains((int) $user->user_id)) {
                throw AdministrativeGovernanceException::conflict('dean_state_stale', 'الحساب المحدد ليس عميد هذه الكلية حاليًا. أعد تحميل الصفحة.');
            }
            $this->endLocked($actor, $role, $college, $user, Carbon::parse($data['end_date'] ?? now()->toDateString()), $ip, 'ended');

            return ['changed' => true, 'user_id' => (int) $user->user_id, 'college_id' => $collegeId];
        });
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function endLocked(User $actor, Role $role, College $college, User $user, Carbon $endDate, ?string $ip, string $reason): void
    {
        $before = $this->snapshot($user);
        $this->closeScope($user, (int) $college->college_id);
        if ($this->deanCollegeIds($role, $user)->isEmpty()) {
            UserRole::query()->where('user_id', $user->user_id)->where('role_id', $role->role_id)->update(['is_active' => false]);
        }
        if ($user->employee_id !== null) {
            $employee = Employee::query()->lockForUpdate()->find($user->employee_id);
            if ($employee !== null) {
                $this->closeDeanPosition($employee, $college, $endDate);
            }
        }
        $this->faculty->audit($actor, 'dean.ended', $ip, [
            'college_id' => (int) $college->college_id,
            'user_id' => (int) $user->user_id,
            'reason' => $reason,
            'before' => $before,
            'after' => $this->snapshot($user),
        ]);
    }

    private function grantDean(User $actor, Role $role, User $user, College $college, Employee $employee, Carbon $start): void
    {
        if ((int) $user->employee_id !== (int) $employee->employee_id) {
            $user->forceFill(['employee_id' => $employee->employee_id])->save();
        }

        $existing = UserRole::query()->where('user_id', $user->user_id)->where('role_id', $role->role_id)->lockForUpdate()->first();
        if ($existing === null) {
            UserRole::query()->create(['user_id' => $user->user_id, 'role_id' => $role->role_id, 'assigned_by_user_id' => $actor->user_id, 'assigned_at' => now(), 'is_active' => true]);
        } elseif (! $existing->is_active) {
            $existing->forceFill(['is_active' => true, 'assigned_by_user_id' => $actor->user_id, 'assigned_at' => now()])->save();
        }

        // Exactly one active academic scope: this college. University scopes are never
        // granted here (and an account holding one is refused earlier).
        DB::table('user_access_scopes')->where('user_id', $user->user_id)
            ->whereIn('scope_type', ['college', 'department', 'program', 'section'])
            ->where(fn ($q) => $q->where('scope_type', '!=', 'college')->orWhere('scope_id', '!=', $college->college_id))
            ->update(['is_active' => 0, 'updated_at' => now()]);
        $scope = DB::table('user_access_scopes')->where('user_id', $user->user_id)->where('scope_type', 'college')->where('scope_id', $college->college_id)->first();
        if ($scope === null) {
            DB::table('user_access_scopes')->insert(['user_id' => $user->user_id, 'scope_type' => 'college', 'scope_id' => $college->college_id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('user_access_scopes')->where('user_access_scope_id', $scope->user_access_scope_id)->update(['is_active' => 1, 'updated_at' => now()]);
        }

        $positionId = DB::table('positions')->where('position_code', AdministrativeGovernance::POSITION_DEAN)->value('position_id');
        if ($positionId !== null) {
            $open = EmployeePosition::query()->where('employee_id', $employee->employee_id)->where('position_id', $positionId)
                ->where('organizational_unit_id', $college->organizational_unit_id)->where('is_active', true)->whereNull('end_date')->exists();
            if (! $open) {
                EmployeePosition::query()->create([
                    'employee_id' => $employee->employee_id,
                    'position_id' => $positionId,
                    'organizational_unit_id' => $college->organizational_unit_id,
                    'start_date' => $start->toDateString(),
                    'end_date' => null,
                    'is_primary' => true,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function closeScope(User $user, int $collegeId): void
    {
        DB::table('user_access_scopes')->where('user_id', $user->user_id)->where('scope_type', 'college')->where('scope_id', $collegeId)
            ->update(['is_active' => 0, 'updated_at' => now()]);
    }

    private function closeDeanPosition(Employee $employee, College $college, Carbon $endDate): void
    {
        $positionId = DB::table('positions')->where('position_code', AdministrativeGovernance::POSITION_DEAN)->value('position_id');
        if ($positionId === null) {
            return;
        }
        EmployeePosition::query()->where('employee_id', $employee->employee_id)->where('position_id', $positionId)
            ->where('organizational_unit_id', $college->organizational_unit_id)->where('is_active', true)
            ->get()
            ->each(fn (EmployeePosition $position) => $position->forceFill([
                'end_date' => $endDate->max(Carbon::parse($position->start_date))->toDateString(),
                'is_active' => false,
            ])->save());
    }

    private function resolveEmployee(array $input, College $college): Employee
    {
        if (($input['mode'] ?? null) === 'existing') {
            $employee = Employee::query()->with('employeeStatus')->lockForUpdate()->find((int) ($input['employee_id'] ?? 0));
            if ($employee === null
                || trim((string) $employee->employee_number) !== trim((string) ($input['employee_number'] ?? ''))
                || mb_strtolower(trim((string) $employee->last_name)) !== mb_strtolower(trim((string) ($input['last_name'] ?? '')))) {
                throw AdministrativeGovernanceException::invalid('employee_identity_mismatch', 'بيانات التحقق (رقم الموظف والكنية) لا تطابق سجل الموظف.');
            }
            if ($employee->employeeStatus?->status_code !== 'active') {
                throw AdministrativeGovernanceException::invalid('employee_inactive', 'لا يمكن تعيين موظف غير نشط عميدًا.');
            }

            return $employee;
        }

        return Employee::query()->create([
            'employee_number' => $input['employee_number'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'father_name' => $input['father_name'] ?? null,
            'phone_number' => $input['phone_number'] ?? null,
            'email' => $input['email'] ?? null,
            'employee_type_id' => (int) DB::table('employee_types')->where('type_code', 'academic')->value('employee_type_id'),
            'employee_status_id' => (int) DB::table('employee_statuses')->where('status_code', 'active')->value('employee_status_id'),
            'organizational_unit_id' => $college->organizational_unit_id,
        ]);
    }

    private function resolveAccount(User $actor, array $input, Employee $employee): User
    {
        $linked = User::query()->where('employee_id', $employee->employee_id)->lockForUpdate()->first();
        if (($input['mode'] ?? null) === 'existing') {
            $user = $this->lockUser((int) ($input['user_id'] ?? 0));
            if ($linked !== null && (int) $linked->user_id !== (int) $user->user_id) {
                throw AdministrativeGovernanceException::conflict('employee_has_other_account', 'الموظف مرتبط بحساب آخر؛ لا يُربط بحسابين.');
            }
            if ($user->employee_id !== null && (int) $user->employee_id !== (int) $employee->employee_id) {
                throw AdministrativeGovernanceException::denied('account_linked_to_other_employee', 'الحساب مرتبط بموظف آخر؛ لا يمكن ربطه بهذا الموظف.');
            }
            $this->assertManageableAccount($actor, $user);
            if ($user->accountStatus?->status_code !== 'active') {
                throw AdministrativeGovernanceException::invalid('account_inactive', 'الحساب المختار غير مفعّل.');
            }

            return $user;
        }

        if ($linked !== null) {
            throw new AdministrativeGovernanceException('لهذا الموظف حساب دخول قائم؛ اختر «ربط حساب قائم».', 409, 'employee_has_account', ['user_id' => $linked->user_id]);
        }
        $email = mb_strtolower(trim((string) $input['email']));
        if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower(trim((string) $input['username']))])->exists()) {
            throw ValidationException::withMessages(['account.username' => ['اسم المستخدم مستخدم مسبقًا.']]);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['account.email' => ['البريد مستخدم لحساب آخر.']]);
        }
        $user = new User();
        $user->forceFill([
            'username' => trim((string) $input['username']),
            'email' => $email,
            'password_hash' => Hash::make((string) $input['password']),
            'account_status_id' => (int) DB::table('account_statuses')->where('status_code', 'active')->value('account_status_id'),
            'failed_login_attempts' => 0,
            'created_by_user_id' => $actor->user_id,
            'employee_id' => $employee->employee_id,
        ])->save();

        return $user;
    }

    /** Refuse accounts this path must never modify. */
    private function assertManageableAccount(User $actor, User $user): void
    {
        if ((int) $actor->user_id === (int) $user->user_id) {
            throw AdministrativeGovernanceException::denied('self_change_forbidden', 'لا يمكنك تعيين حسابك الشخصي عميدًا أو تعديل تكليفه.');
        }
        $roles = $user->effectiveRoles();
        if ($roles->contains('super_admin')) {
            throw AdministrativeGovernanceException::denied('protected_account', 'لا يمكن تعديل حساب مدير النظام من هذا المسار.');
        }
        $foreign = $roles->reject(fn ($code) => in_array($code, AdministrativeGovernance::DEAN_COMPATIBLE_ROLES, true));
        if ($foreign->isNotEmpty()) {
            throw AdministrativeGovernanceException::denied('protected_account', 'الحساب يحمل أدوارًا أخرى لا يديرها هذا المسار ('.$foreign->implode('، ').').');
        }
        if (DB::table('user_access_scopes')->where('user_id', $user->user_id)->where('scope_type', 'university')->where('is_active', 1)->exists()) {
            throw AdministrativeGovernanceException::denied('protected_account', 'الحساب يملك نطاقًا جامعيًا؛ لا يُعدّل من هذا المسار.');
        }
    }

    private function deanRole(bool $required): ?Role
    {
        $role = Role::query()->where('role_code', AdministrativeGovernance::ROLE_DEAN)->first();
        if ($role === null || ! $role->is_active) {
            if ($required) {
                throw AdministrativeGovernanceException::invalid('dean_role_unavailable', 'دور العميد غير معرّف أو غير مفعّل.');
            }

            return null;
        }
        if ($required) {
            $restricted = DB::table('role_permissions as rp')->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role->role_id)->pluck('p.permission_code')
                ->contains(fn ($code) => AdministrativeGovernance::isRestrictedPermission((string) $code));
            if ($restricted) {
                throw AdministrativeGovernanceException::denied('dean_role_restricted', 'دور العميد يحمل صلاحية إدارية محجوزة؛ رُفضت العملية احتياطًا. راجع مدير النظام.');
            }
        }

        return $role;
    }

    private function lockCollege(int $collegeId, bool $mustBeUsable = true): College
    {
        $college = College::query()->lockForUpdate()->find($collegeId);
        if ($college === null || ($mustBeUsable && (! $college->is_active || $college->organizational_unit_id === null))) {
            throw AdministrativeGovernanceException::invalid('college_invalid', 'الكلية غير موجودة أو غير مفعّلة أو غير مرتبطة بوحدة تنظيمية.');
        }

        return $college;
    }

    private function lockUser(int $userId): User
    {
        $user = User::query()->with('accountStatus')->lockForUpdate()->find($userId);
        if ($user === null) {
            throw AdministrativeGovernanceException::invalid('account_not_found', 'الحساب غير موجود.');
        }

        return $user;
    }

    /** @return Collection<int, int> active dean user ids holding an active scope on the college */
    private function currentDeanIds(Role $role, int $collegeId): Collection
    {
        return DB::table('user_access_scopes as s')
            ->join('user_roles as ur', fn ($j) => $j->on('ur.user_id', '=', 's.user_id')->where('ur.role_id', $role->role_id)->where('ur.is_active', 1))
            ->where('s.scope_type', 'college')->where('s.scope_id', $collegeId)->where('s.is_active', 1)
            ->lockForUpdate()
            ->pluck('s.user_id')->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** @return Collection<int, int> */
    private function deanCollegeIds(Role $role, User $user): Collection
    {
        $isDean = DB::table('user_roles')->where('user_id', $user->user_id)->where('role_id', $role->role_id)->where('is_active', 1)->exists();
        if (! $isDean) {
            return collect();
        }

        return DB::table('user_access_scopes')->where('user_id', $user->user_id)->where('scope_type', 'college')->where('is_active', 1)
            ->pluck('scope_id')->map(fn ($id) => (int) $id)->values();
    }

    private function assertExpected(Collection $current, $expected): void
    {
        $expectedId = $expected === null || $expected === '' ? null : (int) $expected;
        $matches = $expectedId === null ? $current->isEmpty() : $current->contains($expectedId);
        if (! $matches) {
            throw AdministrativeGovernanceException::conflict('dean_state_stale', 'تغيّر عميد الكلية منذ فتح الصفحة. أعد التحميل وراجع الحالة قبل المتابعة.');
        }
    }

    private function activeDeanRows(Role $role)
    {
        return DB::table('user_access_scopes as s')
            ->join('user_roles as ur', fn ($j) => $j->on('ur.user_id', '=', 's.user_id')->where('ur.role_id', $role->role_id)->where('ur.is_active', 1))
            ->join('users as u', 'u.user_id', '=', 's.user_id')
            ->leftJoin('account_statuses as st', 'st.account_status_id', '=', 'u.account_status_id')
            ->leftJoin('employees as e', 'e.employee_id', '=', 'u.employee_id')
            ->where('s.scope_type', 'college')->where('s.is_active', 1)
            ->select('s.scope_id as college_id', 'u.user_id', 'u.username', 'u.email', 'st.status_code', 'u.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name');
    }

    /** @return array<int, array<int, string>> employee_id => unit_id => start_date */
    private function openDeanPositions(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $result = [];
        DB::table('employee_positions as ep')->join('positions as p', 'p.position_id', '=', 'ep.position_id')
            ->where('p.position_code', AdministrativeGovernance::POSITION_DEAN)->where('ep.is_active', 1)->whereNull('ep.end_date')
            ->whereIn('ep.employee_id', $employeeIds)
            ->get(['ep.employee_id', 'ep.organizational_unit_id', 'ep.start_date'])
            ->each(function ($row) use (&$result): void {
                $result[(int) $row->employee_id][(int) $row->organizational_unit_id] = (string) $row->start_date;
            });

        return $result;
    }

    private function snapshot(User $user): array
    {
        return [
            'roles' => DB::table('user_roles as ur')->join('roles as r', 'r.role_id', '=', 'ur.role_id')->where('ur.user_id', $user->user_id)
                ->get(['r.role_code', 'ur.is_active'])->map(fn ($r) => ['role' => $r->role_code, 'active' => (bool) $r->is_active])->all(),
            'scopes' => DB::table('user_access_scopes')->where('user_id', $user->user_id)
                ->get(['scope_type', 'scope_id', 'is_active'])->map(fn ($s) => ['type' => $s->scope_type, 'id' => (int) $s->scope_id, 'active' => (bool) $s->is_active])->all(),
            'employee_id' => $user->fresh()?->employee_id,
        ];
    }
}
