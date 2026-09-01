<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentRegistrationModificationItem;
use App\Services\RegistrationModificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentRegistrationModificationController extends Controller
{
    public function __construct(private RegistrationModificationService $modifications)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id']]);

        return $this->successResponse([
            'modification' => $this->modifications->createDraft(
                $this->student($request),
                $request->user(),
                (int) $validated['semester_id'],
            ),
        ], 'تم إنشاء مسودة تعديل التسجيل.', 201);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_notes' => ['nullable', 'string', 'max:1000'],
            'semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        return $this->successResponse([
            'modification' => $this->modifications->updateNotes($this->student($request), $request->user(), $validated['student_notes'] ?? null, (int) $validated['semester_id']),
        ], 'تم حفظ ملاحظات التعديل.');
    }

    public function updateItem(Request $request, StudentRegistrationModificationItem $modificationItem): JsonResponse
    {
        $validated = $request->validate(['operation' => ['required', 'string', 'in:keep,remove']]);

        return $this->successResponse([
            'modification' => $this->modifications->toggleBaselineItem(
                $this->student($request),
                $request->user(),
                $modificationItem,
                $validated['operation'],
            ),
        ], 'تم تحديث المقرر في التعديل.');
    }

    public function addItem(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        return $this->successResponse([
            'modification' => $this->modifications->addItem($this->student($request), $request->user(), $courseOffering),
        ], 'تمت إضافة المقرر إلى التعديل.', 201);
    }

    public function removeItem(Request $request, StudentRegistrationModificationItem $modificationItem): JsonResponse
    {
        return $this->successResponse([
            'modification' => $this->modifications->removeAddedItem($this->student($request), $request->user(), $modificationItem),
        ], 'تمت إزالة المقرر المقترح.');
    }

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate(['semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id']]);

        return $this->successResponse([
            'modification' => $this->modifications->submit($this->student($request), $request->user(), (int) $validated['semester_id']),
        ], 'تم إرسال تعديل التسجيل إلى المرشد الأكاديمي.');
    }

    private function student(Request $request): Student
    {
        $user = $request->user();
        $student = $user?->student_id === null ? null : Student::query()->find($user->student_id);
        if ($student === null) {
            throw new AccessDeniedHttpException('يجب أن يكون للحساب سجل طالب.');
        }

        return $student;
    }

    private function successResponse(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
