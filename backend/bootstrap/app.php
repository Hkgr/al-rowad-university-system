<?php

use App\Exceptions\AcademicCalendarException;
use App\Exceptions\AcademicRecordException;
use App\Exceptions\AcademicRequirementConfigurationException;
use App\Exceptions\AttendanceException;
use App\Exceptions\CourseOfferingContextException;
use App\Exceptions\CourseOfferingScheduleException;
use App\Exceptions\DisciplinaryCaseException;
use App\Exceptions\GradeException;
use App\Exceptions\CourseOfferingClosureException;
use App\Exceptions\ExceptionalOpeningException;
use App\Exceptions\GraduationEligibilityException;
use App\Exceptions\OfferingInstructorCoverageException;
use App\Exceptions\RegistrationException;
use App\Exceptions\RegistrationRequestException;
use App\Exceptions\SemesterOfferingGovernanceException;
use App\Exceptions\MinistryPlacementException;
use App\Exceptions\SupplementaryExamOfferingException;
use App\Exceptions\SupplementaryExamPeriodGovernanceException;
use App\Exceptions\TeachingAssignmentException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (AcademicCalendarException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (MinistryPlacementException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (AttendanceException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (GradeException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (CourseOfferingContextException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (CourseOfferingScheduleException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
                'data' => $exception->data,
            ], $exception->status);
        });

        $exceptions->render(function (DisciplinaryCaseException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (RegistrationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
                'data' => $exception->data,
            ], $exception->status);
        });

        $exceptions->render(function (RegistrationRequestException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
                'item_failures' => $exception->itemFailures,
            ], $exception->status);
        });

        $exceptions->render(function (AcademicRequirementConfigurationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->context,
            ], $exception->status);
        });

        $exceptions->render(function (TeachingAssignmentException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (OfferingInstructorCoverageException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
                'coverage' => $exception->coverage,
            ], $exception->status);
        });

        $exceptions->render(function (SemesterOfferingGovernanceException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (ExceptionalOpeningException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (SupplementaryExamPeriodGovernanceException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (SupplementaryExamOfferingException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $payload = [
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ];
            if ($exception->data !== []) {
                $payload['data'] = $exception->data;
            }

            return response()->json($payload, $exception->status);
        });

        $exceptions->render(function (CourseOfferingClosureException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (GraduationEligibilityException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (AcademicRecordException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => $exception->errors,
            ], $exception->status);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error_code' => 'unauthenticated',
                'errors' => [],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'error_code' => 'forbidden',
                'errors' => [],
            ], 403);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'error_code' => 'too_many_requests',
                'errors' => [],
            ], 429)->withHeaders($retryAfter !== null ? ['Retry-After' => $retryAfter] : []);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
                'error_code' => 'not_found',
                'errors' => [],
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
                'error_code' => 'not_found',
                'errors' => [],
            ], 404);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Forbidden',
                'error_code' => 'forbidden',
                'errors' => [],
            ], 403);
        });

        $exceptions->render(function (ConflictHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Conflict',
                'error_code' => 'conflict',
                'errors' => [],
            ], 409);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $errorCode = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                429 => 'too_many_requests',
                default => 'http_error',
            };

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed',
                'error_code' => $errorCode,
                'errors' => [],
            ], $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $exception->getMessage() : 'Unexpected error occurred',
                'error_code' => 'unexpected_error',
                'errors' => config('app.debug') ? ['exception' => $exception::class] : [],
            ], 500);
        });
    })->create();
