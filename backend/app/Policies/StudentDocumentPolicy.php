<?php

namespace App\Policies;

use App\Models\StudentDocument;
use App\Models\Student;
use App\Models\User;

class StudentDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->effectiveRoles()->contains('student')
            || $user->hasPermission('students.view');
    }

    public function view(User $user, StudentDocument $document): bool
    {
        if ($user->effectiveRoles()->contains('student')) {
            return (int) $user->student_id === (int) $document->student_id;
        }

        return $user->hasPermission('students.view');
    }
    public function create(User $user): bool { return $this->manage($user); }
    public function createFor(User $user, Student $student): bool
    {
        return $this->manage($user)
            || ($user->effectiveRoles()->contains('student')
                && (int) $user->student_id === (int) $student->student_id);
    }
    public function update(User $user, StudentDocument $document): bool { return $this->manage($user); }
    public function delete(User $user, StudentDocument $document): bool { return $this->manage($user); }
    private function manage(User $user): bool
    {
        return $user->hasPermission('students.manage')
            || $user->effectiveRoles()->contains('registration_officer');
    }
}
