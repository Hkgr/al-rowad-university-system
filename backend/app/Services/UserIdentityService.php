<?php

namespace App\Services;

use App\Models\College;
use App\Models\User;

class UserIdentityService
{
    public function __construct(private readonly DataScopeService $dataScopes) {}

    /**
     * Trusted, presentation-safe identity for generated documents.
     * Internal identifiers and contact data are intentionally excluded.
     *
     * @return array<string, mixed>
     */
    public function documentGenerator(User $user): array
    {
        $user->loadMissing('employee.organizationalUnit');
        $employeeName = trim(implode(' ', array_filter([
            $user->employee?->first_name,
            $user->employee?->last_name,
        ], fn ($part): bool => is_string($part) && trim($part) !== '')));

        return [
            'display_name' => $employeeName !== '' ? $employeeName : $user->username,
            'username' => $user->username,
            'organizational_unit' => $user->employee?->organizationalUnit ? [
                'code' => $user->employee->organizationalUnit->unit_code,
                'name' => $user->employee->organizationalUnit->unit_name,
            ] : null,
        ];
    }

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
            'college' => $this->singleAccessibleCollege($user),
            'board_member_id' => $user->board_member_id,
            'roles' => $user->effectiveRoles()->all(),
            'permissions' => $user->effectivePermissions()->all(),
        ];
    }

    /**
     * Fail closed: expose a College identity only when the user has exactly one
     * accessible College. Missing, empty, or multiple Colleges stay null.
     */
    private function singleAccessibleCollege(User $user): ?array
    {
        $collegeIds = array_values(array_unique($this->dataScopes->accessibleCollegeIds($user)));
        if (count($collegeIds) !== 1) {
            return null;
        }

        $collegeId = $collegeIds[0];
        if ($collegeId <= 0) {
            return null;
        }

        $college = College::query()->find($collegeId);
        if ($college === null) {
            return null;
        }

        $name = trim((string) $college->college_name);
        if ($name === '') {
            return null;
        }

        return [
            'college_id' => (int) $college->college_id,
            'college_name' => $name,
        ];
    }
}
