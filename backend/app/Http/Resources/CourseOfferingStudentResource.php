<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class CourseOfferingStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'student_number' => $this->relationLoaded('student') ? $this->student?->student_number : null,
            'full_name' => $this->relationLoaded('student') ? trim(($this->student?->first_name ?? '').' '.($this->student?->last_name ?? '')) : null,
            'registration_status' => $this->relationLoaded('registrationStatus') ? $this->registrationStatus?->status_name : null,
            'registration_date' => $this->registration_date,
        ];
    }
}