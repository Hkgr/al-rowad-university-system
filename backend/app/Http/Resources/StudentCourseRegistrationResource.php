<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class StudentCourseRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_course_registration_id' => $this->student_course_registration_id,
            'student_id' => $this->student_id,
            'course_offering_id' => $this->course_offering_id,
            'registration_date' => $this->registration_date,
            'registered_by_user_id' => $this->registered_by_user_id,
            'advisor_user_id' => $this->advisor_user_id,
            'registration_status_id' => $this->registration_status_id,
            'result_status_id' => $this->result_status_id,
            'notes' => $this->notes,
            'student' => StudentResource::make($this->whenLoaded('student')),
            'course_offering' => CourseOfferingResource::make($this->whenLoaded('courseOffering')),
            'registration_status' => RegistrationStatusResource::make($this->whenLoaded('registrationStatus')),
            'result_status' => ResultStatusResource::make($this->whenLoaded('resultStatus')),
            'student_course_result' => StudentCourseResultResource::make($this->whenLoaded('studentCourseResult')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
