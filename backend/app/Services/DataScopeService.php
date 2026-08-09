<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\FacultyMember;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use App\Models\AcademicProgram;
use App\Models\Department;
use Illuminate\Support\Facades\Schema;
use App\Models\OrganizationalUnit;

class DataScopeService
{
    public function scopes(User $user): array
    {
        return $user->accessScopes()->where('is_active', true)->get(['scope_type', 'scope_id'])
            ->filter(fn ($scope) => $this->scopeReferenceExists($scope->scope_type, (int) $scope->scope_id))
            ->map(fn ($scope) => ['type' => $scope->scope_type, 'id' => (int) $scope->scope_id])
            ->values()->all();
    }

    public function scopeStudents(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);

        return $query->where(function (Builder $q) use ($user, $scopes): void {
            if ($user->student_id !== null) $q->orWhereKey($user->student_id);
            if ($scopes['university'] !== []) $q->orWhereRaw('1 = 1');
            $q->orWhereIn('academic_program_id', $scopes['program'])
                ->orWhereHas('academicProgram', fn (Builder $program) => $program
                    ->whereIn('department_id', $scopes['department'])
                    ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college'])))
                ->orWhereHas('studentCourseRegistrations', fn (Builder $registration) =>
                    $registration->whereIn('course_offering_id', $scopes['section']));
        });
    }

    public function scopeOfferings(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        $facultyIds = FacultyMember::query()->where('employee_id', $user->employee_id)->pluck('faculty_member_id');

        return $query->where(function (Builder $q) use ($scopes, $facultyIds): void {
            if ($scopes['university'] !== []) $q->orWhereRaw('1 = 1');
            $q->orWhereIn('course_offering_id', $scopes['section'])
                ->orWhereIn('academic_program_id', $scopes['program'])
                ->orWhereIn('department_id', $scopes['department'])
                ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college']))
                ->orWhereIn('faculty_member_id', $facultyIds)
                ->orWhereHas('offeringInstructors', fn (Builder $instructor) =>
                    $instructor->whereIn('faculty_member_id', $facultyIds)->where('is_active', true));
        });
    }

    public function scopeRegistrations(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        return $query->where(function (Builder $paths) use ($user): void {
            if ($user->student_id !== null) $paths->orWhere('student_id', $user->student_id);
            $paths->orWhere(fn (Builder $staff) => $staff
                ->whereHas('student', fn (Builder $student) => $this->scopeStudents($student, $user))
                ->whereHas('courseOffering', fn (Builder $offering) => $this->scopeOfferings($offering, $user)));
        });
    }

    public function scopeResourceQuery(Builder $query, User $user): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();
        if (in_array($table, ['student_academic_terms', 'student_credit_limits', 'student_documents', 'student_attendance', 'grade_appeals'], true)
            && Schema::hasColumn($table, 'student_id')) {
            return $query->whereHas('student', fn (Builder $student) => $this->scopeStudents($student, $user));
        }
        if (in_array($table, ['grade_approvals', 'grade_components', 'attendance_sessions'], true)
            && Schema::hasColumn($table, 'course_offering_id')) {
            return $query->whereHas('courseOffering', fn (Builder $offering) => $this->scopeOfferings($offering, $user));
        }
        if (in_array($table, ['student_course_results', 'student_grade_components', 'supplementary_exam_results'], true)
            && Schema::hasColumn($table, 'student_course_registration_id')) {
            return $query->whereHas('studentCourseRegistration', fn (Builder $registration) => $this->scopeRegistrations($registration, $user));
        }
        return $query;
    }

    public function assertPayloadScope(User $user, array $data): void
    {
        if (array_key_exists('student_id', $data)) {
            $student = Student::query()->findOrFail($data['student_id']);
            abort_unless($this->canAccessStudent($user, $student), 403);
        }
        if (array_key_exists('course_offering_id', $data)) {
            $offering = CourseOffering::query()->findOrFail($data['course_offering_id']);
            abort_unless($this->canAccessOffering($user, $offering), 403);
        }
    }

    public function canAccessStudent(User $user, Student $student): bool
    {
        return $this->scopeStudents(Student::query(), $user)->whereKey($student->student_id)->exists();
    }

    public function canAccessOffering(User $user, CourseOffering $offering): bool
    {
        return $this->scopeOfferings(CourseOffering::query(), $user)->whereKey($offering->course_offering_id)->exists();
    }

    public function canAccessProgram(User $user, int $programId): bool
    {
        if ($this->bypassesScope($user)) return true;
        $program = AcademicProgram::query()->with('department')->find($programId);
        if (! $program) return false;
        $scopes = $this->grouped($user);
        return $scopes['university'] !== []
            || in_array($programId, $scopes['program'], true)
            || in_array((int) $program->department_id, $scopes['department'], true)
            || in_array((int) $program->department?->college_id, $scopes['college'], true);
    }

    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if ($this->bypassesScope($user)) return true;
        $department = Department::query()->find($departmentId);
        if (! $department) return false;
        $scopes = $this->grouped($user);
        return $scopes['university'] !== []
            || in_array($departmentId, $scopes['department'], true)
            || in_array((int) $department->college_id, $scopes['college'], true);
    }

    public function scopeColleges(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;
        return $query->whereIn('college_id', $scopes['college'])
            ->orWhereHas('departments', fn (Builder $department) => $department
                ->whereIn('department_id', $scopes['department'])
                ->orWhereHas('academicPrograms', fn (Builder $program) => $program->whereIn('academic_program_id', $scopes['program'])));
    }

    public function scopeDepartments(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;
        return $query->whereIn('college_id', $scopes['college'])->orWhereIn('department_id', $scopes['department'])
            ->orWhereHas('academicPrograms', fn (Builder $program) => $program->whereIn('academic_program_id', $scopes['program']));
    }

    public function scopePrograms(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;
        return $query->whereIn('academic_program_id', $scopes['program'])->orWhereIn('department_id', $scopes['department'])
            ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college']));
    }

    private function grouped(User $user): array
    {
        $result = array_fill_keys(['university', 'college', 'department', 'program', 'section'], []);
        foreach ($this->scopes($user) as $scope) $result[$scope['type']][] = $scope['id'];
        return $result;
    }

    private function scopeReferenceExists(string $type, int $id): bool
    {
        return match ($type) {
            'university' => OrganizationalUnit::query()->whereKey($id)
                ->whereHas('organizationalUnitType', fn (Builder $type) => $type->where('type_code', 'university'))->exists(),
            'college' => \App\Models\College::query()->whereKey($id)->exists(),
            'department' => Department::query()->whereKey($id)->exists(),
            'program' => AcademicProgram::query()->whereKey($id)->exists(),
            'section' => CourseOffering::query()->whereKey($id)->exists(),
            default => false,
        };
    }

    private function bypassesScope(User $user): bool
    {
        return $user->effectiveRoles()->contains('super_admin');
    }
}
