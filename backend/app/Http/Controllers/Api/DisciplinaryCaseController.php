<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DisciplinaryCase\StoreDisciplinaryCaseRequest;
use App\Http\Resources\DisciplinaryCaseResource;
use App\Models\Student;
use App\Models\StudentDisciplinaryCase;
use App\Services\DisciplinaryCaseService;
use Illuminate\Http\JsonResponse;

class DisciplinaryCaseController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentDisciplinaryCase::class;
    }

    protected function resourceClass(): string
    {
        return DisciplinaryCaseResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDisciplinaryCaseRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return '';
    }

    public function store(): JsonResponse
    {
        /** @var StoreDisciplinaryCaseRequest $request */
        $request = app(StoreDisciplinaryCaseRequest::class);

        $case = app(DisciplinaryCaseService::class)->createCase(
            $request->validated(),
            $request->user()?->user_id
        );

        return $this->successResponse(
            (new DisciplinaryCaseResource($case))->resolve(request()),
            'Operation completed successfully',
            201
        );
    }

    public function forStudent(int $student): JsonResponse
    {
        Student::query()->findOrFail($student);

        $cases = StudentDisciplinaryCase::query()
            ->where('student_id', $student)
            ->with(['violationType', 'penaltyType', 'triggerCourseOffering', 'affectedCourses', 'appeals'])
            ->orderByDesc('case_id')
            ->get();

        return $this->successResponse(
            DisciplinaryCaseResource::collection($cases)->resolve(request())
        );
    }
}
