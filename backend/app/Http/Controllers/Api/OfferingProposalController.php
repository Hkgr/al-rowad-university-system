<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OfferingProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class OfferingProposalController extends Controller
{
    public function preview(Request $request, OfferingProposalService $service): JsonResponse
    {
        $this->assertDean($request->user());
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'semester_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer'],
        ]);

        return $this->success($service->preview(
            (int) $validated['academic_year_id'],
            (int) $validated['semester_id'],
            (int) $validated['department_id'],
        ));
    }

    public function store(Request $request, OfferingProposalService $service): JsonResponse
    {
        $this->assertDean($request->user());
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'semester_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer'],
            'mandatory_faculty' => ['sometimes', 'array'],
            'mandatory_faculty.*.course_id' => ['required', 'integer'],
            'mandatory_faculty.*.academic_program_id' => ['required', 'integer'],
            'mandatory_faculty.*.faculty_member_id' => ['nullable', 'integer'],
            'electives' => ['sometimes', 'array'],
            'electives.*.course_id' => ['required', 'integer'],
            'electives.*.academic_program_id' => ['required', 'integer'],
            'electives.*.faculty_member_id' => ['required', 'integer'],
            'electives.*.capacity' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->success(
            $service->submit($request->user(), $validated),
            'تم إرسال طرح الفصل إلى النائب العلمي.',
            201
        );
    }

    public function index(Request $request, OfferingProposalService $service): JsonResponse
    {
        $user = $request->user();
        $this->assertDeanOrVicePresident($user);

        return $this->success($service->listFor($request->query('status')));
    }

    public function show(int $id, Request $request, OfferingProposalService $service): JsonResponse
    {
        $this->assertDeanOrVicePresident($request->user());

        return $this->success($service->show($id));
    }

    public function approve(int $id, Request $request, OfferingProposalService $service): JsonResponse
    {
        $this->assertVicePresident($request->user());
        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success(
            $service->approve($request->user(), $id, $validated['decision_note'] ?? null),
            'تم اعتماد طرح الفصل. المواد الإجبارية مفتوحة للتسجيل.'
        );
    }

    public function reject(int $id, Request $request, OfferingProposalService $service): JsonResponse
    {
        $this->assertVicePresident($request->user());
        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success(
            $service->reject($request->user(), $id, $validated['decision_note'] ?? null),
            'تم رفض طرح الفصل.'
        );
    }

    private function assertDean(User $user): void
    {
        if (! $user->effectiveRoles()->intersect(['dean', 'super_admin'])->isNotEmpty()) {
            throw new AccessDeniedHttpException('طرح المواد متاح لحساب العميد.');
        }
    }

    private function assertVicePresident(User $user): void
    {
        if (! $user->effectiveRoles()->intersect(['vice_president', 'vice_president_scientific', 'super_admin'])->isNotEmpty()) {
            throw new AccessDeniedHttpException('اعتماد الطرح متاح لحساب النائب العلمي.');
        }
    }

    private function assertDeanOrVicePresident(User $user): void
    {
        if (! $user->effectiveRoles()->intersect(['dean', 'vice_president', 'vice_president_scientific', 'super_admin'])->isNotEmpty()) {
            throw new AccessDeniedHttpException('عرض الطروحات متاح للعميد والنائب العلمي.');
        }
    }

    private function success(mixed $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
