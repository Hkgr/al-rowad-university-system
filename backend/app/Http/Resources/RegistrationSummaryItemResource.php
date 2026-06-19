<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class RegistrationSummaryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'registration_id' => $this->student_course_registration_id,
            'course_code' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_code),
            'course_name' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->course_name),
            'credit_hours' => $this->whenLoaded('courseOffering', fn () => $this->courseOffering?->course?->credit_hours),
            'course_offering_id' => $this->course_offering_id,
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
