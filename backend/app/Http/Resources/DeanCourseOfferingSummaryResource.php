<?php

namespace App\Http\Resources;

use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class DeanCourseOfferingSummaryResource extends JsonResource
{
    public static function collection($resource)
    {
        CourseRequirementClassification::hydrateOfferings(
            CourseRequirementClassification::modelsFromResource($resource)
        );

        return tap(new AnonymousResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    public function toArray(Request $request): array
    {
        $course = $this->course;
        $academicYear = $this->academicYear;
        $semester = $this->semester;
        $department = $this->department;
        $program = $this->academicProgram;
        $canViewGrades = $request->user()?->hasPermission('grades.view') ?? false;

        $slots = $this->relationLoaded('offeringInstructors')
            ? $this->offeringInstructors
                ->where('is_active', true)
                ->keyBy(fn (CourseOfferingInstructor $slot) => (string) $slot->instructor_role)
            : collect();

        return [
            'course_offering_id' => $this->course_offering_id,
            'status' => $this->status,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'course' => $course === null ? null : [
                'course_id' => $course->course_id,
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
                'theoretical_hours' => $course->theoretical_hours,
                'practical_hours' => $course->practical_hours,
                'credit_hours' => $course->credit_hours,
            ],
            'requirement_classification' => CourseRequirementClassification::forOffering($this->resource),
            'academic_year' => $academicYear === null ? null : [
                'academic_year_id' => $academicYear->academic_year_id,
                'year_name' => $academicYear->year_name,
            ],
            'semester' => $semester === null ? null : [
                'semester_id' => $semester->semester_id,
                'semester_name' => $semester->semester_name,
            ],
            'department' => $department === null ? null : [
                'department_id' => $department->department_id,
                'department_name' => $department->department_name,
            ],
            'academic_program' => $program === null ? null : [
                'academic_program_id' => $program->academic_program_id,
                'program_name' => $program->program_name,
            ],
            'teachers' => [
                'theoretical' => $this->teacherSlot(
                    (int) ($course?->theoretical_hours ?? 0) > 0,
                    $slots->get('theoretical')
                ),
                'practical' => $this->teacherSlot(
                    (int) ($course?->practical_hours ?? 0) > 0,
                    $slots->get('practical')
                ),
            ],
            'metrics' => [
                'registered_students_count' => (int) ($this->registered_students_count ?? 0),
                'attendance_sessions_count' => (int) ($this->attendance_sessions_count ?? 0),
                'theoretical_sessions_count' => (int) ($this->theoretical_sessions_count ?? 0),
                'practical_sessions_count' => (int) ($this->practical_sessions_count ?? 0),
                'average_final_mark' => $canViewGrades ? $this->nullableRoundedMark($this->average_final_mark ?? null) : null,
                'graded_students_count' => $canViewGrades ? (int) ($this->graded_students_count ?? 0) : null,
            ],
        ];
    }

    private function teacherSlot(bool $available, ?CourseOfferingInstructor $slot): array
    {
        if (! $available) {
            return [
                'available' => false,
                'faculty_member_id' => null,
                'full_name' => null,
                'academic_rank' => null,
            ];
        }

        $faculty = $slot?->facultyMember;

        return [
            'available' => true,
            'faculty_member_id' => $faculty?->faculty_member_id,
            'full_name' => $this->facultyFullName($faculty),
            'academic_rank' => $faculty?->academic_rank,
        ];
    }

    private function facultyFullName(?FacultyMember $facultyMember): ?string
    {
        if ($facultyMember === null) {
            return null;
        }

        $employee = $facultyMember->employee;
        $fullName = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }

    private function nullableRoundedMark(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
