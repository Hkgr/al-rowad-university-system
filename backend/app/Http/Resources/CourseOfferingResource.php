<?php

namespace App\Http\Resources;

use App\Services\CourseOfferingInstructorCoverageService;
use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class CourseOfferingResource extends JsonResource
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
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'department_id' => $this->department_id,
            'academic_program_id' => $this->academic_program_id,
            'faculty_member_id' => $this->faculty_member_id,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'status' => $this->status,
            'requirement_classification' => CourseRequirementClassification::forOffering($this->resource),
            'instructor_coverage' => $this->when(
                CourseOfferingInstructorCoverageService::relationsLoadedForDescription($this->resource),
                fn () => app(CourseOfferingInstructorCoverageService::class)->describe($this->resource)
            ),
            'course' => CourseResource::make($this->whenLoaded('course')),
            'academic_year' => AcademicYearResource::make($this->whenLoaded('academicYear')),
            'semester' => SemesterResource::make($this->whenLoaded('semester')),
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'academic_program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'college' => $this->loadedCollege(),
            'faculty_member' => FacultyMemberResource::make($this->whenLoaded('facultyMember')),
            'registered_students_count' => $this->student_course_registrations_count ?? null,
            'legacy_context_incomplete' => $this->academic_program_id === null || $this->department_id === null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function loadedCollege(): mixed
    {
        $fromProgram = null;
        if ($this->relationLoaded('academicProgram') && $this->academicProgram) {
            $program = $this->academicProgram;
            if ($program->relationLoaded('department') && $program->department?->relationLoaded('college')) {
                $fromProgram = $program->department->college;
            }
        }

        $fromDepartment = null;
        if ($this->relationLoaded('department') && $this->department?->relationLoaded('college')) {
            $fromDepartment = $this->department->college;
        }

        if ($fromProgram && $fromDepartment && (int) $fromProgram->college_id !== (int) $fromDepartment->college_id) {
            return null;
        }

        $college = $fromProgram ?? $fromDepartment;

        return $college ? CollegeResource::make($college) : null;
    }
}
