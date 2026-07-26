<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

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

        return $user->hasPermission('students.view');
    }

    public function create(User $user): bool { return $this->manage($user); }
    public function update(User $user, Student $student): bool { return $this->manage($user); }
    public function delete(User $user, Student $student): bool { return $this->manage($user); }
    public function restore(User $user, Student $student): bool { return $this->manage($user); }
    public function forceDelete(User $user, Student $student): bool { return $this->manage($user); }

    private function manage(User $user): bool
    {
        return $user->hasPermission('students.manage')
            || $user->effectiveRoles()->contains('registration_officer');
    }
}
