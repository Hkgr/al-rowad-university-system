<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Grade\IncompleteOperationRequest;
use App\Services\IncompleteResultService;
use Illuminate\Http\Request;

final class IncompleteResultController extends ActionApiController
{
    public function index(Request $request, IncompleteResultService $service)
    {
        $input = $request->validate(['state' => ['sometimes', 'in:active,resolved,expired'], 'student_id' => ['sometimes', 'integer', 'min:1'],
            'academic_year_id' => ['sometimes', 'integer', 'min:1'], 'semester_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        if (array_diff(array_keys($request->query()), ['state', 'student_id', 'academic_year_id', 'semester_id', 'page', 'per_page'])) throw \Illuminate\Validation\ValidationException::withMessages(['request' => 'Unknown filter.']);
        return $this->successResponse($service->index($request->user(), $input));
    }
    public function registration(Request $request, int $registration, IncompleteResultService $service) { return $this->successResponse($service->registrationView($request->user(), $registration)); }
    public function show(Request $request, int $case, IncompleteResultService $service) { return $this->successResponse($service->show($request->user(), $case)); }
    public function grant(IncompleteOperationRequest $request, int $registration, IncompleteResultService $service) { return $this->successResponse($service->grant($request->user(), $registration, $request->validated()), 'Incomplete granted.', 201); }
    public function deadline(IncompleteOperationRequest $request, int $case, IncompleteResultService $service) { return $this->successResponse($service->changeDeadline($request->user(), $case, $request->validated())); }
    public function resolve(IncompleteOperationRequest $request, int $case, IncompleteResultService $service) { return $this->successResponse($service->resolve($request->user(), $case, $request->validated())); }
}
