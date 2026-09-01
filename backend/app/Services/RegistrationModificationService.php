<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Exceptions\RegistrationRequestException;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationModificationEvent;
use App\Models\StudentRegistrationModificationItem;
use App\Models\StudentRegistrationModificationRequest;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationWithdrawalRequest;
use App\Models\User;
use App\Support\AcademicQueuePagination;
use App\Support\CourseRegistrationPhase;
use App\Support\RegistrationModificationWorkflow as Workflow;
use App\Support\RegistrationProjectionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RegistrationModificationService
{
    private const NOTES_MAX = 1000;
    private const ADVISOR_NOTES_MIN = 8;
    private const ADVISOR_NOTES_MAX = 2000;

    public function __construct(
        private RegistrationService $registration,
        private AcademicRequirementService $requirements,
        private CourseOfferingScheduleService $schedules,
        private AcademicTermResolver $academicTerms,
        private DataScopeService $dataScopes,
    ) {
    }

    public function studentWorkspace(Student $student, ?int $academicYearId, ?int $semesterId): array
    {
        if (! Workflow::schemaReady()) {
            return ['schema_ready' => false, 'can_create' => false, 'current' => null, 'history' => []];
        }
        if ($academicYearId === null || $semesterId === null) {
            return ['schema_ready' => true, 'can_create' => false, 'current' => null, 'history' => []];
        }

        $this->reconcileStudentExpiration($student, $academicYearId, $semesterId);
        $current = StudentRegistrationModificationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->where('current_slot', Workflow::CURRENT_SLOT)
            ->first();
        $initial = $this->approvedInitialRequest($student, $academicYearId, $semesterId);
        $deadline = $this->registration->courseRegistrationDeadlines($academicYearId, $semesterId);
        $history = StudentRegistrationModificationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->whereNull('current_slot')
            ->latest('student_registration_modification_request_id')
            ->limit(20)
            ->get()
            ->map(fn ($request): array => $this->present($request, $student, false, false))
            ->all();

        return [
            'schema_ready' => true,
            'can_create' => $current === null && $initial !== null && $deadline->phase === CourseRegistrationPhase::STUDENT_OPEN,
            'current' => $current === null ? null : $this->present($current, $student, false, true),
            'history' => $history,
        ];
    }

    public function createDraft(Student $student, User $actor, int $semesterId): array
    {
        $this->assertSchemaReady();
        $yearId = $this->academicTerms->uniqueCurrentAcademicYearId();
        if ($yearId === null) {
            throw RegistrationRequestException::calendarConfigurationInvalid('current_academic_year_not_unique');
        }
        $this->registration->assertCourseRegistrationStudentWindowOpen($yearId, $semesterId);

        try {
            $request = DB::transaction(function () use ($student, $actor, $yearId, $semesterId) {
                $lockedStudent = $this->registration->lockStudent((int) $student->student_id);
                $existing = StudentRegistrationModificationRequest::query()
                    ->where('student_id', $lockedStudent->student_id)
                    ->where('academic_year_id', $yearId)
                    ->where('semester_id', $semesterId)
                    ->where('current_slot', Workflow::CURRENT_SLOT)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $initial = StudentRegistrationRequest::query()
                    ->where('student_id', $lockedStudent->student_id)
                    ->where('academic_year_id', $yearId)
                    ->where('semester_id', $semesterId)
                    ->where('status', StudentRegistrationRequest::STATUS_APPROVED)
                    ->with('items')
                    ->lockForUpdate()
                    ->first();
                if ($initial === null || $initial->items->isEmpty()
                    || $initial->items->contains(fn ($item): bool => $item->student_course_registration_id === null)) {
                    throw new RegistrationRequestException(
                        'يجب اعتماد طلب التسجيل الأولي وتثبيته قبل إنشاء طلب تعديل.',
                        ['modification' => ['initial_approved_registration_required']],
                        409,
                        'initial_approved_registration_required',
                    );
                }

                $baseline = $this->officialTermRegistrationsQuery($lockedStudent, $yearId, $semesterId)
                    ->orderBy('student_course_registrations.student_course_registration_id')
                    ->lockForUpdate()
                    ->get();
                $request = StudentRegistrationModificationRequest::query()->create([
                    'initial_registration_request_id' => $initial->getKey(),
                    'student_id' => $lockedStudent->student_id,
                    'academic_year_id' => $yearId,
                    'semester_id' => $semesterId,
                    'status' => Workflow::STATUS_DRAFT,
                    'submission_version' => 0,
                    'current_slot' => Workflow::CURRENT_SLOT,
                ]);
                foreach ($baseline as $registration) {
                    StudentRegistrationModificationItem::query()->create([
                        'student_registration_modification_request_id' => $request->getKey(),
                        'operation' => Workflow::OPERATION_KEEP,
                        'course_offering_id' => $registration->course_offering_id,
                        'source_student_course_registration_id' => $registration->getKey(),
                    ]);
                }
                $this->writeEvent($request, Workflow::EVENT_DRAFT_CREATED, $actor, null, Workflow::STATUS_DRAFT);
                $this->writeEvent($request, Workflow::EVENT_BASELINE_SNAPSHOTTED, $actor, null, Workflow::STATUS_DRAFT);

                return $request;
            });
        } catch (QueryException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw $exception;
            }
            $request = StudentRegistrationModificationRequest::query()
                ->where('student_id', $student->student_id)
                ->where('academic_year_id', $yearId)
                ->where('semester_id', $semesterId)
                ->where('current_slot', Workflow::CURRENT_SLOT)
                ->firstOrFail();
        }

        return $this->present($request, $student, false, true);
    }

    public function updateNotes(Student $student, User $actor, ?string $notes, int $semesterId): array
    {
        $notes = $notes === null ? null : trim($notes);
        if ($notes !== null && mb_strlen($notes) > self::NOTES_MAX) {
            throw new RegistrationRequestException('ملاحظات الطلب طويلة جدًا.', ['student_notes' => ['max:1000']]);
        }

        return $this->studentMutation($student, $actor, function ($request) use ($notes): void {
            $request->update(['student_notes' => $notes === '' ? null : $notes]);
        }, semesterId: $semesterId);
    }

    public function toggleBaselineItem(
        Student $student,
        User $actor,
        StudentRegistrationModificationItem $item,
        string $operation,
    ): array {
        if (! in_array($operation, [Workflow::OPERATION_KEEP, Workflow::OPERATION_REMOVE], true)) {
            throw new RegistrationRequestException('عملية العنصر غير صالحة.', ['operation' => ['in:keep,remove']]);
        }

        return $this->studentMutation($student, $actor, function ($request) use ($item, $operation, $actor): void {
            $lockedItem = StudentRegistrationModificationItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedItem->student_registration_modification_request_id !== (int) $request->getKey()
                || $lockedItem->source_student_course_registration_id === null
                || ! in_array($lockedItem->operation, [Workflow::OPERATION_KEEP, Workflow::OPERATION_REMOVE], true)) {
                throw new AccessDeniedHttpException('هذا العنصر لا ينتمي إلى طلب التعديل الحالي.');
            }
            if ($lockedItem->operation === $operation) {
                return;
            }
            $lockedItem->update(['operation' => $operation]);
            $this->writeEvent(
                $request,
                $operation === Workflow::OPERATION_REMOVE ? Workflow::EVENT_ITEM_MARKED_REMOVE : Workflow::EVENT_ITEM_RESTORED_KEEP,
                $actor,
                $request->status,
                $request->status,
            );
        }, requestId: (int) $item->student_registration_modification_request_id);
    }

    public function addItem(Student $student, User $actor, CourseOffering $offering): array
    {
        return $this->studentMutation($student, $actor, function ($request) use ($student, $actor, $offering): void {
            $lockedOffering = CourseOffering::query()->whereKey($offering->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $lockedOffering->academic_year_id !== (int) $request->academic_year_id
                || (int) $lockedOffering->semester_id !== (int) $request->semester_id) {
                throw new RegistrationRequestException('الطرح لا ينتمي إلى فصل طلب التعديل.', ['course_offering_id' => ['term_mismatch']]);
            }
            $this->registration->assertSelfRegistrationAllowed($student, $lockedOffering);
            if (StudentRegistrationModificationItem::query()
                ->where('student_registration_modification_request_id', $request->getKey())
                ->where('course_offering_id', $lockedOffering->getKey())
                ->exists()) {
                throw new RegistrationRequestException(
                    'المقرر موجود أصلًا في التسجيل أو التعديل.',
                    ['course_offering_id' => ['duplicate_final_offering']],
                    409,
                    'duplicate_final_offering',
                );
            }
            $canonicalFailures = $this->registration->evaluateRegistrationCandidatesForProjection(
                $student,
                collect([$lockedOffering]),
            );
            if ($canonicalFailures !== []) {
                throw new RegistrationRequestException(
                    'لا يمكن إضافة المقرر بسبب السجل الأكاديمي أو المتطلبات السابقة.',
                    ['course_offering_id' => [$canonicalFailures[0]['reason']]],
                    422,
                    (string) $canonicalFailures[0]['reason'],
                    $canonicalFailures,
                );
            }
            StudentRegistrationModificationItem::query()->create([
                'student_registration_modification_request_id' => $request->getKey(),
                'operation' => Workflow::OPERATION_ADD,
                'course_offering_id' => $lockedOffering->getKey(),
            ]);
            $this->writeEvent($request, Workflow::EVENT_ITEM_ADDED, $actor, $request->status, $request->status);
        }, semesterId: (int) $offering->semester_id);
    }

    public function removeAddedItem(Student $student, User $actor, StudentRegistrationModificationItem $item): array
    {
        return $this->studentMutation($student, $actor, function ($request) use ($item, $actor): void {
            $locked = StudentRegistrationModificationItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->student_registration_modification_request_id !== (int) $request->getKey()
                || $locked->operation !== Workflow::OPERATION_ADD
                || $locked->source_student_course_registration_id !== null
                || $locked->materialized_student_course_registration_id !== null) {
                throw new AccessDeniedHttpException('لا يمكن حذف هذا العنصر من طلب التعديل.');
            }
            $locked->delete();
            $this->writeEvent($request, Workflow::EVENT_ITEM_REMOVED, $actor, $request->status, $request->status);
        }, requestId: (int) $item->student_registration_modification_request_id);
    }

    public function submit(Student $student, User $actor, int $semesterId): array
    {
        $this->assertSchemaReady();
        $outcome = DB::transaction(function () use ($student, $actor, $semesterId): array {
            $request = $this->lockCurrentForStudent($student, semesterId: $semesterId);
            $this->registration->assertCourseRegistrationStudentWindowOpen(
                (int) $request->academic_year_id,
                (int) $request->semester_id,
            );
            $this->assertEditable($request);
            if (! $this->baselineMatches($request, $student, lock: true)) {
                $this->supersede($request, $actor);
                return ['outcome' => 'stale'];
            }
            $request->load('items.courseOffering.course');
            if (! $this->hasDelta($request->items)) {
                throw new RegistrationRequestException(
                    'لا يحتوي طلب التعديل على أي تغيير.',
                    ['modification' => ['registration_modification_no_changes']],
                    422,
                    'registration_modification_no_changes',
                );
            }
            $description = $this->projectedDescription($student, $request, true);
            if ($description['failures'] !== []) {
                throw new RegistrationRequestException(
                    'لا يمكن إرسال التعديل لوجود مقررات غير مؤهلة.',
                    ['items' => ['registration_modification_invalid']],
                    422,
                    'registration_modification_invalid',
                    $description['failures'],
                );
            }
            $from = $request->status;
            $now = CarbonImmutable::now('UTC');
            $request->update([
                'status' => Workflow::STATUS_SUBMITTED,
                'submission_version' => (int) $request->submission_version + 1,
                'first_submitted_at' => $request->first_submitted_at ?? $now,
                'last_submitted_at' => $now,
                'reviewed_at' => null,
            ]);
            $this->writeEvent(
                $request,
                $from === Workflow::STATUS_RETURNED ? Workflow::EVENT_RESUBMITTED : Workflow::EVENT_SUBMITTED,
                $actor,
                $from,
                Workflow::STATUS_SUBMITTED,
            );

            return ['outcome' => 'ok', 'request' => $request];
        });

        return $this->finishStudentOutcome($outcome, $student);
    }

    public function advisorIndex(User $actor, ?string $status = null, ?int $perPage = null): array
    {
        $this->assertSchemaReady();
        $this->assertCanView($actor);
        $this->reconcileScopedExpirations($actor);
        $status = in_array($status, [
            Workflow::STATUS_SUBMITTED,
            Workflow::STATUS_RETURNED,
            Workflow::STATUS_APPROVED,
            Workflow::STATUS_EXPIRED,
            Workflow::STATUS_SUPERSEDED,
        ], true) ? $status : Workflow::STATUS_SUBMITTED;
        $base = $this->scopedQuery($actor);
        $summary = [];
        foreach ([Workflow::STATUS_SUBMITTED, Workflow::STATUS_RETURNED, Workflow::STATUS_APPROVED, Workflow::STATUS_EXPIRED, Workflow::STATUS_SUPERSEDED] as $code) {
            $summary[$code] = (clone $base)->where('status', $code)->count();
        }
        $paginator = $base->where('status', $status)
            ->with(['student.academicProgram', 'academicYear', 'semester', 'items.courseOffering.course'])
            ->orderByDesc('last_submitted_at')
            ->orderByDesc('student_registration_modification_request_id')
            ->paginate(AcademicQueuePagination::perPage($perPage));
        $deadlines = [];
        foreach ($paginator->getCollection() as $request) {
            $key = (int) $request->academic_year_id.':'.(int) $request->semester_id;
            $deadlines[$key] ??= $this->registration->courseRegistrationDeadlines(
                (int) $request->academic_year_id,
                (int) $request->semester_id,
            )->toArray();
        }

        return [
            'summary' => $summary,
            'status' => $status,
            'requests' => $paginator->getCollection()->map(function ($request) use ($deadlines): array {
                $key = (int) $request->academic_year_id.':'.(int) $request->semester_id;

                return $this->presentListItem($request, $deadlines[$key]);
            })->all(),
            'meta' => AcademicQueuePagination::meta($paginator),
        ];
    }

    public function advisorShow(User $actor, StudentRegistrationModificationRequest $request): array
    {
        $this->assertSchemaReady();
        $this->assertCanView($actor);
        $this->assertCanAccess($actor, $request);
        $request = $this->reconcileExpiration($request);

        return $this->present($request, $request->student, true, true);
    }

    public function returnForModification(User $actor, StudentRegistrationModificationRequest $request, string $notes): array
    {
        $this->assertSchemaReady();
        $this->assertCanReview($actor);
        $this->assertCanAccess($actor, $request);
        $notes = trim($notes);
        if (mb_strlen($notes) < self::ADVISOR_NOTES_MIN || mb_strlen($notes) > self::ADVISOR_NOTES_MAX) {
            throw new RegistrationRequestException('سبب الإعادة مطلوب.', ['advisor_notes' => ['min:8,max:2000']]);
        }
        $outcome = DB::transaction(function () use ($actor, $request, $notes): array {
            $locked = StudentRegistrationModificationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCanAccess($actor, $locked);
            $student = Student::query()->whereKey($locked->student_id)->lockForUpdate()->firstOrFail();
            if (! $this->baselineMatches($locked, $student, lock: true)) {
                $this->supersede($locked, $actor);
                return ['outcome' => 'stale'];
            }
            $deadline = $this->registration->courseRegistrationDeadlines((int) $locked->academic_year_id, (int) $locked->semester_id);
            if (! $deadline->isAdvisorDecisionOpen()) {
                $this->expire($locked);
                return ['outcome' => 'expired'];
            }
            if ($locked->status !== Workflow::STATUS_SUBMITTED) {
                throw new RegistrationRequestException('يمكن إعادة الطلب المرسل فقط.', [], 409, 'registration_modification_not_submitted');
            }
            $from = $locked->status;
            $locked->update([
                'status' => Workflow::STATUS_RETURNED,
                'advisor_user_id' => $actor->user_id,
                'advisor_notes' => $notes,
                'reviewed_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->writeEvent($locked, Workflow::EVENT_RETURNED, $actor, $from, Workflow::STATUS_RETURNED, $notes);

            return ['outcome' => 'ok', 'request' => $locked];
        });

        return $this->finishAdvisorOutcome($outcome, $actor);
    }

    public function approve(User $actor, StudentRegistrationModificationRequest $request): array
    {
        $this->assertSchemaReady();
        $this->assertCanReview($actor);
        $this->assertCanAccess($actor, $request);
        $outcome = DB::transaction(function () use ($actor, $request): array {
            $locked = StudentRegistrationModificationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCanAccess($actor, $locked);
            if ($locked->status === Workflow::STATUS_APPROVED && $locked->materialized_at !== null) {
                return ['outcome' => 'ok', 'request' => $locked];
            }
            if ($locked->status !== Workflow::STATUS_SUBMITTED || ! $locked->isCurrent()) {
                throw new RegistrationRequestException('يمكن اعتماد الطلب الحالي المرسل فقط.', [], 409, 'registration_modification_not_submitted');
            }
            $student = Student::query()->whereKey($locked->student_id)->lockForUpdate()->firstOrFail();
            // The request lock serializes all supported item mutations. Read the
            // immutable identifiers first so the canonical approval lock order is
            // request -> student -> offerings -> registrations -> withdrawals -> items.
            $itemSnapshot = StudentRegistrationModificationItem::query()
                ->where('student_registration_modification_request_id', $locked->getKey())
                ->orderBy('course_offering_id')
                ->get();
            $offeringIds = $itemSnapshot->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            $lockedOfferings = CourseOffering::query()
                ->with('course')
                ->whereIn('course_offering_id', $offeringIds)
                ->orderBy('course_offering_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('course_offering_id');
            if ($lockedOfferings->count() !== $offeringIds->count()) {
                throw new RegistrationRequestException(
                    'تغيرت بيانات طرح مضمّن في طلب التعديل.',
                    ['items' => ['registration_modification_stale']],
                    409,
                    'registration_modification_stale',
                );
            }
            $official = $this->officialTermRegistrationsQuery($student, (int) $locked->academic_year_id, (int) $locked->semester_id)
                ->orderBy('student_course_registrations.student_course_registration_id')
                ->lockForUpdate()->get();
            $sourceIds = $itemSnapshot->whereNotNull('source_student_course_registration_id')
                ->pluck('source_student_course_registration_id')->map(fn ($id): int => (int) $id)->all();
            $withdrawals = StudentRegistrationWithdrawalRequest::query()
                ->whereIn('student_course_registration_id', $sourceIds)
                ->where('current_slot', 1)
                ->orderBy('student_registration_withdrawal_request_id')
                ->lockForUpdate()->get();
            $items = StudentRegistrationModificationItem::query()
                ->where('student_registration_modification_request_id', $locked->getKey())
                ->orderBy('course_offering_id')
                ->lockForUpdate()
                ->get();
            if ($items->pluck('student_registration_modification_item_id')->all()
                !== $itemSnapshot->pluck('student_registration_modification_item_id')->all()) {
                throw new RegistrationRequestException(
                    'تغيرت عناصر طلب التعديل أثناء المراجعة.',
                    ['items' => ['registration_modification_stale']],
                    409,
                    'registration_modification_stale',
                );
            }
            $items->each(function (StudentRegistrationModificationItem $item) use ($lockedOfferings): void {
                $item->setRelation('courseOffering', $lockedOfferings->get((int) $item->course_offering_id));
            });
            if ($withdrawals->isNotEmpty()) {
                throw new RegistrationRequestException(
                    'يوجد طلب انسحاب حالي يتعارض مع تعديل التسجيل.',
                    ['modification' => ['registration_modification_withdrawal_conflict']],
                    409,
                    'registration_modification_withdrawal_conflict',
                );
            }
            if (! $this->baselineMatchesCollection($items, $official, $student, $locked)) {
                $this->supersede($locked, $actor);
                return ['outcome' => 'stale'];
            }
            $now = CarbonImmutable::now('UTC');
            $deadline = $this->registration->courseRegistrationDeadlines((int) $locked->academic_year_id, (int) $locked->semester_id, $now);
            if (! $deadline->isAdvisorDecisionOpen()) {
                $this->expire($locked);
                return ['outcome' => 'expired'];
            }
            if ($locked->last_submitted_at === null || $deadline->startsAt === null || $deadline->studentRegistrationEndsAt === null
                || CarbonImmutable::instance($locked->last_submitted_at)->utc()->lt($deadline->startsAt)
                || CarbonImmutable::instance($locked->last_submitted_at)->utc()->gt($deadline->studentRegistrationEndsAt)) {
                throw RegistrationRequestException::submissionOutsideStudentDeadline();
            }

            $locked->setRelation('items', $items);
            $description = $this->projectedDescription($student, $locked, true);
            if ($description['failures'] !== []) {
                throw new RegistrationRequestException(
                    'تعذر اعتماد التعديل لأن المجموعة المتوقعة لم تعد مؤهلة.',
                    ['items' => ['registration_modification_approval_failed']],
                    409,
                    'registration_modification_approval_failed',
                    $description['failures'],
                );
            }

            $removeItems = $items->where('operation', Workflow::OPERATION_REMOVE)->sortBy('course_offering_id');
            foreach ($removeItems as $item) {
                $registration = $official->firstWhere('student_course_registration_id', $item->source_student_course_registration_id);
                $offering = $item->courseOffering;
                if ($registration === null || $offering === null || $offering->status !== 'open') {
                    throw new RegistrationRequestException(
                        'يتطلب هذا المقرر استخدام مسار الانسحاب.',
                        ['course_offering_id' => ['registration_modification_source_requires_withdrawal']],
                        409,
                        'registration_modification_source_requires_withdrawal',
                    );
                }
                $registration->loadMissing('registrationStatus');
                $this->registration->transitionRegisteredToDropped($registration, $offering);
            }

            $materialized = [];
            foreach ($items->where('operation', Workflow::OPERATION_ADD)->sortBy('course_offering_id') as $item) {
                $result = $this->registration->materializeAdvisorApprovedModificationItemWithinTransaction($locked, $item, (int) $actor->user_id, $now);
                $item->update(['materialized_student_course_registration_id' => $result['registration']->getKey()]);
                $materialized[] = $result['registration']->getKey();
            }

            $from = $locked->status;
            $hours = $description['hours'];
            $locked->update([
                'status' => Workflow::STATUS_APPROVED,
                'current_slot' => null,
                'advisor_user_id' => $actor->user_id,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'materialized_at' => $now,
                'registered_hours_before_approval' => $hours['registered_hours_before'],
                'removed_hours_at_approval' => $hours['removed_hours'],
                'added_hours_at_approval' => $hours['added_hours'],
                'projected_hours_at_approval' => $hours['projected_hours'],
                'max_allowed_hours_at_approval' => $hours['max_allowed_hours'],
                'remaining_hours_after_approval' => max($hours['max_allowed_hours'] - $hours['projected_hours'], 0),
            ]);
            $this->writeEvent($locked, Workflow::EVENT_APPROVED, $actor, $from, Workflow::STATUS_APPROVED);
            $this->writeEvent($locked, Workflow::EVENT_MATERIALIZED, $actor, Workflow::STATUS_APPROVED, Workflow::STATUS_APPROVED);

            return ['outcome' => 'ok', 'request' => $locked, 'materialized_registration_ids' => $materialized];
        });

        return $this->finishAdvisorOutcome($outcome, $actor);
    }

    private function studentMutation(
        Student $student,
        User $actor,
        callable $mutation,
        ?int $requestId = null,
        ?int $semesterId = null,
    ): array
    {
        $this->assertSchemaReady();
        $outcome = DB::transaction(function () use ($student, $actor, $mutation, $requestId, $semesterId): array {
            $request = $this->lockCurrentForStudent($student, $requestId, $semesterId);
            $this->registration->assertCourseRegistrationStudentWindowOpen((int) $request->academic_year_id, (int) $request->semester_id);
            $this->assertEditable($request);
            if (! $this->baselineMatches($request, $student, lock: true)) {
                $this->supersede($request, $actor);
                return ['outcome' => 'stale'];
            }
            $mutation($request);

            return ['outcome' => 'ok', 'request' => $request];
        });

        return $this->finishStudentOutcome($outcome, $student);
    }

    private function finishStudentOutcome(array $outcome, Student $student): array
    {
        if (($outcome['outcome'] ?? null) === 'stale') {
            throw new RegistrationRequestException('تغير التسجيل الرسمي وأصبح طلب التعديل قديمًا.', [], 409, 'registration_modification_stale');
        }
        if (($outcome['outcome'] ?? null) === 'expired') {
            throw RegistrationRequestException::advisorDeadlineClosed();
        }

        return $this->present($outcome['request'], $student, false, true);
    }

    private function finishAdvisorOutcome(array $outcome, User $actor): array
    {
        if (($outcome['outcome'] ?? null) === 'stale') {
            throw new RegistrationRequestException('تغير التسجيل الرسمي وأصبح طلب التعديل قديمًا.', [], 409, 'registration_modification_stale');
        }
        if (($outcome['outcome'] ?? null) === 'expired') {
            throw RegistrationRequestException::advisorDeadlineClosed();
        }
        $request = StudentRegistrationModificationRequest::query()->findOrFail($outcome['request']->getKey());
        $this->assertCanAccess($actor, $request);

        return $this->present($request, $request->student, true, true);
    }

    private function projectedDescription(Student $student, StudentRegistrationModificationRequest $request, bool $validate): array
    {
        $request->loadMissing(['items.courseOffering.course', 'items.sourceRegistration.registrationStatus']);
        $remove = $request->items->where('operation', Workflow::OPERATION_REMOVE);
        $add = $request->items->where('operation', Workflow::OPERATION_ADD);
        $keep = $request->items->where('operation', Workflow::OPERATION_KEEP);
        $projection = new RegistrationProjectionContext(
            excludedRegistrationIds: $remove->pluck('source_student_course_registration_id')->all(),
            excludedOfferingIds: $remove->pluck('course_offering_id')->all(),
            proposedAddOfferingIds: $add->pluck('course_offering_id')->all(),
        );
        $baseHours = $this->registration->hoursSnapshot($student, (int) $request->academic_year_id, (int) $request->semester_id);
        $projectedBase = $this->registration->hoursSnapshot($student, (int) $request->academic_year_id, (int) $request->semester_id, $projection);
        $removedHours = (int) $remove->sum(fn ($item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));
        $addedHours = (int) $add->sum(fn ($item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));
        $projectedHours = (int) $projectedBase['registered_hours'] + $addedHours;
        $finalIds = collect([...$keep->pluck('course_offering_id')->all(), ...$add->pluck('course_offering_id')->all()])
            ->map(fn ($id): int => (int) $id)->unique()->values();
        $finalOfferings = CourseOffering::query()->with('course')->whereIn('course_offering_id', $finalIds)->get();
        $schedule = $this->schedules->registrationEvaluations($student, $finalOfferings, $finalIds->all(), [], $projection);
        $failures = [];
        if ($validate) {
            foreach ($add as $item) {
                try {
                    $this->registration->assertSelfRegistrationAllowed($student, $item->courseOffering);
                } catch (RegistrationException $exception) {
                    $failures[] = ['course_offering_id' => (int) $item->course_offering_id, 'reason' => $exception->errorCode ?? 'not_eligible'];
                }
            }
            $failures = [
                ...$failures,
                ...$this->registration->evaluateRegistrationCandidatesForProjection(
                    $student,
                    $add->pluck('courseOffering')->filter(),
                ),
            ];
            $failures = [...$failures, ...$this->requirements->validateProjectedCandidates($student, $add->pluck('courseOffering')->filter(), $projection)];
            foreach ($schedule as $offeringId => $evaluation) {
                if (is_string($evaluation['reason'] ?? null)) {
                    $failures[] = [
                        'course_offering_id' => (int) $offeringId,
                        'reason' => $evaluation['reason'],
                        'conflicts' => $evaluation['conflicts'] ?? [],
                        'incomplete_timetable_sources' => $evaluation['incomplete_timetable_sources'] ?? [],
                    ];
                }
            }
            if ($projectedHours > (int) $projectedBase['max_allowed_hours']) {
                $failures[] = ['course_offering_id' => null, 'reason' => 'credit_limit_exceeded'];
            }
        }

        return [
            'projection' => $projection,
            'hours' => [
                'registered_hours_before' => (int) $baseHours['registered_hours'],
                'removed_hours' => $removedHours,
                'added_hours' => $addedHours,
                'projected_hours' => $projectedHours,
                'max_allowed_hours' => (int) $projectedBase['max_allowed_hours'],
                'remaining_hours' => max((int) $projectedBase['max_allowed_hours'] - $projectedHours, 0),
                'recommended_minimum_hours' => RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS,
                'below_recommended_minimum' => $projectedHours < RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS,
                'official_cgpa' => $projectedBase['official_cgpa'],
            ],
            'schedules' => $schedule,
            'failures' => collect($failures)->unique(fn ($failure): string => ($failure['course_offering_id'] ?? 'term').':'.($failure['reason'] ?? 'unknown'))->values()->all(),
        ];
    }

    private function present(StudentRegistrationModificationRequest $request, Student $student, bool $includeActor, bool $includeProjection): array
    {
        $request->loadMissing([
            'initialRequest', 'academicYear', 'semester', 'advisor.employee',
            'items.courseOffering.course', 'items.sourceRegistration.registrationStatus',
            'items.materializedRegistration.registrationStatus', 'events.actor.employee',
        ]);
        $materializedApproval = $request->status === Workflow::STATUS_APPROVED
            && $request->materialized_at !== null;
        $projection = $includeProjection
            ? $this->projectedDescription($student, $request, false)
            : null;
        $deadline = $this->registration->courseRegistrationDeadlines((int) $request->academic_year_id, (int) $request->semester_id);
        $available = [];
        if ($includeProjection && ! $includeActor && $projection !== null) {
            $addIds = $request->items->where('operation', Workflow::OPERATION_ADD)->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->all();
            $available = $this->registration->getSelfRegistrationOfferings(
                $student,
                (int) $request->academic_year_id,
                (int) $request->semester_id,
                (int) $projection['hours']['added_hours'],
                $addIds,
                $projection['projection'],
            )->map(fn ($offering): array => [
                'course_offering_id' => (int) $offering->course_offering_id,
                'course_id' => (int) $offering->course_id,
                'course_code' => $offering->course?->course_code,
                'course_name' => $offering->course?->course_name,
                'credit_hours' => (int) ($offering->course?->credit_hours ?? 0),
                'eligibility_status' => $offering->getAttribute('eligibility_status'),
                'eligibility_reasons' => $offering->getAttribute('eligibility_reasons') ?? [],
                'official_timetable' => $offering->getAttribute('official_timetable'),
                'timetable_conflicts' => $offering->getAttribute('timetable_conflicts') ?? [],
            ])->values()->all();
        }

        return [
            'student_registration_modification_request_id' => (int) $request->getKey(),
            'initial_registration_request_id' => (int) $request->initial_registration_request_id,
            'student_id' => (int) $request->student_id,
            'student' => [
                'student_id' => (int) $student->student_id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name ?? $student->student_name ?? null,
                'program_name' => $student->academicProgram?->program_name,
            ],
            'academic_year_id' => (int) $request->academic_year_id,
            'semester_id' => (int) $request->semester_id,
            'academic_year' => $request->academicYear?->year_name,
            'semester' => $request->semester?->semester_name,
            'status' => $request->status,
            'submission_version' => (int) $request->submission_version,
            'current_slot' => $request->current_slot,
            'student_notes' => $request->student_notes,
            'advisor_notes' => $request->advisor_notes,
            'first_submitted_at' => $request->first_submitted_at?->utc()->toIso8601String(),
            'last_submitted_at' => $request->last_submitted_at?->utc()->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->utc()->toIso8601String(),
            'approved_at' => $request->approved_at?->utc()->toIso8601String(),
            'expired_at' => $request->expired_at?->utc()->toIso8601String(),
            'superseded_at' => $request->superseded_at?->utc()->toIso8601String(),
            'materialized_at' => $request->materialized_at?->utc()->toIso8601String(),
            'editable' => $request->isEditable() && $deadline->phase === CourseRegistrationPhase::STUDENT_OPEN,
            'advisor_decision_open' => $deadline->isAdvisorDecisionOpen(),
            'deadline' => $deadline->toArray(),
            'items' => $request->items->sortBy('course_offering_id')->map(fn ($item): array => [
                'student_registration_modification_item_id' => (int) $item->getKey(),
                'operation' => $item->operation,
                'course_offering_id' => (int) $item->course_offering_id,
                'source_student_course_registration_id' => $item->source_student_course_registration_id,
                'materialized_student_course_registration_id' => $item->materialized_student_course_registration_id,
                'course' => $item->courseOffering?->course === null ? null : [
                    'course_id' => (int) $item->courseOffering->course->course_id,
                    'course_code' => $item->courseOffering->course->course_code,
                    'course_name' => $item->courseOffering->course->course_name,
                    'credit_hours' => (int) $item->courseOffering->course->credit_hours,
                ],
                'official_timetable' => $projection['schedules'][(int) $item->course_offering_id]['schedule'] ?? null,
            ])->values()->all(),
            'hours' => $materializedApproval
                ? $this->approvalSnapshot($request)
                : ($projection['hours'] ?? $this->approvalSnapshot($request)),
            'failures' => $projection['failures'] ?? [],
            'available_courses' => $available,
            'events' => $request->events->sortBy('student_registration_modification_event_id')->map(fn ($event): array => [
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'submission_version' => $event->submission_version,
                'notes' => $event->notes,
                'created_at' => $event->created_at?->utc()->toIso8601String(),
                'actor' => $includeActor && $event->actor !== null ? ['username' => $event->actor->username] : null,
            ])->values()->all(),
        ];
    }

    private function presentListItem(StudentRegistrationModificationRequest $request, array $deadline): array
    {
        $baselineHours = (int) $request->items
            ->whereNotNull('source_student_course_registration_id')
            ->sum(fn ($item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));
        $removedHours = (int) $request->items->where('operation', Workflow::OPERATION_REMOVE)
            ->sum(fn ($item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));
        $addedHours = (int) $request->items->where('operation', Workflow::OPERATION_ADD)
            ->sum(fn ($item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));

        return [
            'student_registration_modification_request_id' => (int) $request->getKey(),
            'status' => $request->status,
            'submission_version' => (int) $request->submission_version,
            'last_submitted_at' => $request->last_submitted_at?->utc()->toIso8601String(),
            'student' => $request->student === null ? null : [
                'student_id' => (int) $request->student->student_id,
                'student_number' => $request->student->student_number,
                'full_name' => $request->student->full_name ?? $request->student->student_name ?? null,
                'program' => $request->student->academicProgram === null ? null : [
                    'program_name' => $request->student->academicProgram->program_name,
                ],
            ],
            'academic_year' => $request->academicYear === null ? null : ['year_name' => $request->academicYear->year_name],
            'semester' => $request->semester === null ? null : ['semester_name' => $request->semester->semester_name],
            'hours' => [
                'change_hours' => $removedHours + $addedHours,
                'projected_hours' => $request->projected_hours_at_approval ?? ($baselineHours - $removedHours + $addedHours),
                'max_allowed_hours' => $request->max_allowed_hours_at_approval,
            ],
            'registration_calendar' => $deadline,
        ];
    }

    private function approvalSnapshot(StudentRegistrationModificationRequest $request): ?array
    {
        if ($request->approved_at === null) {
            return null;
        }

        return [
            'registered_hours_before' => $request->registered_hours_before_approval,
            'removed_hours' => $request->removed_hours_at_approval,
            'added_hours' => $request->added_hours_at_approval,
            'projected_hours' => $request->projected_hours_at_approval,
            'max_allowed_hours' => $request->max_allowed_hours_at_approval,
            'remaining_hours' => $request->remaining_hours_after_approval,
            'below_recommended_minimum' => (int) $request->projected_hours_at_approval < RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS,
        ];
    }

    private function approvedInitialRequest(Student $student, int $yearId, int $semesterId): ?StudentRegistrationRequest
    {
        return StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $yearId)
            ->where('semester_id', $semesterId)
            ->where('status', StudentRegistrationRequest::STATUS_APPROVED)
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($items) => $items->whereNull('student_course_registration_id'))
            ->first();
    }

    private function lockCurrentForStudent(
        Student $student,
        ?int $requestId = null,
        ?int $semesterId = null,
    ): StudentRegistrationModificationRequest
    {
        $query = StudentRegistrationModificationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('current_slot', Workflow::CURRENT_SLOT);
        if ($requestId !== null) {
            $query->whereKey($requestId);
        }
        if ($semesterId !== null) {
            $query->where('semester_id', $semesterId);
        }

        return $query->lockForUpdate()->firstOrFail();
    }

    private function assertEditable(StudentRegistrationModificationRequest $request): void
    {
        if (! $request->isEditable() || ! $request->isCurrent()) {
            throw new RegistrationRequestException('طلب التعديل غير قابل للتحرير.', [], 409, 'registration_modification_not_editable');
        }
    }

    private function officialTermRegistrationsQuery(Student $student, int $yearId, int $semesterId): Builder
    {
        return StudentCourseRegistration::query()
            ->select('student_course_registrations.*')
            ->join('course_offerings', 'course_offerings.course_offering_id', '=', 'student_course_registrations.course_offering_id')
            ->join('registration_statuses', 'registration_statuses.registration_status_id', '=', 'student_course_registrations.registration_status_id')
            ->where('student_course_registrations.student_id', $student->student_id)
            ->where('course_offerings.academic_year_id', $yearId)
            ->where('course_offerings.semester_id', $semesterId)
            ->where('registration_statuses.status_code', StudentCourseRegistration::CURRENT_STATUS)
            ->with(['courseOffering.course', 'registrationStatus']);
    }

    private function baselineMatches(StudentRegistrationModificationRequest $request, Student $student, bool $lock): bool
    {
        $items = StudentRegistrationModificationItem::query()
            ->where('student_registration_modification_request_id', $request->getKey())
            ->whereNotNull('source_student_course_registration_id')
            ->orderBy('source_student_course_registration_id');
        if ($lock) {
            $items->lockForUpdate();
        }
        $baseline = $items->get();
        $official = $this->officialTermRegistrationsQuery($student, (int) $request->academic_year_id, (int) $request->semester_id)
            ->orderBy('student_course_registrations.student_course_registration_id');
        if ($lock) {
            $official->lockForUpdate();
        }

        return $this->baselineMatchesCollection($baseline, $official->get(), $student, $request);
    }

    private function baselineMatchesCollection(Collection $items, Collection $official, Student $student, StudentRegistrationModificationRequest $request): bool
    {
        $baseline = $items->whereNotNull('source_student_course_registration_id')
            ->map(fn ($item): string => (int) $item->source_student_course_registration_id.':'.(int) $item->course_offering_id)
            ->sort()->values()->all();
        $current = $official->filter(function ($registration) use ($student, $request): bool {
            return (int) $registration->student_id === (int) $student->student_id
                && (int) $registration->courseOffering->academic_year_id === (int) $request->academic_year_id
                && (int) $registration->courseOffering->semester_id === (int) $request->semester_id;
        })->map(fn ($registration): string => (int) $registration->getKey().':'.(int) $registration->course_offering_id)
            ->sort()->values()->all();

        return $baseline === $current;
    }

    private function hasDelta(Collection $items): bool
    {
        return $items->contains(fn ($item): bool => in_array($item->operation, [Workflow::OPERATION_REMOVE, Workflow::OPERATION_ADD], true));
    }

    private function supersede(StudentRegistrationModificationRequest $request, ?User $actor): void
    {
        if (! $request->isCurrent()) {
            return;
        }
        $from = $request->status;
        $request->update([
            'status' => Workflow::STATUS_SUPERSEDED,
            'current_slot' => null,
            'superseded_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->writeEvent($request, Workflow::EVENT_SUPERSEDED, $actor, $from, Workflow::STATUS_SUPERSEDED);
    }

    private function expire(StudentRegistrationModificationRequest $request): void
    {
        if (! $request->isCurrent()) {
            return;
        }
        $from = $request->status;
        $request->update([
            'status' => Workflow::STATUS_EXPIRED,
            'current_slot' => null,
            'expired_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->writeEvent($request, Workflow::EVENT_EXPIRED, null, $from, Workflow::STATUS_EXPIRED);
    }

    private function reconcileStudentExpiration(Student $student, int $yearId, int $semesterId): void
    {
        $request = StudentRegistrationModificationRequest::query()
            ->where('student_id', $student->student_id)->where('academic_year_id', $yearId)->where('semester_id', $semesterId)
            ->where('current_slot', Workflow::CURRENT_SLOT)->first();
        if ($request !== null) {
            $this->reconcileExpiration($request);
        }
    }

    private function reconcileScopedExpirations(User $actor): void
    {
        $this->scopedQuery($actor)->where('current_slot', Workflow::CURRENT_SLOT)
            ->pluck('student_registration_modification_request_id')
            ->each(function ($id): void {
                $request = StudentRegistrationModificationRequest::query()->find($id);
                if ($request !== null) {
                    $this->reconcileExpiration($request);
                }
            });
    }

    private function reconcileExpiration(StudentRegistrationModificationRequest $request): StudentRegistrationModificationRequest
    {
        if (! $request->isCurrent()) {
            return $request;
        }
        $deadline = $this->registration->courseRegistrationDeadlines((int) $request->academic_year_id, (int) $request->semester_id);
        if ($deadline->phase !== CourseRegistrationPhase::CLOSED) {
            return $request;
        }
        DB::transaction(function () use ($request): void {
            $locked = StudentRegistrationModificationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isCurrent()) {
                $this->expire($locked);
            }
        });

        return $request->fresh();
    }

    private function scopedQuery(User $actor): Builder
    {
        return StudentRegistrationModificationRequest::query()->whereHas(
            'student',
            fn (Builder $students) => $this->dataScopes->scopeStaffStudents($students, $actor),
        );
    }

    private function assertCanView(User $actor): void
    {
        if (! $actor->hasPermission('registration_requests.view')) {
            throw new AccessDeniedHttpException('لا تملك صلاحية عرض طلبات تعديل التسجيل.');
        }
    }

    private function assertCanReview(User $actor): void
    {
        if (! $actor->hasPermission('registration_requests.review')) {
            throw new AccessDeniedHttpException('لا تملك صلاحية مراجعة طلبات تعديل التسجيل.');
        }
    }

    private function assertCanAccess(User $actor, StudentRegistrationModificationRequest $request): void
    {
        $request->loadMissing('student');
        if ($request->student === null || ! $this->dataScopes->canStaffAccessStudent($actor, $request->student)) {
            throw new AccessDeniedHttpException('لا تملك نطاق الوصول إلى طلب التعديل.');
        }
    }

    private function assertSchemaReady(): void
    {
        if (! Workflow::schemaReady()) {
            throw new RegistrationRequestException(
                'مخطط تعديل التسجيل غير جاهز.',
                ['schema' => ['registration_modification_schema_not_ready']],
                503,
                'registration_modification_schema_not_ready',
            );
        }
    }

    private function writeEvent(
        StudentRegistrationModificationRequest $request,
        string $type,
        ?User $actor,
        ?string $from,
        ?string $to,
        ?string $notes = null,
    ): void {
        StudentRegistrationModificationEvent::query()->create([
            'student_registration_modification_request_id' => $request->getKey(),
            'event_type' => $type,
            'actor_user_id' => $actor?->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'submission_version' => (int) $request->submission_version,
            'notes' => $notes,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
