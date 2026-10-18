<?php

namespace App\Policies;

use App\Models\StudentDocument;
use App\Models\Student;
use App\Models\User;
use App\Services\DataScopeService;

class StudentDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('students.view');
    }

    public function view(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('students.view')
            && app(DataScopeService::class)->canAccessStudent($user, $document->student);
    }
    public function create(User $user): bool { return $this->manage($user); }
    public function createFor(User $user, Student $student): bool
    {
        return ($this->manage($user) || (int) $user->student_id === (int) $student->student_id)
            && app(DataScopeService::class)->canAccessStudent($user, $student);
    }
    public function update(User $user, StudentDocument $document): bool { return $this->manage($user) && $this->view($user, $document); }
    public function delete(User $user, StudentDocument $document): bool { return $this->update($user, $document); }
    private function manage(User $user): bool
    {
        return $user->hasPermission('students.manage');
    }
}
