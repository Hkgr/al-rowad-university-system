<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DisciplinaryCase\StoreDisciplinaryCaseAppealRequest;
use App\Http\Requests\DisciplinaryCase\UpdateDisciplinaryCaseAppealRequest;
use App\Http\Resources\DisciplinaryCaseAppealResource;
use App\Models\DisciplinaryCaseAppeal;
use App\Models\StudentDisciplinaryCase;
use App\Services\AcademicPlanContext;
use App\Services\DataScopeService;
use App\Services\DisciplinaryCaseService;
use App\Support\StudentDisciplineAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisciplinaryCaseAppealController extends ApiController
{
    public function index(): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user());
        StudentDisciplineAccess::ready();
        $data = request()->validate(['case_id' => 'nullable|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $query = $this->appeals()->with('appealStatus')->orderByDesc('appeal_id');
        if (! empty($data['case_id'])) {
            $query->where('case_id', $data['case_id']);
        }
        request()->attributes->set('disciplinary.presentation.write', false);

        return $this->successResponse(DisciplinaryCaseAppealResource::collection($query->paginate($data['per_page'] ?? 15))->response(request())->getData(true));
    }

    public function show($id): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user());
        StudentDisciplineAccess::ready();

        $appeal = $this->appeals()->with(['appealStatus', 'disciplinaryCase.student'])->findOrFail($id);
        request()->attributes->set('disciplinary.presentation.write', StudentDisciplineAccess::allows(request()->user(), true)
            && app(DataScopeService::class)->canMutateStudent(request()->user(), $appeal->disciplinaryCase->student));

        return $this->successResponse((new DisciplinaryCaseAppealResource($appeal))->resolve(request()));
    }

    private function appeals(): Builder
    {
        return DisciplinaryCaseAppeal::whereHas('disciplinaryCase', fn ($q) => $q->whereIn('student_id', StudentDisciplineAccess::students(request()->user())->select('student_id')));
    }

    protected function modelClass(): string
    {
        return DisciplinaryCaseAppeal::class;
    }

    protected function resourceClass(): string
    {
        return DisciplinaryCaseAppealResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDisciplinaryCaseAppealRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateDisciplinaryCaseAppealRequest::class;
    }

    public function store(): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user(), true);
        StudentDisciplineAccess::ready();
        /** @var StoreDisciplinaryCaseAppealRequest $request */
        $request = app(StoreDisciplinaryCaseAppealRequest::class);
        $validated = $request->validated();

        $appeal = AcademicPlanContext::transaction(function () use ($validated) {
            $case = StudentDisciplinaryCase::findOrFail($validated['case_id']);
            StudentDisciplineAccess::student(request()->user(), (int) $case->student_id, true);
            $case = StudentDisciplinaryCase::lockForUpdate()->findOrFail($case->case_id);
            abort_if(in_array($case->case_status, ['overturned', 'expired'], true), 409, 'تغيرت حالة العقوبة؛ افتح السجل الحالي قبل تسجيل طعن.');

            return app(DisciplinaryCaseService::class)->submitAppeal((int) $case->case_id, $validated);
        });

        return $this->successResponse(
            (new DisciplinaryCaseAppealResource($appeal))->resolve(request()),
            'Operation completed successfully',
            201
        );
    }

    public function decide(int $id, Request $request): JsonResponse
    {
        StudentDisciplineAccess::authorize($request->user(), true);
        StudentDisciplineAccess::ready();
        $validated = $request->validate([
            'status_code' => ['required', 'string', 'in:accepted,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $appeal = AcademicPlanContext::transaction(function () use ($id, $request, $validated) {
            $appeal = $this->appeals()->with('disciplinaryCase')->findOrFail($id);
            StudentDisciplineAccess::student($request->user(), (int) $appeal->disciplinaryCase->student_id, true);
            StudentDisciplinaryCase::lockForUpdate()->findOrFail($appeal->case_id);
            $appeal = $this->appeals()->with('appealStatus')->lockForUpdate()->findOrFail($id);
            abort_unless($appeal->appealStatus?->status_code === 'submitted', 409, 'سُجلت نتيجة هذا الطعن بالفعل؛ افتح حالته الحالية.');

            return app(DisciplinaryCaseService::class)->decideAppeal($id, $validated['status_code'], $validated['notes'] ?? null, $request->user()->user_id);
        });

        return $this->successResponse(
            (new DisciplinaryCaseAppealResource($appeal))->resolve(request())
        );
    }
}
