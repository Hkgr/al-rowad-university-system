<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SemesterOfferingRequest;
use App\Services\SemesterOfferingGovernanceService;
use App\Support\SemesterOfferingGovernance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScientificSemesterOfferingController extends Controller
{
    public function __construct(private SemesterOfferingGovernanceService $governance) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([
                SemesterOfferingGovernance::STATUS_SUBMITTED,
                SemesterOfferingGovernance::STATUS_RETURNED,
                SemesterOfferingGovernance::STATUS_APPROVED,
            ])],
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
            'college_id' => ['sometimes', 'integer', 'min:1'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->governance->reviewQueue($request->user())
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(! isset($validated['status']), fn ($q) => $q->where('status', SemesterOfferingGovernance::STATUS_SUBMITTED))
            ->when($validated['academic_year_id'] ?? null, fn ($q, $id) => $q->whereHas('courseOffering', fn ($o) => $o->where('academic_year_id', $id)))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->whereHas('courseOffering', fn ($o) => $o->where('semester_id', $id)))
            ->when($validated['academic_program_id'] ?? null, fn ($q, $id) => $q->whereHas('courseOffering', fn ($o) => $o->where('academic_program_id', $id)))
            ->when($validated['college_id'] ?? null, fn ($q, $id) => $q->whereHas('courseOffering.academicProgram.department', fn ($d) => $d->where('college_id', $id)))
            ->orderByDesc('submitted_at')
            ->orderByDesc('semester_offering_request_id');

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        $rows->getCollection()->transform(fn (SemesterOfferingRequest $row) => $this->governance->payload($row));

        return $this->ok($rows);
    }

    public function show(Request $request, SemesterOfferingRequest $semesterOfferingRequest): JsonResponse
    {
        return $this->ok($this->governance->payload($this->governance->show($request->user(), $semesterOfferingRequest)));
    }

    public function approve(Request $request, SemesterOfferingRequest $semesterOfferingRequest): JsonResponse
    {
        $request->validate(['reason' => ['prohibited']]);
        $updated = $this->governance->approve($request->user(), $semesterOfferingRequest);

        return $this->ok($this->governance->payload($updated), 'تم اعتماد الطرح وفتحه للتسجيل وفق المسار النظامي.');
    }

    public function returnForEditing(Request $request, SemesterOfferingRequest $semesterOfferingRequest): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:1000']]);
        $updated = $this->governance->returnForEditing($request->user(), $semesterOfferingRequest, $validated['reason']);

        return $this->ok($this->governance->payload($updated), 'أُعيد الطرح إلى العميد للتعديل.');
    }

    private function ok(mixed $data, string $message = 'Operation completed successfully'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
