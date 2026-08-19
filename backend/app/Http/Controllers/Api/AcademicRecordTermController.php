<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AcademicTermSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicRecordTermController extends Controller
{
    public function __construct(private AcademicTermSnapshotService $terms)
    {
    }

    public function index(Request $request, Student $student): JsonResponse
    {
        return $this->ok($this->terms->index($request->user(), $student));
    }

    public function recalculate(Request $request, Student $student, int $academicYear, int $semester): JsonResponse
    {
        return $this->ok(
            $this->terms->recalculate($request->user(), $student, $academicYear, $semester),
            'Academic term snapshot recalculated from official results.'
        );
    }

    public function finalize(Request $request, Student $student, int $academicYear, int $semester): JsonResponse
    {
        return $this->ok(
            $this->terms->finalize($request->user(), $student, $academicYear, $semester),
            'Academic term snapshot finalized.'
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
