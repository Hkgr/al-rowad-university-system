<?php

namespace App\Http\Resources;

use App\Services\AcademicAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseRegistration */
class StudentCourseRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $exposeOfficialResult = $this->shouldExposeOfficialResult($request);

        return [
            'student_course_registration_id' => $this->student_course_registration_id,
            'student_id' => $this->student_id,
            'course_offering_id' => $this->course_offering_id,
            'registration_date' => $this->registration_date,
            'registered_by_user_id' => $this->registered_by_user_id,
            'advisor_user_id' => $this->advisor_user_id,
            'registration_status_id' => $this->registration_status_id,
            'result_status_id' => $this->when($exposeOfficialResult, $this->result_status_id),
            'notes' => $this->when($this->shouldExposeInternalNotes($request), $this->notes),
            'grade_entry_allowed' => $this->allowsGradeEntry(),
            'grade_entry_blocked_reason' => $this->allowsGradeEntry()
                ? null
                : 'Historical or inactive registrations are read-only.',
            'student' => StudentResource::make($this->whenLoaded('student')),
            'course_offering' => CourseOfferingResource::make($this->whenLoaded('courseOffering')),
            'registration_status' => RegistrationStatusResource::make($this->whenLoaded('registrationStatus')),
            'result_status' => $this->when(
                $exposeOfficialResult,
                fn () => ResultStatusResource::make($this->whenLoaded('resultStatus'))
            ),
            'student_course_result' => $this->when(
                $exposeOfficialResult && $this->relationLoaded('studentCourseResult') && $this->studentCourseResult !== null,
                fn () => StudentCourseResultResource::make($this->studentCourseResult)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function shouldExposeOfficialResult(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return app(AcademicAuthorizationService::class)->canExposeStudentCourseResult($user, $this->resource);
    }

    private function shouldExposeInternalNotes(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return ! app(AcademicAuthorizationService::class)->isRestrictedToOfficialStudentGrades($user);
    }
}
