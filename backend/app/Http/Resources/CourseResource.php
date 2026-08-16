<?php

namespace App\Http\Resources;

use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    public static function collection($resource)
    {
        CourseRequirementClassification::hydrateCourses(
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
            'course_id' => $this->course_id,
            'course_code' => $this->course_code,
            'course_name' => $this->course_name,
            'credit_hours' => $this->credit_hours,
            'theoretical_hours' => $this->theoretical_hours,
            'practical_hours' => $this->practical_hours,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'program_requirement_classifications' => $this->when(
                $this->relationLoaded('programCourses'),
                CourseRequirementClassification::programClassificationsForCourse($this->resource)
            ),
            'departments' => $this->relationLoaded('departments') ? DepartmentResource::collection($this->departments) : null,
            'academic_programs' => $this->relationLoaded('academicPrograms') ? AcademicProgramResource::collection($this->academicPrograms) : null,
            'prerequisites' => $this->relationLoaded('prerequisites') ? CourseResource::collection($this->prerequisites) : null,
            'course_offerings' => $this->relationLoaded('courseOfferings') ? CourseOfferingResource::collection($this->courseOfferings) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
