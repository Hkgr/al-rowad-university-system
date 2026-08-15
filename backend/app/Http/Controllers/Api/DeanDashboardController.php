<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeanDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DeanDashboardController extends Controller
{
    public function __construct(private DeanDashboardService $dashboard)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('dashboards.view')) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لعرض لوحة متابعة الكلية.');
        }

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        return $this->successResponse($this->dashboard->build(
            $user,
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null,
            isset($validated['semester_id']) ? (int) $validated['semester_id'] : null,
        ));
    }

    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
