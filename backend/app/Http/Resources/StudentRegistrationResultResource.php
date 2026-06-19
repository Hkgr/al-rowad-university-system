<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentRegistrationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $registration = $this->resource['registration'];

        return [
            'registration' => StudentCourseRegistrationResource::make($registration),
            'student' => StudentResource::make($registration->relationLoaded('student') ? $registration->student : null),
            'course_offering' => CourseOfferingResource::make($registration->relationLoaded('courseOffering') ? $registration->courseOffering : null),
            'course' => CourseResource::make(
                $registration->relationLoaded('courseOffering') && $registration->courseOffering?->relationLoaded('course')
                    ? $registration->courseOffering->course
                    : null
            ),
            'registered_hours' => $this->resource['registered_hours'],
            'max_allowed_hours' => $this->resource['max_allowed_hours'],
            'remaining_hours' => $this->resource['remaining_hours'],
            'available_seats' => $this->resource['available_seats'],
        ];
    }
}
