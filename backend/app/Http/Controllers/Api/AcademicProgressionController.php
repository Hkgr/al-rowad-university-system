<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentProgressionDecision;
use App\Services\AcademicProgressionService;
use App\Support\AcademicRecordWorkflow;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicProgressionController extends Controller
{
    public function __construct(private AcademicProgressionService $progression)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:submitted,returned,approved,superseded'],
            'student_id' => ['sometimes', 'integer', 'exists:students,student_id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->ok($this->progression->index(
            $request->user(),
            $validated['status'] ?? null,
            isset($validated['student_id']) ? (int) $validated['student_id'] : null,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null
        ));
    }

    public function evaluate(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,academic_year_id'],
        ]);

        return $this->ok($this->progression->evaluate(
            $request->user(),
            $student,
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null
        ));
    }

    public function show(Request $request, StudentProgressionDecision $progressionDecision): JsonResponse
    {
        return $this->ok($this->progression->show($request->user(), $progressionDecision));
    }

    public function submit(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'decision_result' => ['required', 'string', 'in:promoted,retained'],
        ]);

        return $this->ok(
            $this->progression->submit(
                $request->user(),
                $student,
                (int) $validated['academic_year_id'],
                $validated['decision_result']
            ),
            'Academic progression decision submitted.'
        );
    }

    public function returnForModification(Request $request, StudentProgressionDecision $progressionDecision): JsonResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'min:'.AcademicRecordWorkflow::RETURN_NOTES_MIN, 'max:'.AcademicRecordWorkflow::RETURN_NOTES_MAX],
        ]);

        return $this->ok(
            $this->progression->returnForModification(
                $request->user(),
                $progressionDecision,
                $validated['review_notes']
            ),
            'Academic progression decision returned.'
        );
    }

    public function approve(Request $request, StudentProgressionDecision $progressionDecision): JsonResponse
    {
        return $this->ok(
            $this->progression->approve($request->user(), $progressionDecision),
            'Academic progression decision approved.'
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
