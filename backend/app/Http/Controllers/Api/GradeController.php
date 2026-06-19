<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grade\StoreRegistrationGradesRequest;
use App\Http\Requests\Grade\UpdateRegistrationGradesRequest;
use App\Services\GradeService;
use Illuminate\Http\JsonResponse;

class GradeController extends Controller
{
    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function show(int $id, GradeService $service): JsonResponse
    {
        return $this->successResponse($service->getRegistrationGrades($id));
    }

    public function store(int $id, StoreRegistrationGradesRequest $request, GradeService $service): JsonResponse
    {
        $data = $service->createRegistrationGrades(
            $id,
            $request->validated(),
            $request->user()?->user_id
        );

        return $this->successResponse($data, 'Grades created successfully', 201);
    }

    public function update(int $id, UpdateRegistrationGradesRequest $request, GradeService $service): JsonResponse
    {
        $data = $service->updateRegistrationGrades(
            $id,
            $request->validated(),
            $request->user()?->user_id
        );

        return $this->successResponse($data, 'Grades updated successfully');
    }

    public function calculateResult(int $id, GradeService $service): JsonResponse
    {
        $data = $service->calculateRegistrationResult($id, request()->user()?->user_id);

        return $this->successResponse($data, 'Result calculated successfully');
    }
}
