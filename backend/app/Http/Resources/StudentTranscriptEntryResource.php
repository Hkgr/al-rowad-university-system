<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class StudentTranscriptEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_course_registration_id' => $this->student_course_registration_id,
            'registration_date' => $this->registration_date,
            'course_code' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_code),
            'course_name' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_name),
            'credit_hours' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->credit_hours),
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
