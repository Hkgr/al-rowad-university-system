<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentAcademicInfoResource;
use App\Services\ExamStudentAcademicRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSelfAcademicRecordController extends ActionApiController
{
    public function show(Request $request, ExamStudentAcademicRecordService $records): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->hasPermission('grades.view') === true, 403);

        $student = $actor->student;
        abort_unless($student !== null, 403, 'The authenticated account is not linked to a student record.');

        $payload = $records->snapshot($student, $actor);
        $payload['student'] = (new StudentAcademicInfoResource($student))->resolve($request);

        return $this->successResponse($payload);
    }
}
