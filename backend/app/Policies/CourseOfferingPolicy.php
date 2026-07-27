<?php

namespace App\Policies;

use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicAuthorizationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CourseOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->effectiveRoles()->intersect(['student', 'doctor_instructor'])->isNotEmpty()
            && $user->hasPermission('courses.view');
    }

    public function view(User $user, CourseOffering $offering): bool
    {
        if ($user->effectiveRoles()->contains('student')) {
            return false;
        }

        if ($user->effectiveRoles()->contains('doctor_instructor')) {
            return $this->isAssignedInstructor($user, $offering);
        }

        return $user->hasPermission('courses.view');
    }

    public function create(User $user): bool { return $user->hasPermission('courses.manage'); }
    public function update(User $user, CourseOffering $offering): bool { return $user->hasPermission('courses.manage'); }
    public function delete(User $user, CourseOffering $offering): bool { return $user->hasPermission('courses.manage'); }

    public function viewRoster(User $user, CourseOffering $offering): bool
    {
        if ($user->effectiveRoles()->contains('student')) {
            return false;
        }

        if ($user->effectiveRoles()->contains('doctor_instructor')) {
            return $this->isAssignedInstructor($user, $offering);
        }

        return $user->hasPermission('students.view')
            && ($user->hasPermission('courses.view')
                || $user->hasPermission('courses.manage')
                || $user->hasPermission('registration.view')
                || $user->hasPermission('grades.view')
                || $user->hasPermission('grades.manage')
                || $user->hasPermission('exams.manage'));
    }

    private function isAssignedInstructor(User $user, CourseOffering $offering): bool
    {
        try {
            app(AcademicAuthorizationService::class)->assertCanAccessOffering($user, $offering->course_offering_id);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }
}
