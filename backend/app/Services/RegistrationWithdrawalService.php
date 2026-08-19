<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationWithdrawalEvent;
use App\Models\StudentRegistrationWithdrawalRequest;
use App\Models\User;
use App\Support\RegistrationLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RegistrationWithdrawalService
{
    public function __construct(
        private RegistrationService $registration,
        private DataScopeService $dataScopes,
    ) {
    }

    public function studentIndex(Student $student): array
    {
        $this->assertSchemaReady();

        $requests = StudentRegistrationWithdrawalRequest::query()
            ->where('student_id', $student->student_id)
            ->with($this->displayRelations())
            ->orderByDesc('student_registration_withdrawal_request_id')
            ->get();

        return [
            'withdrawals' => $requests->map(fn (StudentRegistrationWithdrawalRequest $request) => $this->present($request))->values()->all(),
        ];
    }

    public function submit(Student $student, User $user, StudentCourseRegistration $registration, ?string $reason): array
    {
        $this->assertSchemaReady();
        $trimmed = $this->requireRequestReason($reason);

        return DB::transaction(function () use ($student, $user, $registration, $trimmed): array {
            [$lockedStudent, $offering, $lockedRegistration, $current] = $this->lockStudentOfferingRegistrationThenCurrent(
                (int) $student->student_id,
                (int) $registration->student_course_registration_id
            );

            $this->assertOwned($lockedStudent, $lockedRegistration);
            $this->assertRegisteredForWithdrawal($lockedRegistration);
            $this->assertOfferingClosedForWithdrawal($offering);
            $this->assertGradesAllowWithdrawal($offering);

            if ($current !== null) {
                throw RegistrationException::withdrawalAlreadyCurrent();
            }

            $now = now();
            $request = StudentRegistrationWithdrawalRequest::query()->create([
                'student_course_registration_id' => $lockedRegistration->student_course_registration_id,
                'student_id' => $lockedStudent->student_id,
                'status' => RegistrationLifecycle::STATUS_SUBMITTED,
                'submission_version' => 1,
                'current_slot' => RegistrationLifecycle::CURRENT_SLOT,
                'request_reason' => $trimmed,
                'requested_by_user_id' => $user->user_id,
                'submitted_at' => $now,
            ]);

            $this->writeEvent(
                $request,
                RegistrationLifecycle::EVENT_SUBMITTED,
                $user,
                null,
                RegistrationLifecycle::STATUS_SUBMITTED,
                $trimmed
            );

            return $this->present($this->fresh($request));
        });
    }

    public function resubmit(Student $student, User $user, StudentRegistrationWithdrawalRequest $request, ?string $reason): array
    {
        $this->assertSchemaReady();
        $trimmed = $this->requireRequestReason($reason);

        return DB::transaction(function () use ($student, $user, $request, $trimmed): array {
            [$lockedStudent, $offering, $lockedRegistration, $locked] = $this->lockGraphByRequestId(
                (int) $request->student_registration_withdrawal_request_id
            );

            $this->assertOwned($lockedStudent, $lockedRegistration);
            if ((int) $locked->student_id !== (int) $student->student_id) {
                throw RegistrationException::notOwned();
            }
            if (! $locked->isCurrent() || $locked->status === RegistrationLifecycle::STATUS_SUPERSEDED) {
                throw RegistrationException::withdrawalStale();
            }
            if ($locked->isApproved() || $locked->isMaterialized()) {
                throw RegistrationException::withdrawalAlreadyMaterialized();
            }
            if (! $locked->isReturned()) {
                throw RegistrationException::withdrawalAlreadyCurrent();
            }

            $this->assertRegisteredForWithdrawal($lockedRegistration);
            $this->assertOfferingClosedForWithdrawal($offering);
            $this->assertGradesAllowWithdrawal($offering);

            $from = $locked->status;
            $now = now();
            $locked->update([
                'status' => RegistrationLifecycle::STATUS_SUBMITTED,
                'submission_version' => (int) $locked->submission_version + 1,
                'request_reason' => $trimmed,
                'requested_by_user_id' => $user->user_id,
                'submitted_at' => $now,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_notes' => null,
            ]);

            $this->writeEvent(
                $locked,
                RegistrationLifecycle::EVENT_RESUBMITTED,
                $user,
                $from,
                RegistrationLifecycle::STATUS_SUBMITTED,
                $trimmed
            );

            return $this->present($this->fresh($locked));
        });
    }

    public function advisorIndex(User $user, ?string $status = null): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();

        $query = $this->scopedRequestsQuery($user)
            ->with($this->displayRelations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('student_registration_withdrawal_request_id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return [
            'withdrawals' => $query->get()
                ->map(fn (StudentRegistrationWithdrawalRequest $request) => $this->present($request))
                ->values()
                ->all(),
        ];
    }

    public function advisorShow(User $user, StudentRegistrationWithdrawalRequest $request): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();
        $this->assertCanAccessRequest($user, $request);

        return $this->present($this->fresh($request));
    }

    public function returnForModification(User $user, StudentRegistrationWithdrawalRequest $request, string $notes): array
    {
        $this->assertCanReview($user);
        $this->assertSchemaReady();
        $this->assertCanAccessRequest($user, $request);
        $trimmed = $this->requireReturnReason($notes);

        return $this->finishDecision($this->decide(
            $user,
            $request,
            RegistrationLifecycle::STATUS_RETURNED,
            $trimmed
        ));
    }

    public function approve(User $user, StudentRegistrationWithdrawalRequest $request): array
    {
        $this->assertCanReview($user);
        $this->assertSchemaReady();
        $this->assertCanAccessRequest($user, $request);

        return $this->finishDecision($this->decide(
            $user,
            $request,
            RegistrationLifecycle::STATUS_APPROVED,
            null
        ));
    }

    /**
     * @return array{request: array, outcome: ?string}
     */
    private function decide(
        User $user,
        StudentRegistrationWithdrawalRequest $request,
        string $decision,
        ?string $reason
    ): array {
        return DB::transaction(function () use ($user, $request, $decision, $reason): array {
            [$student, $offering, $registration, $locked] = $this->lockGraphByRequestId(
                (int) $request->student_registration_withdrawal_request_id
            );

            $this->assertCanAccessRequest($user, $locked);

            if ($locked->isApproved() || $locked->isMaterialized()) {
                return $this->decisionConflict($locked, RegistrationException::WITHDRAWAL_ALREADY_MATERIALIZED);
            }

            if (! $locked->isCurrent() || $locked->status === RegistrationLifecycle::STATUS_SUPERSEDED) {
                return $this->decisionConflict($locked, RegistrationException::WITHDRAWAL_STALE);
            }

            if (! $locked->isSubmitted()) {
                throw RegistrationException::withdrawalAlreadyCurrent();
            }

            $stale = $this->staleReason($student, $registration, $locked);
            if ($stale !== null) {
                $this->supersedeUnlocked($user, $locked, $stale);

                return $this->decisionConflict($locked, RegistrationException::WITHDRAWAL_STALE);
            }

            if ($offering->status !== 'closed') {
                return $this->decisionConflict($locked, RegistrationException::WITHDRAWAL_REQUIRES_CLOSED_OFFERING);
            }

            $this->assertGradesAllowWithdrawal($offering);

            if ($decision === RegistrationLifecycle::STATUS_RETURNED) {
                $from = $locked->status;
                $now = now();
                $locked->update([
                    'status' => RegistrationLifecycle::STATUS_RETURNED,
                    'reviewed_by_user_id' => $user->user_id,
                    'reviewed_at' => $now,
                    'review_notes' => $reason,
                ]);
                $this->writeEvent(
                    $locked,
                    RegistrationLifecycle::EVENT_RETURNED,
                    $user,
                    $from,
                    RegistrationLifecycle::STATUS_RETURNED,
                    $reason
                );

                return $this->decisionOk($locked);
            }

            $from = $locked->status;
            $now = now();
            $this->registration->transitionRegisteredToWithdrawn($registration, $offering);
            $locked->update([
                'status' => RegistrationLifecycle::STATUS_APPROVED,
                'current_slot' => null,
                'reviewed_by_user_id' => $user->user_id,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'materialized_at' => $now,
            ]);
            $this->writeEvent(
                $locked,
                RegistrationLifecycle::EVENT_APPROVED,
                $user,
                $from,
                RegistrationLifecycle::STATUS_APPROVED,
                null
            );
            $this->writeEvent(
                $locked,
                RegistrationLifecycle::EVENT_MATERIALIZED,
                $user,
                RegistrationLifecycle::STATUS_APPROVED,
                RegistrationLifecycle::STATUS_APPROVED,
                null
            );

            return $this->decisionOk($locked);
        });
    }

    /**
     * HTTP conflicts for stale withdrawal must be raised AFTER the supersede
     * transaction commits. Throwing inside DB::transaction() would roll the
     * persisted stale/superseded state back.
     *
     * @param  array{request: array, outcome: ?string}  $result
     */
    private function finishDecision(array $result): array
    {
        return match ($result['outcome'] ?? null) {
            RegistrationException::WITHDRAWAL_STALE => throw RegistrationException::withdrawalStale(),
            RegistrationException::WITHDRAWAL_REQUIRES_CLOSED_OFFERING => throw RegistrationException::withdrawalRequiresClosedOffering(),
            RegistrationException::WITHDRAWAL_ALREADY_MATERIALIZED => throw RegistrationException::withdrawalAlreadyMaterialized(),
            default => $result['request'],
        };
    }

    /**
     * @return array{request: array, outcome: null}
     */
    private function decisionOk(StudentRegistrationWithdrawalRequest $request): array
    {
        return [
            'request' => $this->present($this->fresh($request)),
            'outcome' => null,
        ];
    }

    /**
     * @return array{request: array, outcome: string}
     */
    private function decisionConflict(StudentRegistrationWithdrawalRequest $request, string $outcome): array
    {
        return [
            'request' => $this->present($this->fresh($request)),
            'outcome' => $outcome,
        ];
    }

    private function supersedeUnlocked(User $user, StudentRegistrationWithdrawalRequest $request, string $notes): void
    {
        $from = $request->status;
        $now = now();
        $request->update([
            'status' => RegistrationLifecycle::STATUS_SUPERSEDED,
            'current_slot' => null,
            'superseded_at' => $now,
        ]);
        $this->writeEvent(
            $request,
            RegistrationLifecycle::EVENT_STALE,
            $user,
            $from,
            RegistrationLifecycle::STATUS_SUPERSEDED,
            $notes
        );
    }

    private function staleReason(
        Student $student,
        StudentCourseRegistration $registration,
        StudentRegistrationWithdrawalRequest $request
    ): ?string {
        if ((int) $registration->student_id !== (int) $student->student_id
            || (int) $request->student_id !== (int) $student->student_id
            || (int) $request->student_course_registration_id !== (int) $registration->student_course_registration_id) {
            return 'target_changed';
        }

        $status = $registration->registrationStatus?->status_code;
        if ($status === StudentCourseRegistration::DROPPED_STATUS) {
            return 'registration_dropped';
        }
        if ($status === StudentCourseRegistration::WITHDRAWN_STATUS) {
            return 'registration_withdrawn';
        }
        if ($status !== StudentCourseRegistration::CURRENT_STATUS) {
            return 'registration_not_current';
        }

        return null;
    }

    /**
     * @return array{0: Student, 1: CourseOffering, 2: StudentCourseRegistration, 3: StudentRegistrationWithdrawalRequest}
     */
    private function lockGraphByRequestId(int $requestId): array
    {
        $preview = StudentRegistrationWithdrawalRequest::query()->findOrFail($requestId);
        $registrationPreview = StudentCourseRegistration::query()->findOrFail($preview->student_course_registration_id);

        $student = $this->registration->lockStudent((int) $preview->student_id);
        $offering = $this->registration->lockOffering((int) $registrationPreview->course_offering_id);
        $registration = $this->registration->lockRegistration((int) $registrationPreview->student_course_registration_id);
        $request = StudentRegistrationWithdrawalRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->firstOrFail();

        return [$student, $offering, $registration, $request];
    }

    /**
     * @return array{0: Student, 1: CourseOffering, 2: StudentCourseRegistration, 3: ?StudentRegistrationWithdrawalRequest}
     */
    private function lockStudentOfferingRegistrationThenCurrent(int $studentId, int $registrationId): array
    {
        $preview = StudentCourseRegistration::query()->findOrFail($registrationId);
        $student = $this->registration->lockStudent($studentId);
        $offering = $this->registration->lockOffering((int) $preview->course_offering_id);
        $registration = $this->registration->lockRegistration($registrationId);
        $current = StudentRegistrationWithdrawalRequest::query()
            ->where('student_course_registration_id', $registration->student_course_registration_id)
            ->where('current_slot', RegistrationLifecycle::CURRENT_SLOT)
            ->lockForUpdate()
            ->first();

        return [$student, $offering, $registration, $current];
    }

    private function assertOwned(Student $student, StudentCourseRegistration $registration): void
    {
        if ((int) $registration->student_id !== (int) $student->student_id) {
            throw RegistrationException::notOwned();
        }
    }

    private function assertRegisteredForWithdrawal(StudentCourseRegistration $registration): void
    {
        $registration->loadMissing('registrationStatus');
        $status = $registration->registrationStatus?->status_code;
        if ($status === StudentCourseRegistration::WITHDRAWN_STATUS) {
            throw RegistrationException::withdrawalNotRequired();
        }
        if ($status !== StudentCourseRegistration::CURRENT_STATUS) {
            throw RegistrationException::notCurrent();
        }
    }

    private function assertOfferingClosedForWithdrawal(CourseOffering $offering): void
    {
        if ($offering->status !== 'closed') {
            throw RegistrationException::withdrawalRequiresClosedOffering();
        }
    }

    private function assertGradesAllowWithdrawal(CourseOffering $offering): void
    {
        $approval = GradeApproval::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->orderByDesc('grade_approval_id')
            ->first();
        $approval?->load('approvalStatus');

        if ($approval !== null && ! $approval->allowsGradeEditing()) {
            throw RegistrationException::gradesLocked();
        }
    }

    private function requireRequestReason(?string $reason): string
    {
        $trimmed = trim((string) $reason);
        if ($trimmed === '' || mb_strlen($trimmed) < RegistrationLifecycle::REASON_MIN) {
            throw RegistrationException::withdrawalReasonRequired();
        }
        if (mb_strlen($trimmed) > RegistrationLifecycle::REASON_MAX) {
            throw RegistrationException::withdrawalReasonRequired();
        }

        return $trimmed;
    }

    private function requireReturnReason(string $notes): string
    {
        $trimmed = trim($notes);
        if (mb_strlen($trimmed) < RegistrationLifecycle::RETURN_NOTES_MIN) {
            throw RegistrationException::withdrawalReturnReasonRequired();
        }
        if (mb_strlen($trimmed) > RegistrationLifecycle::REASON_MAX) {
            throw RegistrationException::withdrawalReturnReasonRequired();
        }

        return $trimmed;
    }

    private function writeEvent(
        StudentRegistrationWithdrawalRequest $request,
        string $type,
        ?User $actor,
        ?string $from,
        ?string $to,
        ?string $notes
    ): void {
        StudentRegistrationWithdrawalEvent::query()->create([
            'student_registration_withdrawal_request_id' => $request->student_registration_withdrawal_request_id,
            'event_type' => $type,
            'actor_user_id' => $actor?->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'submission_version' => $request->submission_version,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function fresh(StudentRegistrationWithdrawalRequest $request): StudentRegistrationWithdrawalRequest
    {
        return StudentRegistrationWithdrawalRequest::query()
            ->with($this->displayRelations())
            ->findOrFail($request->student_registration_withdrawal_request_id);
    }

    /**
     * @return list<string>
     */
    private function displayRelations(): array
    {
        return [
            'student',
            'requestedBy.employee',
            'reviewer.employee',
            'registration.registrationStatus',
            'registration.courseOffering.course',
            'registration.courseOffering.academicYear',
            'registration.courseOffering.semester',
            'events.actor',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StudentRegistrationWithdrawalRequest $request): array
    {
        $registration = $request->registration;
        $offering = $registration?->courseOffering;
        $course = $offering?->course;

        return [
            'student_registration_withdrawal_request_id' => $request->student_registration_withdrawal_request_id,
            'student_course_registration_id' => $request->student_course_registration_id,
            'student_id' => $request->student_id,
            'status' => $request->status,
            'submission_version' => $request->submission_version,
            'current_slot' => $request->current_slot,
            'request_reason' => $request->request_reason,
            'submitted_at' => $request->submitted_at,
            'reviewed_at' => $request->reviewed_at,
            'review_notes' => $request->review_notes,
            'approved_at' => $request->approved_at,
            'materialized_at' => $request->materialized_at,
            'superseded_at' => $request->superseded_at,
            'student' => $request->student === null ? null : [
                'student_id' => $request->student->student_id,
                'student_number' => $request->student->student_number,
                'first_name' => $request->student->first_name,
                'last_name' => $request->student->last_name,
            ],
            'registration' => $registration === null ? null : [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'registration_status' => $registration->registrationStatus?->status_code,
                'course_offering' => $offering === null ? null : [
                    'course_offering_id' => $offering->course_offering_id,
                    'status' => $offering->status,
                    'course' => $course === null ? null : [
                        'course_id' => $course->course_id,
                        'course_code' => $course->course_code,
                        'course_name' => $course->course_name,
                    ],
                    'academic_year' => $offering->academicYear === null ? null : [
                        'academic_year_id' => $offering->academicYear->academic_year_id,
                        'year_name' => $offering->academicYear->year_name,
                    ],
                    'semester' => $offering->semester === null ? null : [
                        'semester_id' => $offering->semester->semester_id,
                        'semester_name' => $offering->semester->semester_name,
                    ],
                ],
            ],
            'requested_by' => $this->safeUser($request->requestedBy),
            'reviewer' => $this->safeUser($request->reviewer),
            'events' => $request->relationLoaded('events')
                ? $request->events
                    ->sortBy('student_registration_withdrawal_event_id')
                    ->values()
                    ->map(fn (StudentRegistrationWithdrawalEvent $event) => [
                        'event_type' => $event->event_type,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'submission_version' => $event->submission_version,
                        'notes' => $event->notes,
                        'created_at' => $event->created_at,
                        'actor' => $this->safeUser($event->actor),
                    ])
                    ->all()
                : [],
        ];
    }

    /**
     * @return array{user_id: int, username: ?string}|null
     */
    private function safeUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
        ];
    }

    private function scopedRequestsQuery(User $user): Builder
    {
        return StudentRegistrationWithdrawalRequest::query()
            ->whereHas(
                'student',
                fn (Builder $student) => $this->dataScopes->scopeStaffStudents($student, $user)
            );
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(RegistrationLifecycle::PERMISSION_VIEW)
            && ! $user->hasPermission(RegistrationLifecycle::PERMISSION_REVIEW)) {
            throw new AccessDeniedHttpException('You do not have permission to view registration withdrawals.');
        }
    }

    /**
     * Actual academic_advisor role plus assigned role_permissions.
     * Super Admin virtual grants from User::hasPermission() must not
     * impersonate academic review authority.
     */
    private function assertCanReview(User $user): void
    {
        if (! $user->isAcademicAdvisor() || ! $this->holdsAssignedPermission($user, RegistrationLifecycle::PERMISSION_REVIEW)) {
            throw RegistrationException::withdrawalReviewForbidden();
        }
    }

    private function holdsAssignedPermission(User $user, string $permission): bool
    {
        return $user->effectivePermissions()->contains($permission);
    }

    private function assertCanAccessRequest(User $user, StudentRegistrationWithdrawalRequest $request): void
    {
        $request->loadMissing('student');
        if ($request->student === null || ! $this->dataScopes->canStaffAccessStudent($user, $request->student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this withdrawal request.');
        }
    }

    private function assertSchemaReady(): void
    {
        if (! RegistrationLifecycle::schemaReady()) {
            throw RegistrationException::withdrawalNotRequired();
        }
    }
}
