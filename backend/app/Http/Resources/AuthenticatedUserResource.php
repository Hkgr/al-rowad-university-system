<?php

namespace App\Http\Resources;

use App\Models\FacultyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing([
            'accountStatus',
            'roles.permissions',
            'employee.facultyMembers',
        ]);

        /** @var FacultyMember|null $facultyMember */
        $facultyMember = $this->employee?->facultyMembers
            ->first(fn (FacultyMember $member): bool => $member->is_active);

        return [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'email' => $this->email,
            'account_status' => $this->accountStatus ? [
                'code' => $this->accountStatus->status_code,
                'name' => $this->accountStatus->status_name,
            ] : null,
            'student_id' => $this->student_id,
            'employee_id' => $this->employee_id,
            'faculty_member_id' => $facultyMember?->faculty_member_id,
            'board_member_id' => $this->board_member_id,
            'last_login_at' => $this->last_login_at,
            'roles' => $this->roles
                ->map(fn ($role): array => [
                    'code' => $role->role_code,
                    'name' => $role->role_name,
                ])
                ->values(),
            'role_codes' => $this->roleCodes(),
            'permissions' => $this->permissionCodes(),
            'dashboards' => $this->accessibleDashboards(),
            'default_dashboard' => $this->defaultDashboardPath(),
        ];
    }
}
