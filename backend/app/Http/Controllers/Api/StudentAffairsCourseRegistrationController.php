<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Services\RegistrationService;
use App\Services\StudentAffairsRegistrationPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function pdf(Request $request, StudentAffairsRegistrationPdf $pdf): Response
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,student_id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,semester_id'],
        ]);
        $student = Student::query()->findOrFail($validated['student_id']);
        Gate::authorize('view', $student);
        $bytes = $pdf->forStudent($student, (int) $validated['academic_year_id'], (int) $validated['semester_id']);
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $student->student_number) ?: 'student';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="registration-'.$number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
