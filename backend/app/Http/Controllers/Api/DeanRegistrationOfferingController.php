<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dean\OpenDeanRegistrationOfferingRequest;
use App\Models\CourseOffering;
use App\Services\DeanRegistrationOfferingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DeanRegistrationOfferingController extends Controller
{
    public function __construct(private DeanRegistrationOfferingService $registrationOfferings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanView($request);

        $validated = $request->validate([
            'academic_year_id' => ['required_with:academic_program_id', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required_with:academic_program_id', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,department_id'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
        ]);

        return $this->successResponse(
            $this->registrationOfferings->catalog($request->user(), $validated)
        );
    }

    public function open(OpenDeanRegistrationOfferingRequest $request): JsonResponse
    {
        $this->assertCanView($request);
        $result = $this->registrationOfferings->openFromProgramCourse(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse($result, $this->successMessage($result['action'] ?? null));
    }

    public function reopen(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanView($request);
        $request->validate([
            'exceptional' => ['prohibited'],
            'force' => ['prohibited'],
            'skip_coverage' => ['prohibited'],
            'bypass' => ['prohibited'],
        ]);
        $result = $this->registrationOfferings->reopenOffering($request->user(), $courseOffering);
        $action = ($result['action'] ?? null) === 'unchanged' ? 'reopened' : ($result['action'] ?? 'reopened');

        return $this->successResponse($result, $this->successMessage($action));
    }

    public function close(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanView($request);
        $result = $this->registrationOfferings->closeOffering($request->user(), $courseOffering);

        return $this->successResponse($result, $this->successMessage('closed'));
    }

    private function successMessage(?string $action): string
    {
        return match ($action) {
            'created', 'created_pending_coverage', 'created_closed' => 'تم إنشاء طرح المادة. يجب استكمال تكليف المدرسين المعتمدين قبل فتحها.',
            'reopened' => 'تمت إعادة فتح التسجيل للمادة بنجاح.',
            'closed' => 'تم إغلاق التسجيل للمادة بنجاح.',
            default => 'تمت إتاحة المادة للتسجيل بنجاح.',
        };
    }

    private function assertCanView(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('courses.view')) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لعرض مواد الكلية.');
        }
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
