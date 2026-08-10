<?php

namespace App\Services;

use App\Models\User;

class UserIdentityService
{
    public function __construct(private readonly DataScopeService $dataScopes) {}

    public function payload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'student_id' => $user->student_id,
            'employee_id' => $user->employee_id,
            'organizational_unit' => $user->employee?->organizationalUnit ? [
                'id' => $user->employee->organizationalUnit->organizational_unit_id,
                'code' => $user->employee->organizationalUnit->unit_code,
                'name' => $user->employee->organizationalUnit->unit_name,
            ] : null,
            'access_scopes' => $this->dataScopes->scopes($user),
            'board_member_id' => $user->board_member_id,
            'roles' => $user->effectiveRoles()->all(),
            'permissions' => $user->effectivePermissions()->all(),
        ];
    }
}
