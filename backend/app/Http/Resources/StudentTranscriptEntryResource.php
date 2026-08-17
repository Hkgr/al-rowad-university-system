<?php

namespace App\Http\Resources;

use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class StudentTranscriptEntryResource extends JsonResource
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

        return [
            'student_course_registration_id' => $this->student_course_registration_id,
            'registration_date' => $this->registration_date,
            'course_code' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_code),
            'course_name' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_name),
            'credit_hours' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->credit_hours),
            'requirement_classification' => CourseRequirementClassification::forStudent(
                $programId,
                $courseId,
                $this->relationLoaded('studentProgramCourse') ? $this->getRelation('studentProgramCourse') : null
            ),
            'academic_year' => AcademicYearResource::make($this->whenLoaded(
                'courseOffering',
                fn () => $this->courseOffering?->relationLoaded('academicYear') ? $this->courseOffering->academicYear : null
            )),
            'semester' => SemesterResource::make($this->whenLoaded(
                'courseOffering',
                fn () => $this->courseOffering?->relationLoaded('semester') ? $this->courseOffering->semester : null
            )),
            'theoretical_total' => $this->whenLoaded('studentCourseResult', fn () => $this->studentCourseResult?->theoretical_total),
            'practical_total' => $this->whenLoaded('studentCourseResult', fn () => $this->studentCourseResult?->practical_total),
            'coursework_total' => $this->whenLoaded('studentCourseResult', fn () => $this->studentCourseResult?->coursework_total),
            'final_mark' => $this->whenLoaded('studentCourseResult', fn () => $this->studentCourseResult?->final_mark),
            'result_status' => ResultStatusResource::make($this->when(
                $this->relationLoaded('studentCourseResult') && $this->studentCourseResult?->relationLoaded('resultStatus'),
                $this->studentCourseResult?->resultStatus
            )),
        ];
    }
}
