<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DisciplinaryCase\StoreDisciplinaryCaseRequest;
use App\Http\Resources\DisciplinaryCaseResource;
use App\Models\DisciplinaryPenaltyType;
use App\Models\DisciplinaryViolationType;
use App\Models\StudentDisciplinaryCase;
use App\Services\AcademicPlanContext;
use App\Services\DataScopeService;
use App\Services\DisciplinaryCaseService;
use App\Support\StudentDisciplineAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisciplinaryCaseController extends ApiController
{
    public const STATUSES = ['active', 'appealed', 'overturned', 'served', 'expired'];

    public function index(): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user());
        StudentDisciplineAccess::ready();
        $filters = request()->validate(['q' => 'nullable|string|max:120', 'case_status' => 'nullable|in:'.implode(',', self::STATUSES),
            'student_id' => 'nullable|integer|min:1', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        if (! empty($filters['student_id'])) {
            StudentDisciplineAccess::student(request()->user(), (int) $filters['student_id']);
        }
        $query = $this->cases()->with(['student', 'violationType', 'penaltyType'])->orderByDesc('case_id');
        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }
        if (! empty($filters['case_status'])) {
            $query->where('case_status', $filters['case_status']);
        }
        foreach (preg_split('/\s+/u', trim($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $query->whereHas('student', fn ($q) => $q->where(fn ($q) => $q->where('student_number', 'like', '%'.$token.'%')
                ->orWhere('first_name', 'like', '%'.$token.'%')->orWhere('father_name', 'like', '%'.$token.'%')->orWhere('last_name', 'like', '%'.$token.'%')));
        }
        $page = $query->paginate($filters['per_page'] ?? 15);
        // Open a scoped detail before offering a record-specific mutation.
        request()->attributes->set('disciplinary.presentation.write', false);

        return $this->successResponse(DisciplinaryCaseResource::collection($page)->response(request())->getData(true));
    }

    public function show($id): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user());
        StudentDisciplineAccess::ready();
        $case = $this->cases()->with(['student', 'violationType', 'penaltyType', 'affectedCourses.courseOffering.course', 'appeals.appealStatus'])->findOrFail($id);
        request()->attributes->set('disciplinary.presentation.write', StudentDisciplineAccess::allows(request()->user(), true)
            && app(DataScopeService::class)->canMutateStudent(request()->user(), $case->student));

        return $this->successResponse((new DisciplinaryCaseResource($case))->resolve(request()));
    }

    public function options(Request $request): JsonResponse
    {
        StudentDisciplineAccess::authorize($request->user());
        StudentDisciplineAccess::ready();
        $data = $request->validate(['student_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:120', 'offering_q' => 'nullable|string|max:120']);
        $students = StudentDisciplineAccess::students($request->user())->orderBy('student_number')->orderBy('student_id');
        foreach (preg_split('/\s+/u', trim($data['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $students->where(fn ($q) => $q->where('student_number', 'like', '%'.$token.'%')->orWhere('first_name', 'like', '%'.$token.'%')
                ->orWhere('father_name', 'like', '%'.$token.'%')->orWhere('last_name', 'like', '%'.$token.'%'));
        }
        $offerings = [];
        $student = null;
        if (! empty($data['student_id'])) {
            $student = StudentDisciplineAccess::student($request->user(), (int) $data['student_id']);
            $registrations = $student->studentCourseRegistrations()->with(['courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester']);
            if (trim($data['offering_q'] ?? '') !== '') {
                $term = trim($data['offering_q']);
                $registrations->whereHas('courseOffering.course', fn ($q) => $q->where(fn ($q) => $q->where('course_name', 'like', '%'.$term.'%')->orWhere('course_code', 'like', '%'.$term.'%')));
            }
            $offerings = $registrations
                ->orderByDesc('student_course_registration_id')->limit(100)->get()->map(fn ($r) => [
                    'course_offering_id' => $r->course_offering_id, 'course_name' => $r->courseOffering?->course?->course_name,
                    'course_code' => $r->courseOffering?->course?->course_code, 'year_name' => $r->courseOffering?->academicYear?->year_name,
                    'semester_name' => $r->courseOffering?->semester?->semester_name,
                ])->unique('course_offering_id')->values();
        }

        return $this->successResponse([
            'students' => $students->whereNull('students.deleted_at')->limit(20)->get(['student_id', 'student_number', 'first_name', 'father_name', 'last_name', 'deleted_at'])->map(fn ($s) => StudentDisciplineAccess::identity($s)),
            'student' => $student ? StudentDisciplineAccess::identity($student) : null,
            'offerings' => $offerings,
            'violation_types' => DisciplinaryViolationType::where('is_active', true)->orderBy('violation_type_id')->get(['violation_type_id', 'violation_name_ar']),
            'penalty_types' => DisciplinaryPenaltyType::where('is_active', true)->orderBy('severity_order')->get(['penalty_type_id', 'penalty_name_ar', 'cascades_to_subsequent_courses']),
            'capabilities' => ['create' => StudentDisciplineAccess::allows($request->user(), true)
                && (! $student || app(DataScopeService::class)->canMutateStudent($request->user(), $student))],
        ]);
    }

    private function cases(): Builder
    {
        return StudentDisciplinaryCase::query()->whereIn('student_id', StudentDisciplineAccess::students(request()->user())->select('student_id'));
    }

    protected function modelClass(): string
    {
        return StudentDisciplinaryCase::class;
    }

    protected function resourceClass(): string
    {
        return DisciplinaryCaseResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDisciplinaryCaseRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return '';
    }

    public function store(): JsonResponse
    {
        StudentDisciplineAccess::authorize(request()->user(), true);
        StudentDisciplineAccess::ready();
        /** @var StoreDisciplinaryCaseRequest $request */
        $request = app(StoreDisciplinaryCaseRequest::class);
        $validated = $request->validated();
        $case = AcademicPlanContext::transaction(function () use ($request, $validated) {
            $student = StudentDisciplineAccess::student($request->user(), (int) $validated['student_id'], true);
            abort_unless(DisciplinaryViolationType::whereKey($validated['violation_type_id'])->where('is_active', true)->exists(), 422, 'نوع المخالفة غير فعال.');
            abort_unless(DisciplinaryPenaltyType::whereKey($validated['penalty_type_id'])->where('is_active', true)->exists(), 422, 'نوع العقوبة غير فعال.');
            if (! empty($validated['trigger_course_offering_id'])) {
                $registration = $student->studentCourseRegistrations()->where('course_offering_id', $validated['trigger_course_offering_id'])->with('courseOffering')->firstOrFail();
                abort_unless(app(DataScopeService::class)->canAccessOffering($request->user(), $registration->courseOffering), 403);
            }

            return app(DisciplinaryCaseService::class)->createCase(
                $validated,
                $request->user()?->user_id
            );
        });

        return $this->successResponse(
            (new DisciplinaryCaseResource($case))->resolve(request()),
            'Operation completed successfully',
            201
        );
    }

    public function forStudent(int $student): JsonResponse
    {
        $target = StudentDisciplineAccess::student(request()->user(), $student);
        StudentDisciplineAccess::ready();
        request()->attributes->set('disciplinary.presentation.write', StudentDisciplineAccess::allows(request()->user(), true)
            && app(DataScopeService::class)->canMutateStudent(request()->user(), $target));

        $cases = StudentDisciplinaryCase::query()
            ->where('student_id', $student)
            ->with(['violationType', 'penaltyType', 'affectedCourses', 'appeals.appealStatus'])
            ->orderByDesc('case_id')
            ->get();

        return $this->successResponse(
            DisciplinaryCaseResource::collection($cases)->resolve(request())
        );
    }
}
