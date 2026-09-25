<?php

namespace App\Services;

use App\Exceptions\AccountAdministrationException;
use App\Models\AccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserRole;
use App\Support\AcademicQueuePagination;
use App\Support\AccountAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The only write path for accounts, role assignments and account status.
 * Every rule is re-checked on the server for each request; the UI flags are
 * derived from the same functions and are never trusted as input.
 */
class AccountAdministrationService
{
    public function __construct(private readonly ?PersonNameCorrectionService $names = null) {}

    /** @return array<string, mixed> */
    public function list(User $actor, array $filters): array
    {
        $query = User::query()
            ->with(['accountStatus', 'userRoleRecords' => fn ($q) => $q->where('is_active', true)->with('role')])
            ->orderByDesc('user_id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like, $search): void {
                $q->where('username', 'like', $like)->orWhere('email', 'like', $like)
                    // Holder names live on the linked employee/student record.
                    ->orWhereHas('employee', fn ($e) => $e->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                    ->orWhereHas('student', fn ($st) => $st->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
                if (ctype_digit($search)) {
                    $q->orWhere('user_id', (int) $search);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->whereHas('accountStatus', fn ($q) => $q->where('status_code', $filters['status']));
        }
        if (! empty($filters['role_id'])) {
            $query->whereHas('userRoleRecords', fn ($q) => $q->where('is_active', true)->where('role_id', (int) $filters['role_id']));
        }

        $page = $query->paginate(AcademicQueuePagination::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15));
        $actorIsAdmin = $this->isSuperAdmin($actor);

        return [
            'data' => collect($page->items())->map(fn (User $user) => $this->summary($user) + [
                'holder_name' => $this->holder($user)['display_name'],
                'can_manage' => $this->targetRestriction($actor, $user, $actorIsAdmin) === null,
            ])->values()->all(),
            'meta' => AcademicQueuePagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    public function options(User $actor): array
    {
        $actorIsAdmin = $this->isSuperAdmin($actor);
        $roles = Role::query()->with(['permissions' => fn ($q) => $q->orderBy('permission_code')])->orderBy('role_id')->get();

        return [
            'statuses' => AccountStatus::query()->whereIn('status_code', AccountAdministration::STATUSES)
                ->orderBy('account_status_id')->get(['status_code', 'status_name'])
                ->map(fn ($s) => ['code' => $s->status_code, 'name' => $s->status_name])->values()->all(),
            'roles' => $roles->map(function (Role $role) use ($actorIsAdmin): array {
                $restriction = $this->roleRestriction($role, $actorIsAdmin, true);

                return [
                    'role_id' => $role->role_id,
                    'role_code' => $role->role_code,
                    'role_name' => $role->role_name,
                    'is_system_role' => (bool) $role->is_system_role,
                    'is_active' => (bool) $role->is_active,
                    'assignable' => $restriction === null,
                    'restriction' => $restriction,
                    'permissions' => $role->permissions->where('is_active', true)->map(fn ($p) => [
                        'permission_code' => $p->permission_code,
                        'permission_name' => $p->permission_name,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'actor' => ['is_super_admin' => $actorIsAdmin, 'can_manage' => $actor->hasPermission(AccountAdministration::MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $actor, User $user): array
    {
        $user->load(['accountStatus', 'userRoleRecords' => fn ($q) => $q->with(['role', 'assignedBy'])->orderByDesc('is_active')->orderBy('role_id')]);
        $actorIsAdmin = $this->isSuperAdmin($actor);
        $restriction = $this->targetRestriction($actor, $user, $actorIsAdmin);
        $canManage = $actor->hasPermission(AccountAdministration::MANAGE);
        $isSelf = (int) $actor->user_id === (int) $user->user_id;
        $holder = $this->holder($user);

        $permissions = DB::table('role_permissions')
            ->join('permissions', 'permissions.permission_id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.role_id', '=', 'role_permissions.role_id')
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.role_id')
            ->where('user_roles.user_id', $user->user_id)
            ->where('user_roles.is_active', true)
            ->where('roles.is_active', true)
            ->where('permissions.is_active', true)
            ->orderBy('permissions.permission_code')
            ->get(['permissions.permission_code', 'permissions.permission_name', 'roles.role_code', 'roles.role_name'])
            ->groupBy('permission_code')
            ->map(fn (Collection $rows, string $code) => [
                'permission_code' => $code,
                'permission_name' => $rows->first()->permission_name,
                'granted_by_roles' => $rows->map(fn ($r) => ['role_code' => $r->role_code, 'role_name' => $r->role_name])->unique('role_code')->values()->all(),
            ])->values()->all();

        return $this->summary($user) + [
            'role_assignments' => $user->userRoleRecords->map(fn (UserRole $row) => [
                'user_role_id' => $row->user_role_id,
                'role_id' => $row->role_id,
                'role_code' => $row->role?->role_code,
                'role_name' => $row->role?->role_name,
                'role_is_active' => (bool) $row->role?->is_active,
                'is_active' => (bool) $row->is_active,
                'assigned_at' => $row->assigned_at?->toIso8601String(),
                'assigned_by' => $row->assignedBy?->username,
            ])->values()->all(),
            'effective_permissions' => $permissions,
            'super_admin_bypass' => $user->userRoleRecords->contains(fn ($r) => $r->is_active && $r->role?->is_active && $r->role?->role_code === AccountAdministration::ROLE_SUPER_ADMIN),
            'holder' => $holder,
            'capabilities' => [
                'can_manage' => $canManage && $restriction === null,
                'can_change_status' => $canManage && $restriction === null && ! $isSelf,
                'can_edit_login' => $canManage && $restriction === null && ! $isSelf,
                'can_reset_password' => $canManage && $restriction === null && ! $isSelf,
                'can_correct_holder_name' => $actor->hasPermission(AccountAdministration::HOLDER_NAME_MANAGE) && $restriction === null && ! $isSelf
                    && $holder['type'] !== null && $holder['links'] === 1,
                'restriction' => $canManage ? $restriction : 'manage_permission_missing',
            ],
            'is_current_user' => $actor->user_id === $user->user_id,
        ];
    }

    public function create(User $actor, array $data, ?string $ip): User
    {
        return $this->audited($actor, 'account.created', null, $ip, fn () => $this->createAccount($actor, $data, $ip));
    }

    private function createAccount(User $actor, array $data, ?string $ip): User
    {
        $actorIsAdmin = $this->isSuperAdmin($actor);

        try {
            return DB::transaction(function () use ($actor, $data, $ip, $actorIsAdmin): User {
                $status = $this->status($data['account_status'] ?? 'active');
                $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
                $roles = Role::query()->whereIn('role_id', $roleIds)->lockForUpdate()->get();
                foreach ($roles as $role) {
                    $this->assertRoleAssignable($role, $actorIsAdmin);
                }

                $employeeId = $this->lockLinkableEmployee($data['employee_id'] ?? null);

                $user = new User();
                $user->forceFill([
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'password_hash' => Hash::make($data['password']),
                    'account_status_id' => $status->account_status_id,
                    'failed_login_attempts' => 0,
                    'created_by_user_id' => $actor->user_id,
                    'employee_id' => $employeeId,
                ])->save();

                foreach ($roles as $role) {
                    UserRole::query()->create([
                        'user_id' => $user->user_id,
                        'role_id' => $role->role_id,
                        'assigned_by_user_id' => $actor->user_id,
                        'assigned_at' => now(),
                        'is_active' => true,
                    ]);
                }

                $this->audit($actor, 'account.created', $ip, [
                    'target_user_id' => $user->user_id,
                    'username' => $user->username,
                    'status' => $status->status_code,
                    'roles' => $roles->pluck('role_code')->values()->all(),
                    'employee_id' => $employeeId,
                    'outcome' => 'success',
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages($this->duplicateFieldErrors($data));
        }
    }

    public function assignRole(User $actor, int $userId, int $roleId, ?string $ip): void
    {
        $this->audited($actor, 'account.role_assigned', $userId, $ip, fn () => $this->assignRoleNow($actor, $userId, $roleId, $ip));
    }

    private function assignRoleNow(User $actor, int $userId, int $roleId, ?string $ip): void
    {
        $actorIsAdmin = $this->isSuperAdmin($actor);

        try {
            DB::transaction(function () use ($actor, $userId, $roleId, $ip, $actorIsAdmin): void {
                $target = User::query()->lockForUpdate()->findOrFail($userId);
                $role = Role::query()->lockForUpdate()->findOrFail($roleId);
                $this->assertTargetManageable($actor, $target, $actorIsAdmin);
                $this->assertRoleAssignable($role, $actorIsAdmin);

                $row = UserRole::query()->where('user_id', $target->user_id)->where('role_id', $role->role_id)->lockForUpdate()->first();
                if ($row?->is_active) {
                    throw AccountAdministrationException::conflict('role_already_assigned', 'هذا الدور مُسند للحساب مسبقًا.');
                }

                $values = ['assigned_by_user_id' => $actor->user_id, 'assigned_at' => now(), 'is_active' => true];
                $row === null
                    ? UserRole::query()->create(['user_id' => $target->user_id, 'role_id' => $role->role_id] + $values)
                    : $row->forceFill($values)->save();

                $this->audit($actor, 'account.role_assigned', $ip, [
                    'target_user_id' => $target->user_id,
                    'role_code' => $role->role_code,
                    'reactivated' => $row !== null,
                    'outcome' => 'success',
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
            throw AccountAdministrationException::conflict('role_already_assigned', 'هذا الدور مُسند للحساب مسبقًا.');
        }
    }

    public function revokeRole(User $actor, int $userId, int $roleId, ?string $ip): void
    {
        $this->audited($actor, 'account.role_revoked', $userId, $ip, fn () => $this->revokeRoleNow($actor, $userId, $roleId, $ip));
    }

    private function revokeRoleNow(User $actor, int $userId, int $roleId, ?string $ip): void
    {
        $actorIsAdmin = $this->isSuperAdmin($actor);

        DB::transaction(function () use ($actor, $userId, $roleId, $ip, $actorIsAdmin): void {
            $target = User::query()->lockForUpdate()->findOrFail($userId);
            $role = Role::query()->lockForUpdate()->findOrFail($roleId);
            $this->assertTargetManageable($actor, $target, $actorIsAdmin);
            $restriction = $this->roleRestriction($role, $actorIsAdmin, false);
            if ($restriction !== null) {
                throw $this->roleRestrictionException($restriction);
            }

            $row = UserRole::query()->where('user_id', $target->user_id)->where('role_id', $role->role_id)->lockForUpdate()->first();
            if (! $row?->is_active) {
                throw AccountAdministrationException::conflict('role_not_assigned', 'هذا الدور غير مُسند للحساب حاليًا.');
            }

            if ($role->role_code === AccountAdministration::ROLE_SUPER_ADMIN) {
                $this->assertNotLastSuperAdmin($target);
            }

            $row->forceFill(['is_active' => false])->save();

            $this->audit($actor, 'account.role_revoked', $ip, [
                'target_user_id' => $target->user_id,
                'role_code' => $role->role_code,
                'outcome' => 'success',
            ]);
        });
    }

    public function setStatus(User $actor, int $userId, string $statusCode, ?string $ip): void
    {
        $this->audited($actor, 'account.status_changed', $userId, $ip, fn () => $this->setStatusNow($actor, $userId, $statusCode, $ip));
    }

    private function setStatusNow(User $actor, int $userId, string $statusCode, ?string $ip): void
    {
        $actorIsAdmin = $this->isSuperAdmin($actor);

        DB::transaction(function () use ($actor, $userId, $statusCode, $ip, $actorIsAdmin): void {
            $target = User::query()->with('accountStatus')->lockForUpdate()->findOrFail($userId);
            if ($target->user_id === $actor->user_id) {
                throw AccountAdministrationException::forbidden('self_change_forbidden', 'لا يمكنك تغيير حالة حسابك الشخصي.');
            }
            $this->assertTargetManageable($actor, $target, $actorIsAdmin);

            $status = $this->status($statusCode);
            $previous = $target->accountStatus?->status_code;
            if ($previous === $status->status_code) {
                throw AccountAdministrationException::conflict('status_unchanged', 'الحساب على هذه الحالة مسبقًا.');
            }

            if ($status->status_code !== 'active') {
                $this->assertNotLastSuperAdmin($target);
            }

            $changes = ['account_status_id' => $status->account_status_id];
            if ($status->status_code === 'active') {
                $changes['failed_login_attempts'] = 0;
            }
            $target->forceFill($changes)->save();

            $revoked = $status->status_code !== 'active' ? $target->tokens()->delete() : 0;

            $this->audit($actor, 'account.status_changed', $ip, [
                'target_user_id' => $target->user_id,
                'from' => $previous,
                'to' => $status->status_code,
                'tokens_revoked' => $revoked,
                'outcome' => 'success',
            ]);
        });
    }

    /**
     * Change the login identity (username and/or email) of a manageable account.
     * Uniqueness is case-insensitive; the email is stored trimmed and lower-cased.
     */
    public function updateLoginIdentity(User $actor, int $userId, array $data, ?string $ip): void
    {
        $this->audited($actor, 'account.login_identity_updated', $userId, $ip, function () use ($actor, $userId, $data, $ip): void {
            DB::transaction(function () use ($actor, $userId, $data, $ip): void {
                $target = User::query()->lockForUpdate()->findOrFail($userId);
                $this->assertNotSelf($actor, $target, 'لا يمكنك تعديل بيانات دخول حسابك الشخصي من هذه البوابة.');
                $this->assertTargetManageable($actor, $target, $this->isSuperAdmin($actor));

                $changes = [];
                if (array_key_exists('username', $data)) {
                    $username = trim((string) $data['username']);
                    if ($username !== $target->username) {
                        if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->whereKeyNot($target->user_id)->exists()) {
                            throw ValidationException::withMessages(['username' => ['اسم المستخدم مستخدم لحساب آخر.']]);
                        }
                        $changes['username'] = ['from' => $target->username, 'to' => $username];
                    }
                }
                if (array_key_exists('email', $data)) {
                    $email = mb_strtolower(trim((string) $data['email']));
                    if ($email !== $target->email) {
                        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($target->user_id)->exists()) {
                            throw ValidationException::withMessages(['email' => ['البريد الإلكتروني مستخدم لحساب آخر.']]);
                        }
                        $changes['email'] = ['from' => $target->email, 'to' => $email];
                    }
                }
                if ($changes === []) {
                    throw AccountAdministrationException::conflict('account_unchanged', 'لم تتغير أي قيمة؛ اسم المستخدم والبريد مطابقان للقيم الحالية.');
                }

                $target->forceFill(array_map(fn ($change) => $change['to'], $changes))->save();
                $this->audit($actor, 'account.login_identity_updated', $ip, [
                    'target_user_id' => $target->user_id,
                    'changes' => $changes,
                    'outcome' => 'success',
                ]);
            });
        });
    }

    /**
     * Set a new password chosen by the operator. The previous password cannot be
     * recovered or shown. Every token of the target is revoked (Sanctum), so all of
     * its sessions end; the value never appears in responses or logs.
     *
     * @return int number of revoked tokens
     */
    public function resetPassword(User $actor, int $userId, string $password, ?string $ip): int
    {
        return $this->audited($actor, 'account.password_reset', $userId, $ip, function () use ($actor, $userId, $password, $ip): int {
            return DB::transaction(function () use ($actor, $userId, $password, $ip): int {
                $target = User::query()->lockForUpdate()->findOrFail($userId);
                $this->assertNotSelf($actor, $target, 'لا يمكنك إعادة تعيين كلمة مرور حسابك الشخصي من هذه البوابة.');
                $this->assertTargetManageable($actor, $target, $this->isSuperAdmin($actor));

                $target->forceFill(['password_hash' => Hash::make($password), 'failed_login_attempts' => 0])->save();
                $revoked = $target->tokens()->delete();

                $this->audit($actor, 'account.password_reset', $ip, [
                    'target_user_id' => $target->user_id,
                    'tokens_revoked' => $revoked,
                    'outcome' => 'success',
                ]);

                return $revoked;
            });
        });
    }

    /**
     * Correct the holder's name on the employee/student row the account is already
     * linked to. The link is kept; no person is created; nothing else changes.
     */
    public function correctHolderName(User $actor, int $userId, string $personType, array $names, ?string $ip): void
    {
        $this->audited($actor, 'account.holder_name_corrected', $userId, $ip, function () use ($actor, $userId, $personType, $names, $ip): void {
            DB::transaction(function () use ($actor, $userId, $personType, $names, $ip): void {
                if (! $actor->hasPermission(AccountAdministration::HOLDER_NAME_MANAGE)) {
                    throw AccountAdministrationException::forbidden('holder_name_permission_missing', 'لا تملك صلاحية تصحيح اسم صاحب الحساب.');
                }
                $target = User::query()->lockForUpdate()->findOrFail($userId);
                $this->assertNotSelf($actor, $target, 'لا يمكنك تصحيح اسمك من هذه البوابة؛ راجع الجهة المختصة.');
                $this->assertTargetManageable($actor, $target, $this->isSuperAdmin($actor));

                $personId = $this->linkedPersonId($target, $personType);
                $result = $this->nameService()->correct($personType, $personId, $names);
                if ($result['changed'] === []) {
                    throw AccountAdministrationException::conflict('holder_name_unchanged', 'الاسم المدخل مطابق للاسم الحالي؛ لم يتغير شيء.');
                }

                $this->audit($actor, 'account.holder_name_corrected', $ip, [
                    'target_user_id' => $target->user_id,
                    'person_type' => $personType,
                    'person_id' => $personId,
                    'changes' => collect($result['changed'])->mapWithKeys(fn ($field) => [$field => ['from' => $result['before'][$field], 'to' => $result['after'][$field]]])->all(),
                    'outcome' => 'success',
                ]);
            });
        });
    }

    /** @return array{type:?string, id:?int, first_name:?string, last_name:?string, father_name:?string, mother_name:?string, display_name:?string, links:int} */
    public function holder(User $user): array
    {
        $links = ($user->employee_id !== null ? 1 : 0) + ($user->student_id !== null ? 1 : 0);
        $person = $user->employee_id !== null
            ? $this->nameService()->current('employee', (int) $user->employee_id)
            : ($user->student_id !== null ? $this->nameService()->current('student', (int) $user->student_id) : null);
        $display = $person === null ? null : trim(implode(' ', array_filter([$person['first_name'], $person['father_name'] ?? null, $person['last_name']])));

        return [
            'type' => $person['type'] ?? null,
            'id' => $person['id'] ?? null,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'father_name' => $person['father_name'] ?? null,
            'mother_name' => $person['mother_name'] ?? null,
            'display_name' => $display ?: null,
            'links' => $links,
        ];
    }

    private function linkedPersonId(User $target, string $personType): int
    {
        if ($target->employee_id !== null && $target->student_id !== null) {
            throw AccountAdministrationException::conflict('holder_link_ambiguous', 'الحساب مرتبط بموظف وطالب معًا؛ صحّح الاسم من الجهة المختصة بعد مراجعة الربط.');
        }
        $id = $personType === 'employee' ? $target->employee_id : ($personType === 'student' ? $target->student_id : null);
        if ($target->employee_id === null && $target->student_id === null) {
            throw AccountAdministrationException::conflict('holder_not_linked', 'الحساب غير مرتبط بموظف أو طالب؛ لا يوجد اسم صاحب حساب لتصحيحه.');
        }
        if ($id === null) {
            throw AccountAdministrationException::conflict('holder_link_changed', 'نوع السجل المرتبط بالحساب تغيّر؛ أعد تحميل الحساب.');
        }

        return (int) $id;
    }

    private function nameService(): PersonNameCorrectionService
    {
        return $this->names ?? app(PersonNameCorrectionService::class);
    }

    private function assertNotSelf(User $actor, User $target, string $message): void
    {
        if ((int) $actor->user_id === (int) $target->user_id) {
            throw AccountAdministrationException::forbidden('self_change_forbidden', $message);
        }
    }

    /**
     * Runs a sensitive operation; when it is refused by the rules (403/409/422 from
     * the service) a separate, committed audit row records the attempt with its
     * outcome. The audited event is the attempt; `outcome` is the operation status.
     */
    private function audited(User $actor, string $action, ?int $targetUserId, ?string $ip, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AccountAdministrationException $exception) {
            $this->audit($actor, $action, $ip, ['target_user_id' => $targetUserId, 'outcome' => 'failed', 'error_code' => $exception->errorCode, 'http_status' => $exception->status]);
            throw $exception;
        } catch (ValidationException $exception) {
            $this->audit($actor, $action, $ip, ['target_user_id' => $targetUserId, 'outcome' => 'failed', 'error_code' => 'validation_failed', 'fields' => array_keys($exception->errors()), 'http_status' => 422]);
            throw $exception;
        }
    }

    /**
     * Optional employee link at creation (server-side callers only; the HTTP
     * request does not accept it). One account per employee, fail closed.
     */
    private function lockLinkableEmployee(mixed $employeeId): ?int
    {
        if ($employeeId === null || $employeeId === '') {
            return null;
        }
        $employee = DB::table('employees')->where('employee_id', (int) $employeeId)->lockForUpdate()->first();
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => ['سجل الموظف غير موجود.']]);
        }
        if (User::query()->where('employee_id', (int) $employeeId)->lockForUpdate()->exists()) {
            throw AccountAdministrationException::conflict('employee_has_account', 'لهذا الموظف حساب دخول قائم.');
        }

        return (int) $employeeId;
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRoleCode(AccountAdministration::ROLE_SUPER_ADMIN);
    }

    /**
     * null when the role may be assigned/revoked by this actor; otherwise the
     * reason code. Non-super_admin actors are limited to an explicit allowlist.
     */
    public function roleRestriction(Role $role, bool $actorIsAdmin, bool $forAssignment): ?string
    {
        if ($forAssignment && ! $role->is_active) {
            return 'role_inactive';
        }
        if ($actorIsAdmin) {
            return null;
        }
        if (! in_array($role->role_code, AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES, true)) {
            return 'role_not_assignable';
        }
        // Fail closed on any mapping, active or not, to a restricted permission.
        $carriesRestricted = DB::table('role_permissions')
            ->join('permissions', 'permissions.permission_id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->role_id)
            ->pluck('permissions.permission_code')
            ->contains(fn ($code) => AccountAdministration::isRestrictedPermission((string) $code));

        return $carriesRestricted ? 'role_carries_restricted_permission' : null;
    }

    private function targetRestriction(User $actor, User $target, bool $actorIsAdmin): ?string
    {
        if ($actorIsAdmin) {
            return null;
        }
        if ($actor->user_id === $target->user_id) {
            return 'self_change_forbidden';
        }
        $protected = UserRole::query()
            ->where('user_id', $target->user_id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereNotIn('role_code', AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES))
            ->exists();

        return $protected ? 'protected_account' : null;
    }

    private function assertTargetManageable(User $actor, User $target, bool $actorIsAdmin): void
    {
        $restriction = $this->targetRestriction($actor, $target, $actorIsAdmin);
        if ($restriction === 'self_change_forbidden') {
            throw AccountAdministrationException::forbidden($restriction, 'لا يمكنك تعديل أدوار أو حالة حسابك الشخصي.');
        }
        if ($restriction !== null) {
            throw AccountAdministrationException::forbidden($restriction, 'هذا الحساب يحمل دورًا محجوزًا لمدير النظام، ولا يمكن تعديله إلا بواسطة super_admin.');
        }
    }

    private function assertRoleAssignable(Role $role, bool $actorIsAdmin): void
    {
        $restriction = $this->roleRestriction($role, $actorIsAdmin, true);
        if ($restriction !== null) {
            throw $this->roleRestrictionException($restriction);
        }
    }

    private function roleRestrictionException(string $restriction): AccountAdministrationException
    {
        return match ($restriction) {
            'role_inactive' => AccountAdministrationException::conflict($restriction, 'لا يمكن إسناد دور غير مفعّل.'),
            'role_carries_restricted_permission' => AccountAdministrationException::forbidden($restriction, 'هذا الدور يحمل صلاحية إدارة حسابات أو صلاحيات، وإسناده محصور بمدير النظام.'),
            default => AccountAdministrationException::forbidden($restriction, 'هذا الدور ليس ضمن الأدوار المسموح للفريق التقني بإسنادها أو سحبها.'),
        };
    }

    /** Refuse any change that would leave no active account holding an active super_admin role. */
    private function assertNotLastSuperAdmin(User $target): void
    {
        $holders = UserRole::query()
            ->where('user_roles.is_active', true)
            ->whereHas('role', fn ($q) => $q->where('role_code', AccountAdministration::ROLE_SUPER_ADMIN)->where('is_active', true))
            ->whereHas('user.accountStatus', fn ($q) => $q->where('status_code', 'active'))
            ->lockForUpdate()
            ->pluck('user_id')
            ->unique();

        if ($holders->contains($target->user_id) && $holders->count() <= 1) {
            throw AccountAdministrationException::forbidden('last_super_admin', 'لا يمكن تعطيل آخر مدير نظام نشط أو سحب دوره.');
        }
    }

    private function status(string $code): AccountStatus
    {
        if (! in_array($code, AccountAdministration::STATUSES, true)) {
            throw ValidationException::withMessages(['account_status' => ['حالة الحساب غير مسموحة.']]);
        }
        $status = AccountStatus::query()->where('status_code', $code)->first();
        if ($status === null) {
            throw ValidationException::withMessages(['account_status' => ['حالة الحساب غير معرّفة في قاعدة البيانات.']]);
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function summary(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->accountStatus ? ['code' => $user->accountStatus->status_code, 'name' => $user->accountStatus->status_name] : null,
            'roles' => $user->userRoleRecords->where('is_active', true)->map(fn (UserRole $row) => [
                'role_id' => $row->role_id,
                'role_code' => $row->role?->role_code,
                'role_name' => $row->role?->role_name,
            ])->values()->all(),
            'student_id' => $user->student_id,
            'employee_id' => $user->employee_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function audit(User $actor, string $action, ?string $ip, array $details): void
    {
        UserActivityLog::query()->create([
            'user_id' => $actor->user_id,
            'module_code' => AccountAdministration::MODULE_CODE,
            'action_code' => $action,
            'description' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    /** @return array<string, array<int, string>> */
    private function duplicateFieldErrors(array $data): array
    {
        $errors = [];
        if (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower((string) ($data['username'] ?? ''))])->exists()) {
            $errors['username'] = ['اسم المستخدم مستخدم مسبقًا.'];
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) ($data['email'] ?? ''))])->exists()) {
            $errors['email'] = ['البريد الإلكتروني مستخدم مسبقًا.'];
        }

        return $errors ?: ['username' => ['تعذّر حفظ الحساب بسبب تعارض في البيانات.']];
    }
}
