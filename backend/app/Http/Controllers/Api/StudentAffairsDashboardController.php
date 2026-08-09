<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicProgram;
use App\Models\College;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use App\Services\DataScopeService;

class StudentAffairsDashboardController extends Controller
{
    public function dashboardStats(): JsonResponse
    {
        return $this->successResponse([
            'total_students' => app(DataScopeService::class)->scopeStudents(Student::query(), request()->user())->count(),
            'graduates_count' => app(DataScopeService::class)->scopeStudents(Student::query(), request()->user())
                ->whereHas('studentStatus', fn ($status) => $status->where('status_code', 'graduated'))
                ->count(),
            'colleges_count' => $this->countActiveIfSupported(College::class),
            'departments_count' => $this->countActiveIfSupported(Department::class),
            'programs_count' => $this->countActiveIfSupported(AcademicProgram::class),
        ]);
    }

    private function countActiveIfSupported(string $modelClass): int
    {
        $query = $modelClass::query();
        $model = new $modelClass();

        if (Schema::hasColumn($model->getTable(), 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->count();
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
