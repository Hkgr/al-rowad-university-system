<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\DataScopeService;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) && $user->hasPermission('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        if (! $user->hasPermission('students.view')) {
            return false;
        }

        return $user->student_id === $student->student_id
            || app(DataScopeService::class)->canStaffAccessStudent($user, $student);
    }

    public function create(User $user): bool { return $this->manage($user); }
    public function update(User $user, Student $student): bool { return $this->manage($user) && app(DataScopeService::class)->canStaffAccessStudent($user, $student); }
    public function delete(User $user, Student $student): bool { return $this->update($user, $student); }
    public function restore(User $user, Student $student): bool { return $this->update($user, $student); }
    public function forceDelete(User $user, Student $student): bool { return $this->update($user, $student); }

    private function manage(User $user): bool
    {
        return $this->isStaff($user) && $user->hasPermission('students.manage');
    }

    private function isStaff(User $user): bool
    {
        return $user->employee_id !== null || $user->effectiveRoles()->contains('super_admin');
    }
}
