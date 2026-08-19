<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentGraduationDecision;
use App\Services\GraduationDecisionService;
use App\Support\AcademicRecordWorkflow;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraduationDecisionController extends Controller
{
    public function __construct(private GraduationDecisionService $graduation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:submitted,returned,approved,superseded'],
            'student_id' => ['sometimes', 'integer', 'exists:students,student_id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->ok($this->graduation->index(
            $request->user(),
            $validated['status'] ?? null,
            isset($validated['student_id']) ? (int) $validated['student_id'] : null,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null
        ));
    }

    public function show(Request $request, StudentGraduationDecision $graduationDecision): JsonResponse
    {
        return $this->ok($this->graduation->show($request->user(), $graduationDecision));
    }

    public function submit(Request $request, Student $student): JsonResponse
    {
        return $this->ok(
            $this->graduation->submit($request->user(), $student),
            'Graduation decision submitted.'
        );
    }

    public function returnForModification(Request $request, StudentGraduationDecision $graduationDecision): JsonResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'min:'.AcademicRecordWorkflow::RETURN_NOTES_MIN, 'max:'.AcademicRecordWorkflow::RETURN_NOTES_MAX],
        ]);

        return $this->ok(
            $this->graduation->returnForModification(
                $request->user(),
                $graduationDecision,
                $validated['review_notes']
            ),
            'Graduation decision returned.'
        );
    }

    public function approve(Request $request, StudentGraduationDecision $graduationDecision): JsonResponse
    {
        return $this->ok(
            $this->graduation->approve($request->user(), $graduationDecision),
            'Graduation decision approved.'
        );
    }

    private function ok(mixed $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
