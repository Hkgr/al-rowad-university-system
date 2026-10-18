<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicProgram;
use App\Models\College;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use App\Services\DataScopeService;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;

class StudentAffairsDashboardController extends Controller
{
    public function dashboardStats(): JsonResponse
    {
        $scope = app(DataScopeService::class);
        $user = request()->user();
        return $this->successResponse([
            'total_students' => $scope->scopeStudents(Student::query(), $user)->count(),
            'graduates_count' => $scope->scopeStudents(Student::query(), $user)
                ->whereHas('studentStatus', fn ($status) => $status->where('status_code', 'graduated'))
                ->count(),
            'registrations_count' => $scope->scopeRegistrations(StudentCourseRegistration::query(), $user)->count(),
            'course_offerings_count' => $scope->scopeOfferings(CourseOffering::query(), $user)->count(),
            'colleges_count' => $scope->scopeColleges(College::query(), $user)->count(),
            'departments_count' => $scope->scopeDepartments(Department::query(), $user)->count(),
            'programs_count' => $scope->scopePrograms(AcademicProgram::query(), $user)->count(),
        ]);
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
