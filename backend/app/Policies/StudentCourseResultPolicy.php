<?php

namespace App\Policies;

use App\Models\StudentCourseResult;
use App\Models\User;
use App\Services\AcademicAuthorizationService;

class StudentCourseResultPolicy
{
    public function view(User $user, StudentCourseResult $result): bool
    {
        try {
            app(AcademicAuthorizationService::class)->assertCanViewGrades($user, $result->studentCourseRegistration);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            return false;
        }
    }

    public function create(User $user): bool { return false; }
    public function update(User $user, StudentCourseResult $result): bool { return false; }
    public function delete(User $user, StudentCourseResult $result): bool { return false; }
}
