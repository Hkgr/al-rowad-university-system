<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class CourseOffering extends Model
{
    protected $table = 'course_offerings';

    protected $primaryKey = 'course_offering_id';

    protected $fillable = [
        'course_id',
        'academic_year_id',
        'semester_id',
        'department_id',
        'academic_program_id',
        'faculty_member_id',
        'capacity',
        'available_seats',
        'status',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }

    public function offeringInstructors(): HasMany
    {
        return $this->hasMany(CourseOfferingInstructor::class, 'course_offering_id', 'course_offering_id');
    }

    public function teachingAssignmentRequests(): HasMany
    {
        return $this->hasMany(TeachingAssignmentRequest::class, 'course_offering_id', 'course_offering_id');
    }

    public function exceptionRequests(): HasMany
    {
        return $this->hasMany(CourseOfferingExceptionRequest::class, 'course_offering_id', 'course_offering_id');
    }

    public function currentExceptionRequest(): HasOne
    {
        return $this->hasOne(CourseOfferingExceptionRequest::class, 'course_offering_id', 'course_offering_id')
            ->where('current_slot', 1);
    }

    public function closureRequests(): HasMany
    {
        return $this->hasMany(CourseOfferingClosureRequest::class, 'course_offering_id', 'course_offering_id');
    }

    public function currentClosureRequest(): HasOne
    {
        return $this->hasOne(CourseOfferingClosureRequest::class, 'course_offering_id', 'course_offering_id')
            ->where('current_slot', 1);
    }

    public function semesterOfferingRequest(): HasOne
    {
        return $this->hasOne(SemesterOfferingRequest::class, 'course_offering_id', 'course_offering_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'course_offering_id', 'course_offering_id');
    }

    public function gradeApprovals(): HasMany
    {
        return $this->hasMany(GradeApproval::class, 'course_offering_id', 'course_offering_id');
    }

    public function gradePartApprovals(): HasMany
    {
        return $this->hasMany(GradePartApproval::class, 'course_offering_id', 'course_offering_id');
    }

    public function gradeComponents(): HasMany
    {
        return $this->hasMany(GradeComponent::class, 'course_offering_id', 'course_offering_id');
    }

    public function studentCourseRegistrations(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'course_offering_id', 'course_offering_id');
    }

    public function resolveCollege(): ?College
    {
        $this->loadMissing(['department.college', 'academicProgram.department.college']);

        $departmentCollege = $this->department_id !== null
            ? $this->department?->college
            : null;
        $programCollege = $this->academic_program_id !== null
            ? $this->academicProgram?->department?->college
            : null;

        if ($departmentCollege && $programCollege) {
            return (int) $departmentCollege->college_id === (int) $programCollege->college_id
                ? $departmentCollege
                : null;
        }

        return $departmentCollege ?? $programCollege;
    }

    /**
     * Query-level equivalent of resolveCollege() for College-scoped access.
     *
     * Direct department College is used when present; otherwise fall back through
     * Academic Program → Department → College. Conflicting Colleges fail closed.
     */
    public static function idsResolvedToColleges(array $collegeIds)
    {
        $query = DB::table('course_offerings as accessible_offerings')
            ->leftJoin(
                'departments as offering_departments',
                'offering_departments.department_id',
                '=',
                'accessible_offerings.department_id'
            )
            ->leftJoin(
                'academic_programs as offering_programs',
                'offering_programs.academic_program_id',
                '=',
                'accessible_offerings.academic_program_id'
            )
            ->leftJoin(
                'departments as program_departments',
                'program_departments.department_id',
                '=',
                'offering_programs.department_id'
            )
            ->select('accessible_offerings.course_offering_id');

        $collegeIds = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $collegeIds
        )));

        if ($collegeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            DB::raw('CASE
                WHEN offering_departments.college_id IS NOT NULL
                 AND program_departments.college_id IS NOT NULL
                 AND offering_departments.college_id <> program_departments.college_id THEN NULL
                ELSE COALESCE(offering_departments.college_id, program_departments.college_id)
            END'),
            $collegeIds
        );
    }

}
