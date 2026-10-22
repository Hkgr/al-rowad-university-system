<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentAffairsCourseRegistrationController extends ApiController
{
    public function store(Request $request, RegistrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,student_id'],
            'course_offering_ids' => ['required', 'array', 'min:1', 'max:20'],
            'course_offering_ids.*' => ['integer', 'distinct', 'exists:course_offerings,course_offering_id'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        Gate::authorize('view', $student);

        return $this->successResponse(
            $service->registerByStudentAffairs(
                $request->user(),
                (int) $student->student_id,
                $validated['course_offering_ids'],
            ),
            'تم تسجيل المواد.',
            201,
        );
    }
}
