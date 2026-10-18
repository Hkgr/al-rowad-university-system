<?php

namespace App\Policies;

use App\Models\CourseOffering;
use App\Models\User;
use App\Services\DataScopeService;

class CourseOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('courses.view');
    }

    public function view(User $user, CourseOffering $offering): bool
    {
        return $user->hasPermission('courses.view')
            && app(DataScopeService::class)->canAccessOffering($user, $offering);
    }

    public function create(User $user): bool { return $user->hasPermission('courses.manage'); }
    public function update(User $user, CourseOffering $offering): bool
    {
        return $user->hasPermission('courses.manage')
            && app(DataScopeService::class)->canAccessOffering($user, $offering);
    }

    public function delete(User $user, CourseOffering $offering): bool { return $this->update($user, $offering); }

    public function viewRoster(User $user, CourseOffering $offering): bool
    {
        return $user->hasPermission('students.view')
            && app(DataScopeService::class)->canAccessOffering($user, $offering)
            && ($user->hasPermission('courses.view')
                || $user->hasPermission('courses.manage')
                || $user->hasPermission('registration.view')
                || $user->hasPermission('grades.view')
                || $user->hasPermission('grades.manage')
                || $user->hasPermission('exams.manage'));
    }

}
