<?php

namespace App\Support;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Course;
use App\Models\Department;
use App\Models\ProgramCourse;
use App\Models\Semester;

final class CourseOfferingContext
{
    public function __construct(
        public readonly Course $course,
        public readonly ProgramCourse $programCourse,
        public readonly AcademicProgram $academicProgram,
        public readonly Department $department,
        public readonly College $college,
        public readonly AcademicYear $academicYear,
        public readonly Semester $semester,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function offeringAttributes(): array
    {
        return [
            'course_id' => (int) $this->course->course_id,
            'academic_program_id' => (int) $this->academicProgram->academic_program_id,
            'academic_year_id' => (int) $this->academicYear->academic_year_id,
            'semester_id' => (int) $this->semester->semester_id,
            'department_id' => (int) $this->department->department_id,
        ];
    }
}
