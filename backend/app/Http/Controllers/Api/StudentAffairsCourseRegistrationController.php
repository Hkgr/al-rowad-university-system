<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\AcademicCatalogException;
use App\Exceptions\AcademicPlanException;
use App\Exceptions\AcademicRequirementConfigurationException;
use App\Exceptions\CourseOfferingScheduleException;
use App\Exceptions\GradeException;
use App\Exceptions\RegistrationException;
use App\Models\Student;
use App\Services\RegistrationService;
use App\Services\StudentAffairsRegistrationPdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StudentAffairsCourseRegistrationController extends Controller
{
    public function store(Request $request, RegistrationService $service): JsonResponse
    {
        try {
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
        } catch (Throwable $exception) {
            if ($this->registrationFailureIsVisible($exception)) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => $this->visibleRegistrationFailure($exception),
                'error_code' => (new \ReflectionClass($exception))->getShortName(),
            ], 500);
        }
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

    private function registrationFailureIsVisible(Throwable $exception): bool
    {
        return $exception instanceof ValidationException
            || $exception instanceof RegistrationException
            || $exception instanceof AcademicPlanException
            || $exception instanceof AcademicCatalogException
            || $exception instanceof AcademicRequirementConfigurationException
            || $exception instanceof GradeException
            || $exception instanceof CourseOfferingScheduleException
            || $exception instanceof HttpExceptionInterface
            || $exception instanceof AuthorizationException
            || $exception instanceof AuthenticationException
            || $exception instanceof ModelNotFoundException;
    }

    private function visibleRegistrationFailure(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $code = (int) ($exception->errorInfo[1] ?? 0);

            return $code === 0 ? 'تعذر تسجيل المواد.' : 'تعذر تسجيل المواد. ('.$code.')';
        }

        $message = trim($exception->getMessage());
        if ($message === '' || preg_match('/SQLSTATE|\bSELECT\b|\bINSERT\b|\bUPDATE\b/i', $message) === 1) {
            return 'تعذر تسجيل المواد. ('.(new \ReflectionClass($exception))->getShortName().')';
        }

        return mb_substr($message, 0, 180);
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
