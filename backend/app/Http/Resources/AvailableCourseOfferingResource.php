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
            'course' => CourseResource::make($this->whenLoaded('course')),
            'academic_year' => AcademicYearResource::make($this->whenLoaded('academicYear')),
            'semester' => SemesterResource::make($this->whenLoaded('semester')),
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'faculty_member' => FacultyMemberResource::make($this->whenLoaded('facultyMember')),
            'eligibility_status' => $this->eligibility_status,
            'eligibility_reasons' => $this->eligibility_reasons ?? [],
            'missing_prerequisites' => $this->missing_prerequisites ?? [],
        ];
    }
}
