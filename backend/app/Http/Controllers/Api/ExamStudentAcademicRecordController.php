<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentAcademicInfoResource;
use App\Models\Student;
use App\Services\ExamStudentAcademicRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExamStudentAcademicRecordController extends ActionApiController
{
    public function show(
        Request $request,
        Student $student,
        ExamStudentAcademicRecordService $records
    ): JsonResponse {
        abort_unless($request->user()?->hasPermission('grades.view') === true, 403);
        Gate::authorize('view', $student);

        $payload = $records->snapshot($student, $request->user());
        $payload['student'] = (new StudentAcademicInfoResource($student))->resolve($request);

        return $this->successResponse($payload);
    }
}
