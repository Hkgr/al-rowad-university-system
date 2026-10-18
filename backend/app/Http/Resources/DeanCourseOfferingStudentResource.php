<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class DeanCourseOfferingStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->student;
        $fullName = trim(($student?->first_name ?? '').' '.($student?->last_name ?? ''));
        $canViewGrades = $request->user()?->hasPermission('grades.view') ?? false;
        $result = $this->relationLoaded('studentCourseResult') ? $this->studentCourseResult : null;
        $resultStatus = $result?->resultStatus;

        $payload = [
            'student_course_registration_id' => $this->student_course_registration_id,
            'student_id' => $this->student_id,
            'student_number' => $student?->student_number,
            'first_name' => $student?->first_name,
            'last_name' => $student?->last_name,
            'full_name' => $fullName !== '' ? $fullName : null,
            'registration_date' => optional($this->registration_date)?->toDateString(),
            'registration_status' => $this->registrationStatus === null ? null : [
                'status_code' => $this->registrationStatus->status_code,
                'status_name' => $this->registrationStatus->status_name,
            ],
        ];

        if ($canViewGrades) {
            $payload['final_mark'] = $result?->final_mark === null ? null : round((float) $result->final_mark, 2);
            $payload['result_status'] = $resultStatus === null ? null : [
                'status_code' => $resultStatus->status_code,
                'status_name' => $resultStatus->status_name,
            ];
        }

        return $payload;
    }
}
