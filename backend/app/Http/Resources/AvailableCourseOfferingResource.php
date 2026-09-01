<?php

namespace App\Http\Resources;

use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class AvailableCourseOfferingResource extends JsonResource
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
        return [
            'course_offering_id' => $this->course_offering_id,
            'course_id' => $this->course_id,
            'course_code' => $this->whenLoaded('course', fn () => $this->course?->course_code),
            'course_name' => $this->whenLoaded('course', fn () => $this->course?->course_name),
            'credit_hours' => $this->whenLoaded('course', fn () => $this->course?->credit_hours),
            'status' => $this->status,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'requirement_classification' => CourseRequirementClassification::forStudentOffering($this->resource),
            'advisory_plan' => $this->advisoryPlan(),
            'course' => CourseResource::make($this->whenLoaded('course')),
            'academic_year' => AcademicYearResource::make($this->whenLoaded('academicYear')),
            'semester' => SemesterResource::make($this->whenLoaded('semester')),
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'faculty_member' => FacultyMemberResource::make($this->whenLoaded('facultyMember')),
            'eligibility_status' => $this->eligibility_status,
            'eligibility_reasons' => $this->eligibility_reasons ?? [],
            'missing_prerequisites' => $this->missing_prerequisites ?? [],
            'official_timetable' => $this->official_timetable,
            'timetable_conflicts' => $this->timetable_conflicts ?? [],
            'incomplete_timetable_sources' => $this->incomplete_timetable_sources ?? [],
        ];
    }

    /**
     * Presentational ProgramCourse metadata for the student's current program.
     * Does not affect eligibility.
     *
     * @return array<string, mixed>|null
     */
    private function advisoryPlan(): ?array
    {
        if (! $this->relationLoaded('studentProgramCourse')) {
            return null;
        }

        $programCourse = $this->getRelation('studentProgramCourse');
        if ($programCourse === null) {
            return null;
        }

        $level = $programCourse->relationLoaded('academicLevel') ? $programCourse->academicLevel : null;
        $semester = $programCourse->relationLoaded('recommendedSemester') ? $programCourse->recommendedSemester : null;

        return [
            'program_course_id' => $programCourse->program_course_id,
            'academic_level_id' => $programCourse->academic_level_id === null
                ? null
                : (int) $programCourse->academic_level_id,
            'academic_level_name' => $level?->level_name,
            'recommended_semester_id' => $programCourse->recommended_semester_id === null
                ? null
                : (int) $programCourse->recommended_semester_id,
            'recommended_semester_name' => $semester?->semester_name,
        ];
    }
}
