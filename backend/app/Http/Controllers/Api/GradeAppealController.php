<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GradeException;
use App\Http\Requests\GradeAppeal\StoreGradeAppealRequest;
use App\Http\Requests\GradeAppeal\UpdateGradeAppealRequest;
use App\Http\Resources\GradeAppealResource;
use App\Models\AppealStatus;
use App\Models\GradeAppeal;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Services\AcademicAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GradeAppealController extends ApiController
{
    public function store(): JsonResponse
    {
        /** @var StoreGradeAppealRequest $request */
        $request = app($this->storeRequestClass());
        $user = $request->user();
        $studentId = (int) $user->student_id;

        $registration = StudentCourseRegistration::query()
            ->findOrFail($request->integer('student_course_registration_id'));

        if ((int) $registration->student_id !== $studentId) {
            throw new AccessDeniedHttpException('You are not authorized to submit an appeal for this registration.');
        }

        $result = StudentCourseResult::query()
            ->where('student_course_registration_id', $registration->student_course_registration_id)
            ->first();

        if ($result === null || $result->result_announced_at === null) {
            throw new GradeException('لم يتم إعلان النتيجة رسمياً بعد لهذا المقرر.');
        }

        $announcementDeadline = Carbon::parse($result->result_announced_at)->addDays(7);
        if (now()->gt($announcementDeadline)) {
            throw new GradeException('انتهت مهلة تقديم الاعتراض (أسبوع واحد من تاريخ إعلان النتيجة).');
        }

        if (GradeAppeal::query()
            ->where('student_course_registration_id', $registration->student_course_registration_id)
            ->exists()) {
            throw new GradeException('تم تقديم اعتراض على هذا المقرر مسبقاً.');
        }

        $submittedStatusId = AppealStatus::query()
            ->where('status_code', 'submitted')
            ->value('appeal_status_id');

        $appeal = GradeAppeal::query()->create([
            'student_id' => $studentId,
            'student_course_registration_id' => $registration->student_course_registration_id,
            'appeal_reason' => $request->string('appeal_reason'),
            'appeal_status_id' => $submittedStatusId,
            'submitted_at' => now(),
        ]);

        return $this->successResponse(
            (new GradeAppealResource($appeal))->resolve($request),
            'Operation completed successfully',
            201
        );
    }

    public function decide(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status_code' => ['required', 'string', 'in:under_review,accepted,rejected,closed'],
            'notes' => ['nullable', 'string'],
        ]);

        $appeal = GradeAppeal::query()->findOrFail($id);
        $statusId = AppealStatus::query()
            ->where('status_code', $validated['status_code'])
            ->value('appeal_status_id');

        $appeal->update([
            'appeal_status_id' => $statusId,
            'review_notes' => $validated['notes'] ?? null,
            'decision_date' => now(),
            'reviewed_by_user_id' => $request->user()?->user_id,
        ]);

        return $this->successResponse(
            (new GradeAppealResource($appeal->fresh()))->resolve($request)
        );
    }

    public function forStudent(int $student, AcademicAuthorizationService $authorization): JsonResponse
    {
        $studentModel = Student::query()->findOrFail($student);
        $authorization->assertStudentRecord(request()->user(), $studentModel);

        $appeals = GradeAppeal::query()
            ->where('student_id', $student)
            ->orderByDesc('submitted_at')
            ->get();

        return $this->successResponse(GradeAppealResource::collection($appeals));
    }

    protected function modelClass(): string
    {
        return GradeAppeal::class;
    }

    protected function resourceClass(): string
    {
        return GradeAppealResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeAppealRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeAppealRequest::class;
    }
}
