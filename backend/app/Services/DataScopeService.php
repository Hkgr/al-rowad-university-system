<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DataScopeService
{
    public function scopes(User $user): array
    {
        return $user->accessScopes()->where('is_active', true)
            ->get(['scope_type', 'scope_id'])->map(fn ($scope) => [
                'type' => $scope->scope_type,
                'id' => (int) $scope->scope_id,
            ])->values()->all();
    }

    public function scopeStudents(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        if ($user->student_id !== null) return $query->whereKey($user->student_id);

        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;

        return $query->where(function (Builder $q) use ($scopes): void {
            $q->whereIn('academic_program_id', $scopes['program'])
                ->orWhereHas('academicProgram', function (Builder $program) use ($scopes): void {
                    $program->whereIn('department_id', $scopes['department'])
                        ->orWhereHas('department', fn (Builder $department) =>
                            $department->whereIn('college_id', $scopes['college']));
                })
                ->orWhereHas('studentCourseRegistrations', fn (Builder $registration) =>
                    $registration->whereIn('course_offering_id', $scopes['section']));
        });
    }

    public function canAccessStudent(User $user, Student $student): bool
    {
        return $this->scopeStudents(Student::query(), $user)->whereKey($student->student_id)->exists();
    }

    public function canAccessOffering(User $user, CourseOffering $offering): bool
    {
        if ($this->bypassesScope($user)) return true;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return true;
        if (in_array((int) $offering->course_offering_id, $scopes['section'], true)) return true;
        if (in_array((int) $offering->academic_program_id, $scopes['program'], true)) return true;
        if (in_array((int) $offering->department_id, $scopes['department'], true)) return true;

        return in_array((int) $offering->department?->college_id, $scopes['college'], true);
    }

    private function grouped(User $user): array
    {
        $result = array_fill_keys(['university', 'college', 'department', 'program', 'section'], []);
        foreach ($this->scopes($user) as $scope) $result[$scope['type']][] = $scope['id'];
        return $result;
    }

    private function bypassesScope(User $user): bool
    {
        return $user->effectiveRoles()->contains('super_admin');
    }
}
