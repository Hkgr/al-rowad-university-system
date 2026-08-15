<?php

namespace App\Services;

use App\Models\College;
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
                ->orWhereIn('course_offering_id', CourseOffering::idsResolvedToColleges($scopes['college']))
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

    public function scopeRegistrationsForStaff(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;

        return $query->whereHas('student', fn (Builder $student) => $this->scopeStudentsForStaff($student, $user))
            ->whereHas('courseOffering', fn (Builder $offering) => $this->scopeOfferingsForStaff($offering, $user));
    }

    public function scopeResourceQuery(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;

        $model = $query->getModel();
        $table = $model->getTable();
        if ($table === 'academic_levels') return $query;
        if ($table === 'colleges') return $this->scopeColleges($query, $user);
        if ($table === 'departments') return $this->scopeDepartments($query, $user);
        if ($table === 'academic_programs') return $this->scopePrograms($query, $user);
        if ($table === 'students') return $this->scopeStudents($query, $user);
        if ($table === 'course_offerings') return $this->scopeOfferings($query, $user);
        if ($table === 'student_course_registrations') return $this->scopeRegistrations($query, $user);
        if ($table === 'courses') return $this->scopeCourses($query, $user);
        if ($table === 'faculty_members') return $this->scopeFacultyMembers($query, $user);
        if ($table === 'course_departments') {
            return $query->whereHas('department', fn (Builder $department) => $this->scopeDepartments($department, $user));
        }
        if ($table === 'program_courses') {
            return $query->whereHas('academicProgram', fn (Builder $program) => $this->scopePrograms($program, $user));
        }
        if (in_array($table, ['student_academic_terms', 'student_credit_limits', 'student_documents', 'student_attendance', 'grade_appeals', 'student_registration_requests'], true)
            && Schema::hasColumn($table, 'student_id')) {
            return $query->whereHas('student', fn (Builder $student) => $this->scopeStudents($student, $user));
        }
        if (in_array($table, ['student_registration_request_items', 'student_registration_request_events'], true)) {
            return $query->whereHas('request', fn (Builder $request) => $this->scopeResourceQuery($request, $user));
        }
        if (in_array($table, ['grade_approvals', 'grade_part_approvals', 'grade_components', 'attendance_sessions'], true)
            && Schema::hasColumn($table, 'course_offering_id')) {
            return $query->whereHas('courseOffering', fn (Builder $offering) => $this->scopeOfferings($offering, $user));
        }
        if (in_array($table, ['student_course_results', 'student_grade_components', 'supplementary_exam_results'], true)
            && Schema::hasColumn($table, 'student_course_registration_id')) {
            return $query->whereHas('studentCourseRegistration', fn (Builder $registration) => $this->scopeRegistrations($registration, $user));
        }
        // A newly-added or unsupported resource must never silently become global.
        return $query->whereRaw('1 = 0');
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
        return $this->scopeStudents(Student::withTrashed(), $user)->whereKey($student->student_id)->exists();
    }

    public function canAccessOffering(User $user, CourseOffering $offering): bool
    {
        return $this->scopeOfferings(CourseOffering::query(), $user)->whereKey($offering->course_offering_id)->exists();
    }

    public function canStaffAccessStudent(User $user, Student $student): bool
    {
        if ($this->bypassesScope($user)) return true;
        if ($user->employee_id === null) return false;

        return $this->scopeStudentsForStaff(Student::withTrashed(), $user)
            ->whereKey($student->student_id)->exists();
    }

    public function scopeStaffStudents(Builder $query, User $user): Builder
    {
        return $this->scopeStudentsForStaff($query, $user);
    }

    public function canStaffAccessOffering(User $user, CourseOffering $offering): bool
    {
        if ($this->bypassesScope($user)) return true;
        if ($user->employee_id === null) return false;

        return $this->scopeOfferingsForStaff(CourseOffering::query(), $user)
            ->whereKey($offering->course_offering_id)->exists();
    }

    public function canStaffManageRegistration(User $user, Student $student, CourseOffering $offering): bool
    {
        return $user->hasPermission('registration.manage')
            && $this->canStaffAccessStudent($user, $student)
            && $this->canAccessProgram($user, (int) $student->academic_program_id)
            && $this->canStaffAccessOffering($user, $offering);
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
        return $query->where(fn (Builder $college) => $college
            ->whereIn('college_id', $scopes['college'])
            ->orWhereHas('departments', fn (Builder $department) => $department
                ->whereIn('department_id', $scopes['department'])
                ->orWhereHas('academicPrograms', fn (Builder $program) => $program->whereIn('academic_program_id', $scopes['program']))));
    }

    public function scopeDepartments(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;
        return $query->where(fn (Builder $department) => $department
            ->whereIn('college_id', $scopes['college'])
            ->orWhereIn('department_id', $scopes['department'])
            ->orWhereHas('academicPrograms', fn (Builder $program) => $program->whereIn('academic_program_id', $scopes['program'])));
    }

    public function scopePrograms(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;
        return $query->where(fn (Builder $program) => $program
            ->whereIn('academic_program_id', $scopes['program'])
            ->orWhereIn('department_id', $scopes['department'])
            ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college'])));
    }

    public function scopeCourses(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;

        return $query->where(function (Builder $courses) use ($user): void {
            $courses->whereHas('departments', fn (Builder $department) => $this->scopeDepartments($department, $user))
                ->orWhereHas('academicPrograms', fn (Builder $program) => $this->scopePrograms($program, $user))
                ->orWhereHas('courseOfferings', fn (Builder $offering) => $this->scopeOfferings($offering, $user));
        });
    }

    public function scopeFacultyMembers(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) {
            return $query;
        }

        $unitIds = $this->accessibleCollegeOrganizationalUnitIds($user);
        if ($unitIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('employee', function (Builder $employee) use ($unitIds): void {
            $employee->where(function (Builder $membership) use ($unitIds): void {
                $membership->whereIn('organizational_unit_id', $unitIds)
                    ->orWhereHas('employeeUnitAssignments', function (Builder $assignment) use ($unitIds): void {
                        $assignment->whereIn('organizational_unit_id', $unitIds)
                            ->where('is_active', true);
                    });
            });
        });
    }

    public function canAccessFacultyMember(User $user, FacultyMember $facultyMember): bool
    {
        return $this->scopeFacultyMembers(FacultyMember::query(), $user)
            ->whereKey($facultyMember->faculty_member_id)
            ->exists();
    }

    public function facultyMemberBelongsToCollege(FacultyMember $facultyMember, College $college): bool
    {
        $unitId = $college->organizational_unit_id;
        if ($unitId === null) {
            return false;
        }

        $facultyMember->loadMissing('employee');
        $employee = $facultyMember->employee;
        if ($employee === null) {
            return false;
        }

        if ($employee->employeeUnitAssignments()
            ->where('organizational_unit_id', $unitId)
            ->where('is_active', true)
            ->exists()) {
            return true;
        }

        return $employee->organizational_unit_id !== null
            && (int) $employee->organizational_unit_id === (int) $unitId;
    }

    public function accessibleCollegeIds(User $user): array
    {
        return $this->scopeColleges(College::query(), $user)
            ->pluck('college_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function accessibleCollegeOrganizationalUnitIds(User $user): array
    {
        return College::query()
            ->whereIn('college_id', $this->accessibleCollegeIds($user))
            ->whereNotNull('organizational_unit_id')
            ->pluck('organizational_unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function scopeStudentsForStaff(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        if ($user->employee_id === null) return $query->whereRaw('1 = 0');
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;

        return $query->where(fn (Builder $student) => $student
            ->whereIn('academic_program_id', $scopes['program'])
            ->orWhereHas('academicProgram', fn (Builder $program) => $program
                ->whereIn('department_id', $scopes['department'])
                ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college'])))
            ->orWhereHas('studentCourseRegistrations', fn (Builder $registration) =>
                $registration->whereIn('course_offering_id', $scopes['section'])));
    }

    private function scopeOfferingsForStaff(Builder $query, User $user): Builder
    {
        if ($this->bypassesScope($user)) return $query;
        if ($user->employee_id === null) return $query->whereRaw('1 = 0');
        $scopes = $this->grouped($user);
        if ($scopes['university'] !== []) return $query;

        return $query->where(fn (Builder $offering) => $offering
            ->whereIn('course_offering_id', $scopes['section'])
            ->orWhereIn('academic_program_id', $scopes['program'])
            ->orWhereIn('department_id', $scopes['department'])
            ->orWhereHas('department', fn (Builder $department) => $department->whereIn('college_id', $scopes['college'])));
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
            // The schema has no universities table/type. PRES is the approved
            // organizational root representing the institution.
            'university' => OrganizationalUnit::query()->whereKey($id)->where('unit_code', 'PRES')->exists(),
            'college' => College::query()->whereKey($id)->exists(),
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
