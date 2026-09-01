<?php

namespace App\Http\Resources;

use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class RegistrationSummaryItemResource extends JsonResource
{
    public static function collection($resource)
    {
        $models = CourseRequirementClassification::modelsFromResource($resource);
        CourseRequirementClassification::attachStudentProgramCourses($models);

        return tap(new AnonymousResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    public function toArray(Request $request): array
    {
        $programId = $this->relationLoaded('student') && $this->student?->academic_program_id !== null
            ? (int) $this->student->academic_program_id
            : null;
        $courseId = $this->courseOffering?->course_id ?? $this->courseOffering?->course?->course_id;
        $courseId = $courseId === null ? null : (int) $courseId;
        $programCourse = $this->relationLoaded('studentProgramCourse')
            ? $this->getRelation('studentProgramCourse')
            : null;

        return [
            'registration_id' => $this->student_course_registration_id,
            'course_code' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_code),
            'course_name' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_name),
            'credit_hours' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->credit_hours),
            'course_offering_id' => $this->course_offering_id,
            'requirement_classification' => CourseRequirementClassification::forStudent($programId, $courseId, $programCourse),
            'offering_status' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->status),
            'official_timetable' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->official_timetable),
            'registration_status' => RegistrationStatusResource::make($this->whenLoaded('registrationStatus')),
            'registration_date' => $this->registration_date,
            'academic_year' => AcademicYearResource::make($this->whenLoaded(
                'courseOffering',
                fn () => $this->courseOffering?->relationLoaded('academicYear') ? $this->courseOffering->academicYear : null
            )),
            'semester' => SemesterResource::make($this->whenLoaded(
                'courseOffering',
                fn () => $this->courseOffering?->relationLoaded('semester') ? $this->courseOffering->semester : null
            )),
        ];
    }
}
