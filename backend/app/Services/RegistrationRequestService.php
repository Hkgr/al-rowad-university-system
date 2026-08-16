<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Exceptions\RegistrationRequestException;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestEvent;
use App\Models\StudentRegistrationRequestItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RegistrationRequestService
{
    public const STUDENT_NOTES_MAX = 1000;

    public const ADVISOR_NOTES_MIN = 8;

    public function __construct(
        private RegistrationService $registration,
        private AcademicTermResolver $academicTerms,
        private DataScopeService $dataScopes,
        private AcademicRequirementService $requirements,
    ) {
    }

    public function studentWorkspace(Student $student, ?int $semesterId = null): array
    {
        $year = $this->academicTerms->uniqueCurrentAcademicYear();
        $openSemesters = $year === null
            ? collect()
            : $this->registration->selfRegistrationOpenSemesters($student, (int) $year->academic_year_id);

        $yearRequests = $year === null
            ? collect()
            : $this->currentYearRequests($student, (int) $year->academic_year_id);

        $selectableSemesters = $this->selectableSemesters($openSemesters, $yearRequests);

        $semester = $this->resolveWorkspaceSemester($selectableSemesters, $openSemesters, $yearRequests, $semesterId);
        $registrationOpen = $semester !== null
            && $openSemesters->contains(fn ($open) => (int) $open->semester_id === (int) $semester->semester_id);

        $request = null;
        $available = collect();
        $summary = null;
        $hours = null;

        if ($year !== null && $semester !== null) {
            $request = $yearRequests->first(
                fn (StudentRegistrationRequest $candidate): bool => (int) $candidate->semester_id === (int) $semester->semester_id
            );
            $hours = $this->hoursFor($student, (int) $year->academic_year_id, (int) $semester->semester_id, $request);
            if ($registrationOpen) {
                $available = $this->registration->getSelfRegistrationOfferings(
                    $student,
                    (int) $year->academic_year_id,
                    (int) $semester->semester_id,
                    (int) ($hours['live']['request_hours'] ?? $hours['request_hours']),
                    $this->requestOfferingIds($request)
                );
            }
            $summary = $this->registration->getRegistrationSummary(
                $student,
                (int) $year->academic_year_id,
                (int) $semester->semester_id
            );
        } elseif ($year !== null) {
            $summary = $this->registration->getRegistrationSummary(
                $student,
                (int) $year->academic_year_id,
                $semesterId
            );
        }

        return [
            'registration_open' => $registrationOpen,
            'academic_year' => $year,
            'semester' => $semester,
            'semesters' => $selectableSemesters,
            'available_courses' => $available,
            'summary' => $summary,
            'hours' => $hours,
            'request' => $request === null ? null : $this->presentRequest($request, $student, includeActor: false),
        ];
    }

    public function addItem(Student $student, CourseOffering $offering, User $actor): StudentRegistrationRequest
    {
        $year = $this->requireUniqueCurrentYear();
        $offering->loadMissing('course');
        $this->registration->assertSelfRegistrationAllowed($student, $offering);

        if ((int) $offering->academic_year_id !== (int) $year->academic_year_id) {
            throw new RegistrationRequestException('The selected course offering is not open for the current academic term.', [
                'course_offering_id' => ['The selected course offering is not open for the current academic term.'],
            ]);
        }

        $openSemesters = $this->registration->selfRegistrationOpenSemesters($student, (int) $year->academic_year_id);
        if ($openSemesters->firstWhere('semester_id', (int) $offering->semester_id) === null) {
            throw new RegistrationRequestException('The selected course offering is not open for student registration in this term.', [
                'course_offering_id' => ['The selected course offering is not open for student registration in this term.'],
            ]);
        }

        return DB::transaction(function () use ($student, $offering, $actor, $year): StudentRegistrationRequest {
            $request = $this->lockOrCreateEditableRequest(
                $student,
                (int) $year->academic_year_id,
                (int) $offering->semester_id,
                $actor
            );
            $student = $this->lockStudentRow($student);

            $registeredIds = $this->registration->currentOfferingIds($student);
            if (in_array((int) $offering->course_offering_id, $registeredIds, true)) {
                throw new RegistrationRequestException('Student is already registered in this course offering.', [
                    'course_offering_id' => ['already_registered'],
                ]);
            }

            $exists = $request->items()
                ->where('course_offering_id', $offering->course_offering_id)
                ->exists();
            if ($exists) {
                throw new RegistrationRequestException('This course is already included in the registration request.', [
                    'course_offering_id' => ['already_in_request'],
                ]);
            }

            $failure = $this->itemFailureReason(
                $student,
                $offering->fresh() ?? $offering,
                $this->registration->hoursSnapshot($student, (int) $year->academic_year_id, (int) $offering->semester_id),
                $registeredIds,
                $this->requestHours($request, $registeredIds),
                $this->requirements->buildRegistrationCommitmentContext($student)
            );
            if ($failure !== null) {
                throw new RegistrationRequestException('This course cannot be added to the registration request.', [
                    'course_offering_id' => [$failure],
                ]);
            }

            $item = $request->items()->create([
                'course_offering_id' => $offering->course_offering_id,
                'student_course_registration_id' => null,
            ]);

            $this->writeEvent(
                $request,
                StudentRegistrationRequestEvent::TYPE_ITEM_ADDED,
                $actor,
                $request->status,
                $request->status,
                'course_offering_id='.$item->course_offering_id
            );

            return $this->freshRequest($request);
        });
    }

    public function studentRequestView(Student $student, StudentRegistrationRequest $request): array
    {
        $this->assertStudentOwns($student, $request);

        return $this->presentRequest($this->freshRequest($request), $student, includeActor: false);
    }

    public function removeItem(Student $student, StudentRegistrationRequestItem $item, User $actor): StudentRegistrationRequest
    {
        $this->requireUniqueCurrentYear();

        return DB::transaction(function () use ($student, $item, $actor): StudentRegistrationRequest {
            $item = StudentRegistrationRequestItem::query()
                ->with('request')
                ->lockForUpdate()
                ->findOrFail($item->student_registration_request_item_id);

            $request = StudentRegistrationRequest::query()
                ->lockForUpdate()
                ->findOrFail($item->student_registration_request_id);

            $this->assertStudentOwns($student, $request);
            $this->assertEditable($request);
            $this->assertSemesterOpenForEdits($student, (int) $request->academic_year_id, (int) $request->semester_id);

            $offeringId = (int) $item->course_offering_id;
            $item->delete();

            $this->writeEvent(
                $request,
                StudentRegistrationRequestEvent::TYPE_ITEM_REMOVED,
                $actor,
                $request->status,
                $request->status,
                'course_offering_id='.$offeringId
            );

            return $this->freshRequest($request);
        });
    }

    public function updateNotes(Student $student, ?string $notes, User $actor, ?int $semesterId = null): StudentRegistrationRequest
    {
        $year = $this->requireUniqueCurrentYear();
        $semesterId = $this->resolveOpenSemesterId($student, (int) $year->academic_year_id, $semesterId);
        $normalized = $this->normalizeStudentNotes($notes);

        return DB::transaction(function () use ($student, $normalized, $actor, $year, $semesterId): StudentRegistrationRequest {
            $request = $this->lockOrCreateEditableRequest(
                $student,
                (int) $year->academic_year_id,
                $semesterId,
                $actor
            );
            $request->update(['student_notes' => $normalized]);

            return $this->freshRequest($request);
        });
    }

    public function submit(Student $student, User $actor, ?int $semesterId = null): StudentRegistrationRequest
    {
        $year = $this->requireUniqueCurrentYear();
        $existing = $this->findRequestForSubmit($student, (int) $year->academic_year_id, $semesterId);
        if ($existing !== null) {
            $this->assertSemesterOpenForSubmit($student, (int) $year->academic_year_id, (int) $existing->semester_id);
            $semesterId = (int) $existing->semester_id;
        }
        $semesterId = $this->resolveOpenSemesterId($student, (int) $year->academic_year_id, $semesterId);

        return DB::transaction(function () use ($student, $actor, $year, $semesterId): StudentRegistrationRequest {
            $request = $this->lockExistingRequest($student, (int) $year->academic_year_id, $semesterId);
            $this->assertEditable($request);
            $student = $this->lockStudentRow($student);
            $request->loadMissing(['items.courseOffering.course']);

            if ($request->items->isEmpty()) {
                throw new RegistrationRequestException('A registration request must include at least one course.', [
                    'items' => ['A registration request must include at least one course.'],
                ]);
            }

            $failures = $this->collectItemFailures($student, $request);
            if ($failures !== []) {
                throw new RegistrationRequestException(
                    'One or more courses are no longer eligible for registration.',
                    ['items' => ['One or more courses are no longer eligible for registration.']],
                    422,
                    'registration_request_invalid',
                    $failures
                );
            }

            $from = $request->status;
            $now = now();
            $version = (int) $request->submission_version + 1;
            $request->update([
                'status' => StudentRegistrationRequest::STATUS_SUBMITTED,
                'submission_version' => $version,
                'first_submitted_at' => $request->first_submitted_at ?? $now,
                'last_submitted_at' => $now,
                'reviewed_at' => null,
            ]);

            $this->writeEvent(
                $request,
                $from === StudentRegistrationRequest::STATUS_RETURNED
                    ? StudentRegistrationRequestEvent::TYPE_RESUBMITTED
                    : StudentRegistrationRequestEvent::TYPE_SUBMITTED,
                $actor,
                $from,
                StudentRegistrationRequest::STATUS_SUBMITTED
            );

            return $this->freshRequest($request);
        });
    }

    public function advisorIndex(User $user, ?string $status = null): array
    {
        $this->assertCanViewRequests($user);

        $query = $this->scopedRequestsQuery($user)
            ->with([
                'student.academicProgram',
                'student.currentAcademicLevel',
                'academicYear',
                'semester',
                'items.courseOffering.course',
            ]);

        $counts = [
            'submitted' => (clone $query)->where('status', StudentRegistrationRequest::STATUS_SUBMITTED)->count(),
            'returned' => (clone $query)->where('status', StudentRegistrationRequest::STATUS_RETURNED)->count(),
            'approved' => (clone $query)->where('status', StudentRegistrationRequest::STATUS_APPROVED)->count(),
        ];

        $status = $status ?: StudentRegistrationRequest::STATUS_SUBMITTED;
        if (! in_array($status, [
            StudentRegistrationRequest::STATUS_SUBMITTED,
            StudentRegistrationRequest::STATUS_RETURNED,
            StudentRegistrationRequest::STATUS_APPROVED,
        ], true)) {
            $status = StudentRegistrationRequest::STATUS_SUBMITTED;
        }

        $requests = $query
            ->where('status', $status)
            ->orderByDesc('last_submitted_at')
            ->orderByDesc('student_registration_request_id')
            ->get()
            ->map(fn (StudentRegistrationRequest $request) => $this->presentAdvisorListItem($request))
            ->values()
            ->all();

        return [
            'summary' => $counts,
            'status' => $status,
            'requests' => $requests,
        ];
    }

    public function advisorShow(User $user, StudentRegistrationRequest $request): array
    {
        $this->assertCanViewRequests($user);
        $this->assertCanAccessRequest($user, $request);
        $this->assertAdvisorVisible($request);

        $request = $this->freshRequest($request);

        return $this->presentRequest($request, $request->student, includeActor: true, includeEligibility: true);
    }

    public function returnForModification(User $user, StudentRegistrationRequest $request, string $notes): array
    {
        $this->assertCanReviewRequests($user);
        $this->assertCanAccessRequest($user, $request);
        $this->assertAdvisorVisible($request);
        $normalized = trim($notes);
        if (mb_strlen($normalized) < self::ADVISOR_NOTES_MIN) {
            throw new RegistrationRequestException('A return reason is required.', [
                'advisor_notes' => ['A return reason is required.'],
            ]);
        }

        return DB::transaction(function () use ($user, $request, $normalized): array {
            $locked = StudentRegistrationRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->student_registration_request_id);

            $this->assertCanAccessRequest($user, $locked);
            $this->assertAdvisorVisible($locked);

            if (! $locked->isSubmitted()) {
                throw new ConflictHttpException('Only a submitted registration request can be returned for modification.');
            }

            $from = $locked->status;
            $now = now();
            $locked->update([
                'status' => StudentRegistrationRequest::STATUS_RETURNED,
                'advisor_user_id' => $user->user_id,
                'advisor_notes' => $normalized,
                'reviewed_at' => $now,
            ]);

            $this->writeEvent(
                $locked,
                StudentRegistrationRequestEvent::TYPE_RETURNED,
                $user,
                $from,
                StudentRegistrationRequest::STATUS_RETURNED,
                $normalized
            );

            return $this->presentRequest($this->freshRequest($locked), $locked->student, includeActor: true, includeEligibility: true);
        });
    }

    public function approve(User $user, StudentRegistrationRequest $request): array
    {
        $this->assertCanReviewRequests($user);
        $this->assertCanAccessRequest($user, $request);
        $this->assertAdvisorVisible($request);
        $this->requireUniqueCurrentYear();

        $currentOfferingId = 0;
        try {
            return DB::transaction(function () use ($user, $request, &$currentOfferingId): array {
                $locked = StudentRegistrationRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($request->student_registration_request_id);

                $this->assertAdvisorVisible($locked);
                $this->assertCanAccessRequest($user, $locked);

                if ($locked->isApproved()) {
                    return $this->presentRequest(
                        $this->freshRequest($locked),
                        $locked->student,
                        includeActor: true,
                        includeEligibility: true
                    );
                }

                if (! $locked->isSubmitted()) {
                    throw new ConflictHttpException('Only a submitted registration request can be approved.');
                }

                $student = Student::query()
                    ->whereKey($locked->student_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->loadMissing(['items.courseOffering.course']);
                $offeringIds = $locked->items
                    ->pluck('course_offering_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($offeringIds === []) {
                    throw new RegistrationRequestException('A registration request must include at least one course.', [
                        'items' => ['A registration request must include at least one course.'],
                    ], 409, 'registration_request_empty');
                }

                CourseOffering::query()
                    ->whereIn('course_offering_id', $offeringIds)
                    ->orderBy('course_offering_id')
                    ->lockForUpdate()
                    ->get();

                StudentCourseRegistration::query()
                    ->where('student_id', $student->student_id)
                    ->whereIn('course_offering_id', $offeringIds)
                    ->orderBy('student_course_registration_id')
                    ->lockForUpdate()
                    ->get();

                $locked->unsetRelation('items');
                $locked->load(['items.courseOffering.course']);

                $failures = $this->collectItemFailures($student, $locked);
                if ($failures !== []) {
                    throw new RegistrationRequestException(
                        'Approval was blocked because one or more courses are no longer eligible.',
                        ['items' => ['Approval was blocked because one or more courses are no longer eligible.']],
                        409,
                        'registration_request_approval_failed',
                        $failures
                    );
                }

                $hours = $this->registration->hoursSnapshot(
                    $student,
                    (int) $locked->academic_year_id,
                    (int) $locked->semester_id
                );
                $registeredIds = $this->registration->currentOfferingIds($student);
                $requestHours = $this->requestHours($locked, $registeredIds);
                $registeredHours = (int) $hours['registered_hours'];
                $maxAllowedHours = (int) $hours['max_allowed_hours'];
                $projectedHours = $registeredHours + $requestHours;
                $snapshot = [
                    'registered_hours_before_approval' => $registeredHours,
                    'request_hours_at_approval' => $requestHours,
                    'projected_hours_at_approval' => $projectedHours,
                    'max_allowed_hours_at_approval' => $maxAllowedHours,
                    'remaining_hours_after_approval' => max($maxAllowedHours - $projectedHours, 0),
                ];

                $now = now();
                $registrations = [];
                foreach ($locked->items->sortBy('student_registration_request_item_id') as $item) {
                    $currentOfferingId = (int) $item->course_offering_id;
                    $result = $this->registration->registerStudentWithinTransaction(
                        [
                            'student_id' => $student->student_id,
                            'course_offering_id' => $item->course_offering_id,
                            'advisor_user_id' => $user->user_id,
                            'registration_date' => $now->toDateString(),
                        ],
                        (int) $user->user_id
                    );
                    $registration = $result['registration'];
                    $item->update([
                        'student_course_registration_id' => $registration->student_course_registration_id,
                    ]);
                    $registrations[] = $registration;
                }

                $from = $locked->status;
                $locked->update(array_merge([
                    'status' => StudentRegistrationRequest::STATUS_APPROVED,
                    'advisor_user_id' => $user->user_id,
                    'reviewed_at' => $now,
                    'approved_at' => $now,
                ], $snapshot));

                $this->writeEvent(
                    $locked,
                    StudentRegistrationRequestEvent::TYPE_APPROVED,
                    $user,
                    $from,
                    StudentRegistrationRequest::STATUS_APPROVED
                );

                return $this->presentRequest(
                    $this->freshRequest($locked),
                    $student,
                    includeActor: true,
                    includeEligibility: false,
                    registrations: $registrations
                );
            });
        } catch (RegistrationException $exception) {
            $offeringId = $this->offeringIdFromRegistrationException($exception, $currentOfferingId);
            $failures = $offeringId > 0
                ? [['course_offering_id' => $offeringId, 'reason' => $this->mapRegistrationException($exception)]]
                : [];
            throw new RegistrationRequestException(
                $exception->getMessage(),
                $exception->errors,
                409,
                'registration_request_approval_failed',
                $failures
            );
        } catch (QueryException $exception) {
            $offeringId = (int) $currentOfferingId;
            $failures = $offeringId > 0
                ? [['course_offering_id' => $offeringId, 'reason' => 'already_registered']]
                : [];
            throw new RegistrationRequestException(
                'Approval was blocked because a course could not be finalized without duplication.',
                ['items' => ['already_registered']],
                409,
                'registration_request_approval_failed',
                $failures
            );
        }
    }

    public function approvedIndex(User $user): array
    {
        if (! $user->hasPermission('registration.view')) {
            throw new AccessDeniedHttpException('You do not have permission to view approved registration requests.');
        }

        $requests = $this->scopedRequestsQuery($user)
            ->where('status', StudentRegistrationRequest::STATUS_APPROVED)
            ->with([
                'student.academicProgram',
                'academicYear',
                'semester',
                'advisor.employee',
                'items.courseOffering.course',
            ])
            ->orderByDesc('approved_at')
            ->orderByDesc('student_registration_request_id')
            ->get()
            ->map(fn (StudentRegistrationRequest $request) => $this->presentApprovedListItem($request))
            ->values()
            ->all();

        return [
            'requests' => $requests,
        ];
    }

    private function requireUniqueCurrentYear()
    {
        $year = $this->academicTerms->uniqueCurrentAcademicYear();
        if ($year === null) {
            throw new RegistrationRequestException('The current academic year is not uniquely configured.', [
                'academic_year_id' => ['The current academic year is not uniquely configured.'],
            ]);
        }

        return $year;
    }

    private function resolveOpenSemesterId(Student $student, int $academicYearId, ?int $semesterId): int
    {
        $openSemesters = $this->registration->selfRegistrationOpenSemesters($student, $academicYearId);
        if ($openSemesters->isEmpty()) {
            throw new RegistrationRequestException(
                'انتهت فترة التسجيل الذاتي ولا يمكن تقديم أو إعادة تقديم الطلب حالياً.',
                ['semester_id' => ['انتهت فترة التسجيل الذاتي ولا يمكن تقديم أو إعادة تقديم الطلب حالياً.']]
            );
        }
        if ($semesterId !== null) {
            $match = $openSemesters->firstWhere('semester_id', $semesterId);
            if ($match === null) {
                throw new RegistrationRequestException('The selected semester is not open for student registration.', [
                    'semester_id' => ['The selected semester is not open for student registration.'],
                ]);
            }

            return (int) $match->semester_id;
        }

        if ($openSemesters->count() !== 1) {
            throw new RegistrationRequestException('A registration semester must be selected.', [
                'semester_id' => ['A registration semester must be selected.'],
            ]);
        }

        return (int) $openSemesters->first()->semester_id;
    }

    private function currentYearRequests(Student $student, int $academicYearId): Collection
    {
        return StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->with($this->requestRelations())
            ->orderByDesc('student_registration_request_id')
            ->get();
    }

    private function selectableSemesters(Collection $openSemesters, Collection $yearRequests): Collection
    {
        $selectable = $openSemesters->values();
        foreach ($yearRequests as $request) {
            $semester = $request->semester;
            if ($semester === null) {
                continue;
            }
            if (! $selectable->contains(fn ($item) => (int) $item->semester_id === (int) $semester->semester_id)) {
                $selectable->push($semester);
            }
        }

        return $selectable->sortBy('semester_id')->values();
    }

    private function resolveWorkspaceSemester(
        Collection $selectable,
        Collection $openSemesters,
        Collection $yearRequests,
        ?int $semesterId
    ) {
        if ($semesterId !== null) {
            return $selectable->firstWhere('semester_id', $semesterId);
        }

        if ($selectable->count() === 1) {
            return $selectable->first();
        }

        $preferredRequest = $yearRequests->first(
            function (StudentRegistrationRequest $request) use ($openSemesters): bool {
                return $openSemesters->contains(
                    fn ($open) => (int) $open->semester_id === (int) $request->semester_id
                );
            }
        ) ?? $yearRequests->first();

        if ($preferredRequest !== null) {
            return $selectable->firstWhere('semester_id', (int) $preferredRequest->semester_id);
        }

        return $openSemesters->count() === 1 ? $openSemesters->first() : null;
    }

    private function findRequestForSubmit(Student $student, int $academicYearId, ?int $semesterId): ?StudentRegistrationRequest
    {
        $query = StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId);

        if ($semesterId !== null) {
            $query->where('semester_id', $semesterId);
        }

        return $query->orderByDesc('student_registration_request_id')->first();
    }

    private function assertSemesterOpenForEdits(Student $student, int $academicYearId, int $semesterId): void
    {
        $openSemesters = $this->registration->selfRegistrationOpenSemesters($student, $academicYearId);
        if ($openSemesters->firstWhere('semester_id', $semesterId) === null) {
            throw new RegistrationRequestException(
                'انتهت فترة التسجيل الذاتي ولا يمكن تعديل الطلب حالياً.',
                ['semester_id' => ['انتهت فترة التسجيل الذاتي ولا يمكن تعديل الطلب حالياً.']]
            );
        }
    }

    private function assertSemesterOpenForSubmit(Student $student, int $academicYearId, int $semesterId): void
    {
        $openSemesters = $this->registration->selfRegistrationOpenSemesters($student, $academicYearId);
        if ($openSemesters->firstWhere('semester_id', $semesterId) === null) {
            throw new RegistrationRequestException(
                'انتهت فترة التسجيل الذاتي ولا يمكن تقديم أو إعادة تقديم الطلب حالياً.',
                ['semester_id' => ['انتهت فترة التسجيل الذاتي ولا يمكن تقديم أو إعادة تقديم الطلب حالياً.']]
            );
        }
    }

    private function assertAdvisorVisible(StudentRegistrationRequest $request): void
    {
        if ($request->status === StudentRegistrationRequest::STATUS_DRAFT) {
            throw (new ModelNotFoundException)->setModel(
                StudentRegistrationRequest::class,
                [$request->student_registration_request_id]
            );
        }
    }

    private function offeringIdFromRegistrationException(RegistrationException $exception, int $fallbackOfferingId): int
    {
        if ($fallbackOfferingId > 0) {
            return $fallbackOfferingId;
        }

        return 0;
    }

    private function findRequest(Student $student, int $academicYearId, int $semesterId): ?StudentRegistrationRequest
    {
        return StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->with($this->requestRelations())
            ->first();
    }

    private function lockStudentRow(Student $student): Student
    {
        return Student::query()
            ->whereKey($student->student_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockExistingRequest(Student $student, int $academicYearId, int $semesterId): StudentRegistrationRequest
    {
        $request = StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw new RegistrationRequestException('No registration request exists for this term.', [
                'request' => ['No registration request exists for this term.'],
            ]);
        }

        $this->assertStudentOwns($student, $request);

        return $request;
    }

    private function lockOrCreateEditableRequest(
        Student $student,
        int $academicYearId,
        int $semesterId,
        User $actor
    ): StudentRegistrationRequest {
        $request = StudentRegistrationRequest::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            try {
                $request = StudentRegistrationRequest::query()->create([
                    'student_id' => $student->student_id,
                    'academic_year_id' => $academicYearId,
                    'semester_id' => $semesterId,
                    'status' => StudentRegistrationRequest::STATUS_DRAFT,
                    'submission_version' => 0,
                ]);
                $this->writeEvent(
                    $request,
                    StudentRegistrationRequestEvent::TYPE_DRAFT_CREATED,
                    $actor,
                    null,
                    StudentRegistrationRequest::STATUS_DRAFT
                );
            } catch (QueryException $exception) {
                $request = StudentRegistrationRequest::query()
                    ->where('student_id', $student->student_id)
                    ->where('academic_year_id', $academicYearId)
                    ->where('semester_id', $semesterId)
                    ->lockForUpdate()
                    ->first();
                if ($request === null) {
                    throw $exception;
                }
            }
        }

        $this->assertStudentOwns($student, $request);
        $this->assertEditable($request);

        return $request;
    }

    private function assertStudentOwns(Student $student, StudentRegistrationRequest $request): void
    {
        if ((int) $request->student_id !== (int) $student->student_id) {
            throw new AccessDeniedHttpException('You can only access your own registration request.');
        }
    }

    private function assertEditable(StudentRegistrationRequest $request): void
    {
        if ($request->isApproved()) {
            throw new RegistrationRequestException('An approved registration request cannot be modified.', [
                'status' => ['An approved registration request cannot be modified.'],
            ]);
        }

        if ($request->isSubmitted()) {
            throw new RegistrationRequestException('A submitted registration request cannot be modified while under review.', [
                'status' => ['A submitted registration request cannot be modified while under review.'],
            ]);
        }

        if (! $request->isEditable()) {
            throw new RegistrationRequestException('This registration request cannot be modified.', [
                'status' => ['This registration request cannot be modified.'],
            ]);
        }
    }

    private function assertCanViewRequests(User $user): void
    {
        if (! $user->hasPermission('registration_requests.view')) {
            throw new AccessDeniedHttpException('You do not have permission to view registration requests.');
        }
    }

    private function assertCanReviewRequests(User $user): void
    {
        if (! $user->hasPermission('registration_requests.review')) {
            throw new AccessDeniedHttpException('You do not have permission to review registration requests.');
        }
    }

    private function assertCanAccessRequest(User $user, StudentRegistrationRequest $request): void
    {
        $request->loadMissing('student');
        if ($request->student === null || ! $this->dataScopes->canStaffAccessStudent($user, $request->student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this registration request.');
        }
    }

    private function scopedRequestsQuery(User $user): Builder
    {
        return StudentRegistrationRequest::query()
            ->whereIn('status', [
                StudentRegistrationRequest::STATUS_SUBMITTED,
                StudentRegistrationRequest::STATUS_RETURNED,
                StudentRegistrationRequest::STATUS_APPROVED,
            ])
            ->whereHas(
                'student',
                fn (Builder $student) => $this->dataScopes->scopeStaffStudents($student, $user)
            );
    }

    private function hoursFor(
        Student $student,
        int $academicYearId,
        int $semesterId,
        ?StudentRegistrationRequest $request
    ): array {
        $base = $this->registration->hoursSnapshot($student, $academicYearId, $semesterId);
        $registeredIds = $this->registration->currentOfferingIds($student);
        $liveRequestHours = $this->requestHours($request, $registeredIds);
        $liveRegistered = (int) $base['registered_hours'];
        $liveMax = (int) $base['max_allowed_hours'];
        $liveProjected = $liveRegistered + $liveRequestHours;
        $live = [
            'registered_hours' => $liveRegistered,
            'request_hours' => $liveRequestHours,
            'projected_hours' => $liveProjected,
            'max_allowed_hours' => $liveMax,
            'remaining_before_request' => max($liveMax - $liveRegistered, 0),
            'remaining_after_approval' => max($liveMax - $liveProjected, 0),
        ];

        $approvedSnapshot = null;
        if (
            $request !== null
            && $request->isApproved()
            && $request->request_hours_at_approval !== null
        ) {
            $approvedSnapshot = [
                'registered_hours_before_approval' => (int) $request->registered_hours_before_approval,
                'request_hours_at_approval' => (int) $request->request_hours_at_approval,
                'projected_hours_at_approval' => (int) $request->projected_hours_at_approval,
                'max_allowed_hours_at_approval' => (int) $request->max_allowed_hours_at_approval,
                'remaining_hours_after_approval' => (int) $request->remaining_hours_after_approval,
            ];
        }

        return [
            'source' => $approvedSnapshot !== null ? 'approved_snapshot' : 'live',
            'registered_hours' => $liveRegistered,
            'request_hours' => $liveRequestHours,
            'projected_hours' => $liveProjected,
            'max_allowed_hours' => $liveMax,
            'remaining_before_request' => $live['remaining_before_request'],
            'remaining_after_approval' => $live['remaining_after_approval'],
            'live' => $live,
            'approved_snapshot' => $approvedSnapshot,
        ];
    }

    private function requestHours(?StudentRegistrationRequest $request, array $registeredOfferingIds): int
    {
        if ($request === null) {
            return 0;
        }

        $request->loadMissing('items.courseOffering.course');

        return (int) $request->items
            ->filter(fn (StudentRegistrationRequestItem $item): bool => ! in_array(
                (int) $item->course_offering_id,
                $registeredOfferingIds,
                true
            ))
            ->sum(fn (StudentRegistrationRequestItem $item): int => (int) ($item->courseOffering?->course?->credit_hours ?? 0));
    }

    private function requestOfferingIds(?StudentRegistrationRequest $request): array
    {
        if ($request === null) {
            return [];
        }

        $request->loadMissing('items');

        return $request->items
            ->pluck('course_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function collectItemFailures(Student $student, StudentRegistrationRequest $request): array
    {
        $request->loadMissing('items.courseOffering.course');
        $hours = $this->registration->hoursSnapshot(
            $student,
            (int) $request->academic_year_id,
            (int) $request->semester_id
        );
        $registeredIds = $this->registration->currentOfferingIds($student);
        $requirementContext = $this->requirements->buildRegistrationCommitmentContext($student);
        $runningRequestHours = 0;
        $failures = [];
        $failedOfferingIds = [];

        foreach ($request->items->sortBy('student_registration_request_item_id') as $item) {
            $offering = $item->courseOffering;
            if ($offering === null) {
                $failures[] = [
                    'course_offering_id' => (int) $item->course_offering_id,
                    'reason' => 'offering_not_found',
                ];
                $failedOfferingIds[(int) $item->course_offering_id] = true;
                continue;
            }

            $reason = $this->itemFailureReason(
                $student,
                $offering,
                $hours,
                $registeredIds,
                $runningRequestHours,
                $requirementContext
            );
            if ($reason !== null) {
                $failures[] = [
                    'course_offering_id' => (int) $offering->course_offering_id,
                    'reason' => $reason,
                ];
                $failedOfferingIds[(int) $offering->course_offering_id] = true;
                continue;
            }

            if (! in_array((int) $offering->course_offering_id, $registeredIds, true)) {
                $runningRequestHours += (int) ($offering->course?->credit_hours ?? 0);
            }
        }

        foreach ($this->requirements->validateRegistrationRequestCommitment($student, $request, $requirementContext) as $failure) {
            $offeringId = (int) $failure['course_offering_id'];
            if (isset($failedOfferingIds[$offeringId])) {
                continue;
            }

            $failures[] = $failure;
            $failedOfferingIds[$offeringId] = true;
        }

        return $failures;
    }

    private function itemFailureReason(
        Student $student,
        CourseOffering $offering,
        array $hours,
        array $registeredOfferingIds,
        int $runningRequestHours,
        ?array $requirementContext = null
    ): ?string {
        try {
            $this->registration->assertSelfRegistrationAllowed($student, $offering);
        } catch (RegistrationException $exception) {
            return $this->mapRegistrationException($exception);
        }

        if (in_array((int) $offering->course_offering_id, $registeredOfferingIds, true)) {
            return 'already_registered';
        }

        $missing = $this->registration->getMissingPrerequisites($student, (int) $offering->course_id);
        if ($missing !== []) {
            return 'missing_prerequisites';
        }

        if ((int) $offering->available_seats <= 0) {
            return 'no_available_seats';
        }

        $courseHours = (int) ($offering->course?->credit_hours ?? 0);
        if (((int) $hours['registered_hours'] + $runningRequestHours + $courseHours) > (int) $hours['max_allowed_hours']) {
            return 'credit_limit_exceeded';
        }

        $evaluation = $this->requirements->evaluateRegistrationCandidate($student, $offering, $requirementContext);
        if (! $evaluation['allowed']) {
            return (string) $evaluation['reason'];
        }

        return null;
    }

    private function mapRegistrationException(RegistrationException $exception): string
    {
        if (is_string($exception->errorCode) && $exception->errorCode !== '') {
            return $exception->errorCode;
        }

        $message = $exception->getMessage();
        if (str_contains($message, 'not open for registration')) {
            return 'offering_closed';
        }
        if (str_contains($message, 'No available seats') || str_contains($message, 'no available seats')) {
            return 'no_available_seats';
        }
        if (str_contains($message, 'Credit hour limit')) {
            return 'credit_limit_exceeded';
        }
        if (str_contains($message, 'already registered')) {
            return 'already_registered';
        }
        if (str_contains($message, 'missing prerequisites')) {
            return 'missing_prerequisites';
        }
        if (str_contains($message, 'not available for this academic program')) {
            return 'wrong_program';
        }
        if (str_contains($message, 'current academic term')) {
            return 'not_current_term';
        }
        if (str_contains($message, 'program curriculum')) {
            return AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM;
        }
        if (str_contains($message, 'not assigned to an academic program')) {
            return 'no_program';
        }
        if (str_contains($message, 'complete academic term')) {
            return 'incomplete_term';
        }
        if (str_contains($message, 'self-registration')) {
            return 'not_self_registrable';
        }

        return 'not_eligible';
    }

    private function writeEvent(
        StudentRegistrationRequest $request,
        string $type,
        User $actor,
        ?string $from,
        ?string $to,
        ?string $notes = null
    ): void {
        StudentRegistrationRequestEvent::query()->create([
            'student_registration_request_id' => $request->student_registration_request_id,
            'event_type' => $type,
            'actor_user_id' => $actor->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'submission_version' => $request->submission_version,
            'notes' => $notes,
        ]);
    }

    private function normalizeStudentNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::STUDENT_NOTES_MAX) {
            throw new RegistrationRequestException('Student notes may not exceed 1000 characters.', [
                'student_notes' => ['Student notes may not exceed 1000 characters.'],
            ]);
        }

        return $trimmed;
    }

    private function freshRequest(StudentRegistrationRequest $request): StudentRegistrationRequest
    {
        return StudentRegistrationRequest::query()
            ->with($this->requestRelations())
            ->findOrFail($request->student_registration_request_id);
    }

    private function requestRelations(): array
    {
        return [
            'student.academicProgram',
            'student.currentAcademicLevel',
            'academicYear',
            'semester',
            'advisor.employee',
            'items.courseOffering.course',
            'items.studentCourseRegistration.registrationStatus',
            'events',
        ];
    }

    private function presentRequest(
        StudentRegistrationRequest $request,
        ?Student $student,
        bool $includeActor,
        bool $includeEligibility = false,
        array $registrations = []
    ): array {
        $student ??= $request->student;
        $hours = $this->hoursFor(
            $student,
            (int) $request->academic_year_id,
            (int) $request->semester_id,
            $request
        );
        $registeredIds = $this->registration->currentOfferingIds($student);
        $failureByOffering = [];
        if ($includeEligibility) {
            foreach ($this->collectItemFailures($student, $request) as $failure) {
                $failureByOffering[(int) $failure['course_offering_id']] = $failure['reason'];
            }
        }

        $items = $request->items
            ->sortBy('student_registration_request_item_id')
            ->map(function (StudentRegistrationRequestItem $item) use (
                $includeEligibility,
                $failureByOffering
            ): array {
                $offering = $item->courseOffering;
                $payload = [
                    'student_registration_request_item_id' => $item->student_registration_request_item_id,
                    'course_offering_id' => $item->course_offering_id,
                    'student_course_registration_id' => $item->student_course_registration_id,
                    'course_code' => $offering?->course?->course_code,
                    'course_name' => $offering?->course?->course_name,
                    'credit_hours' => (int) ($offering?->course?->credit_hours ?? 0),
                    'available_seats' => $offering?->available_seats,
                    'capacity' => $offering?->capacity,
                    'offering_status' => $offering?->status,
                ];

                if ($includeEligibility) {
                    $reason = $failureByOffering[(int) $item->course_offering_id] ?? null;
                    $payload['eligibility_reason'] = $reason;
                    $payload['eligibility_status'] = $reason === null ? 'eligible' : 'not_eligible';
                }

                return $payload;
            })
            ->values()
            ->all();

        return [
            'student_registration_request_id' => $request->student_registration_request_id,
            'status' => $request->status,
            'submission_version' => (int) $request->submission_version,
            'student_notes' => $request->student_notes,
            'advisor_notes' => $request->advisor_notes,
            'first_submitted_at' => optional($request->first_submitted_at)?->toDateTimeString(),
            'last_submitted_at' => optional($request->last_submitted_at)?->toDateTimeString(),
            'reviewed_at' => optional($request->reviewed_at)?->toDateTimeString(),
            'approved_at' => optional($request->approved_at)?->toDateTimeString(),
            'student' => $this->compactStudent($request->student),
            'academic_year' => $this->compactYear($request->academicYear),
            'semester' => $this->compactSemester($request->semester),
            'advisor' => $this->compactAdvisor($request->advisor),
            'hours' => $hours,
            'items' => $items,
            'history' => $this->presentHistory($request, $includeActor),
            'finalized_registrations' => array_map(
                fn ($registration) => [
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'course_offering_id' => $registration->course_offering_id,
                ],
                $registrations
            ),
        ];
    }

    private function presentAdvisorListItem(StudentRegistrationRequest $request): array
    {
        $student = $request->student;
        $hours = $this->hoursFor(
            $student,
            (int) $request->academic_year_id,
            (int) $request->semester_id,
            $request
        );

        return [
            'student_registration_request_id' => $request->student_registration_request_id,
            'status' => $request->status,
            'submission_version' => (int) $request->submission_version,
            'last_submitted_at' => optional($request->last_submitted_at)?->toDateTimeString(),
            'student' => $this->compactStudent($student),
            'academic_year' => $this->compactYear($request->academicYear),
            'semester' => $this->compactSemester($request->semester),
            'hours' => $hours,
            'items_count' => $request->items->count(),
        ];
    }

    private function presentApprovedListItem(StudentRegistrationRequest $request): array
    {
        $student = $request->student;
        $hours = $this->hoursFor(
            $student,
            (int) $request->academic_year_id,
            (int) $request->semester_id,
            $request
        );

        return [
            'student_registration_request_id' => $request->student_registration_request_id,
            'status' => $request->status,
            'approved_at' => optional($request->approved_at)?->toDateTimeString(),
            'advisor' => $this->compactAdvisor($request->advisor),
            'student' => $this->compactStudent($student),
            'academic_year' => $this->compactYear($request->academicYear),
            'semester' => $this->compactSemester($request->semester),
            'hours' => $hours,
            'items' => $request->items->map(fn (StudentRegistrationRequestItem $item): array => [
                'course_offering_id' => $item->course_offering_id,
                'course_code' => $item->courseOffering?->course?->course_code,
                'course_name' => $item->courseOffering?->course?->course_name,
                'credit_hours' => (int) ($item->courseOffering?->course?->credit_hours ?? 0),
                'student_course_registration_id' => $item->student_course_registration_id,
            ])->values()->all(),
        ];
    }

    private function presentHistory(StudentRegistrationRequest $request, bool $includeActor): array
    {
        return $request->events
            ->sortBy('student_registration_request_event_id')
            ->map(function (StudentRegistrationRequestEvent $event) use ($includeActor): array {
                $payload = [
                    'event_type' => $event->event_type,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'submission_version' => $event->submission_version,
                    'notes' => $event->notes,
                    'created_at' => optional($event->created_at)?->toDateTimeString(),
                ];
                if ($includeActor) {
                    $payload['actor_user_id'] = $event->actor_user_id;
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    private function compactStudent(?Student $student): ?array
    {
        if ($student === null) {
            return null;
        }

        return [
            'student_id' => $student->student_id,
            'student_number' => $student->student_number,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'program' => $student->academicProgram === null ? null : [
                'academic_program_id' => $student->academicProgram->academic_program_id,
                'program_code' => $student->academicProgram->program_code,
                'program_name' => $student->academicProgram->program_name,
            ],
            'academic_level' => $student->currentAcademicLevel === null ? null : [
                'academic_level_id' => $student->currentAcademicLevel->academic_level_id,
                'level_name' => $student->currentAcademicLevel->level_name,
            ],
        ];
    }

    private function compactYear($year): ?array
    {
        if ($year === null) {
            return null;
        }

        return [
            'academic_year_id' => $year->academic_year_id,
            'year_name' => $year->year_name,
        ];
    }

    private function compactSemester($semester): ?array
    {
        if ($semester === null) {
            return null;
        }

        return [
            'semester_id' => $semester->semester_id,
            'semester_name' => $semester->semester_name,
            'semester_code' => $semester->semester_code,
        ];
    }

    private function compactAdvisor(?User $advisor): ?array
    {
        if ($advisor === null) {
            return null;
        }

        $employee = $advisor->employee;
        $name = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));
        if ($name === '') {
            $name = (string) $advisor->username;
        }

        return [
            'user_id' => $advisor->user_id,
            'full_name' => $name,
        ];
    }
}
