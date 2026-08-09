<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\DataScopeService;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->effectiveRoles()->contains('student') && $user->hasPermission('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->effectiveRoles()->contains('student')) {
            return (int) $user->student_id === (int) $student->student_id;
        }

        return $user->hasPermission('students.view')
            && app(DataScopeService::class)->canAccessStudent($user, $student);
    }

    public function create(User $user): bool { return $this->manage($user); }
    public function update(User $user, Student $student): bool { return $this->manage($user) && app(DataScopeService::class)->canAccessStudent($user, $student); }
    public function delete(User $user, Student $student): bool { return $this->update($user, $student); }
    public function restore(User $user, Student $student): bool { return $this->update($user, $student); }
    public function forceDelete(User $user, Student $student): bool { return $this->update($user, $student); }

    private function manage(User $user): bool
    {
        return $user->hasPermission('students.manage')
            || $user->effectiveRoles()->contains('registration_officer');
    }
}
