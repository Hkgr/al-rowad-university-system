<?php

namespace App\Services;

use App\Models\User;

class UserIdentityService
{
    public function payload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'student_id' => $user->student_id,
            'employee_id' => $user->employee_id,
            'board_member_id' => $user->board_member_id,
            'roles' => $user->effectiveRoles()->all(),
            'permissions' => $user->effectivePermissions()->all(),
        ];
    }
}
