<?php

namespace Tests\Feature;

use App\Exceptions\RegistrationException;
use App\Exceptions\RegistrationRequestException;
use App\Http\Controllers\Api\StudentSelfRegistrationController;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\AcademicTermResolver;
use App\Services\CourseOfferingScheduleService;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\RegistrationModificationService;
use App\Services\RegistrationRequestService;
use App\Services\RegistrationService;
use App\Services\TeachingAssignmentService;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use App\Support\RegistrationProjectionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SemesterRegistrationModificationsPhase5BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_approved_initial_request_blocks_direct_self_drop_without_phase5_schema(): void
    {
        $this->approvedInitialRequest();

        $this->expectRegistrationCode(
            fn () => $this->registrationService()->selfDrop($this->student(), $this->registration()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
        );

        self::assertSame('registered', $this->currentStatus());
        self::assertFalse(Schema::hasTable('student_registration_modification_requests'));
    }

    public function test_student_http_drop_endpoint_preserves_a_workflow_managed_registration(): void
    {
        $this->approvedInitialRequest();
        $request = Request::create('/api/v1/student/registration/10/drop', 'POST');
        $user = new User(['user_id' => 7, 'student_id' => 1]);
        $user->setAttribute('user_id', 7);
        $user->setAttribute('student_id', 1);
        $request->setUserResolver(fn (): User => $user);
        $controller = new StudentSelfRegistrationController(
            $this->createMock(RegistrationRequestService::class),
            $this->createMock(RegistrationModificationService::class),
        );

        $this->expectRegistrationCode(
            fn () => $controller->drop($request, $this->registration(), $this->registrationService()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
            409,
        );

        self::assertSame('registered', $this->currentStatus());
    }

    public function test_term_governance_blocks_a_registration_not_linked_to_an_original_request_item(): void
    {
        $this->approvedInitialRequest(withItemLink: false);

        $this->expectRegistrationCode(
            fn () => $this->registrationService()->selfDrop($this->student(), $this->registration()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
        );

        self::assertSame('registered', $this->currentStatus());
    }

    public function test_legacy_registration_without_approved_request_keeps_existing_self_drop_behavior(): void
    {
        $updated = $this->registrationService()->selfDrop($this->student(), $this->registration());

        self::assertSame('dropped', $updated->registrationStatus?->status_code);
        self::assertSame('dropped', $this->currentStatus());
    }

    public function test_trusted_low_level_drop_transition_remains_available_for_atomic_phase5_approval(): void
    {
        DB::transaction(function (): void {
            $registration = StudentCourseRegistration::query()->whereKey(10)->lockForUpdate()->firstOrFail();
            $registration->load('registrationStatus');
            $offering = $registration->courseOffering()->lockForUpdate()->firstOrFail();

            $this->registrationService()->transitionRegisteredToDropped($registration, $offering);
        });

        self::assertSame('dropped', $this->currentStatus());
    }

    public function test_real_delta_removal_stays_provisional_until_atomic_advisor_approval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T00:00:00Z'));
        $this->phase5Schema();
        $this->approvedInitialRequest();
        $phase = (object) ['value' => CourseRegistrationPhase::STUDENT_OPEN];
        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('lockStudent')->andReturnUsing(fn (): Student => $this->student());
        $registration->shouldReceive('assertCourseRegistrationStudentWindowOpen')->zeroOrMoreTimes();
        $registration->shouldReceive('courseRegistrationDeadlines')->zeroOrMoreTimes()
            ->andReturnUsing(fn (): CourseRegistrationDeadlineResult => $this->deadline($phase->value));
        $registration->shouldReceive('hoursSnapshot')->zeroOrMoreTimes()
            ->andReturnUsing(function (Student $student, int $yearId, int $semesterId, ?RegistrationProjectionContext $projection = null): array {
                $removed = $projection?->excludedRegistrationIds() ?? [];

                return [
                    'registered_hours' => in_array(10, $removed, true) ? 0 : 3,
                    'max_allowed_hours' => 18,
                    'official_cgpa' => 2.5,
                ];
            });
        $registration->shouldReceive('getSelfRegistrationOfferings')->zeroOrMoreTimes()->andReturn(collect());
        $registration->shouldReceive('evaluateRegistrationCandidatesForProjection')->zeroOrMoreTimes()->andReturn([]);
        $registration->shouldReceive('transitionRegisteredToDropped')->once()
            ->andReturnUsing(function (StudentCourseRegistration $current): void {
                DB::table('student_course_registrations')->where('student_course_registration_id', $current->getKey())
                    ->update(['registration_status_id' => 2]);
            });

        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('validateProjectedCandidates')->zeroOrMoreTimes()->andReturn([]);
        $schedules = Mockery::mock(CourseOfferingScheduleService::class);
        $schedules->shouldReceive('registrationEvaluations')->zeroOrMoreTimes()
            ->andReturnUsing(function (Student $student, $targets): array {
                return $targets->mapWithKeys(fn ($offering): array => [(int) $offering->getKey() => [
                    'reason' => null,
                    'schedule' => ['schema_ready' => true, 'components_defined' => true, 'complete' => true, 'slots' => []],
                    'conflicts' => [],
                ]])->all();
            });
        $terms = Mockery::mock(AcademicTermResolver::class);
        $terms->shouldReceive('uniqueCurrentAcademicYearId')->andReturn(1);
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canStaffAccessStudent')->zeroOrMoreTimes()->andReturnTrue();
        $service = new RegistrationModificationService($registration, $requirements, $schedules, $terms, $scope);
        $studentActor = User::query()->findOrFail(7);

        $draft = $service->createDraft($this->student(), $studentActor, 1);
        $item = \App\Models\StudentRegistrationModificationItem::query()->findOrFail(
            $draft['items'][0]['student_registration_modification_item_id'],
        );
        $service->toggleBaselineItem($this->student(), $studentActor, $item, 'remove');
        $submitted = $service->submit($this->student(), $studentActor, 1);

        self::assertSame('submitted', $submitted['status']);
        self::assertSame('registered', $this->currentStatus());
        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 20)->value('status'));
        self::assertSame(10, (int) DB::table('student_registration_request_items')->where('student_registration_request_item_id', 30)->value('student_course_registration_id'));

        $phase->value = CourseRegistrationPhase::ADVISOR_REVIEW;
        $advisor = Mockery::mock(User::class)->makePartial();
        $advisor->setRawAttributes(['user_id' => 8, 'username' => 'advisor']);
        $advisor->exists = true;
        $advisor->shouldReceive('hasPermission')->with('registration_requests.review')->andReturnTrue();
        $approved = $service->approve(
            $advisor,
            \App\Models\StudentRegistrationModificationRequest::query()->findOrFail($draft['student_registration_modification_request_id']),
        );

        self::assertSame('approved', $approved['status']);
        self::assertNull($approved['current_slot']);
        self::assertNotNull($approved['materialized_at']);
        self::assertSame('dropped', $this->currentStatus());
        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 20)->value('status'));
        self::assertSame(10, (int) DB::table('student_registration_request_items')->where('student_registration_request_item_id', 30)->value('student_course_registration_id'));
    }

    public function test_real_workflow_snapshots_exact_baseline_without_mutating_approved_initial_request(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->registerCurrent(11, 2);
        $this->addOffering(3, 3, 'C103', '12:00', '13:00');
        $this->approvedInitialForCurrent();
        $initialBefore = DB::table('student_registration_requests')->where('student_registration_request_id', 20)->first();
        $itemsBefore = DB::table('student_registration_request_items')->orderBy('student_registration_request_item_id')->get()->toArray();

        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);

        self::assertSame([10, 11], collect($draft['items'])->pluck('source_student_course_registration_id')->sort()->values()->all());
        self::assertSame(['keep', 'keep'], collect($draft['items'])->pluck('operation')->all());
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(3));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;
        $harness['service']->approve($this->advisorActor(), $this->modification($draft));
        self::assertEquals($initialBefore, DB::table('student_registration_requests')->where('student_registration_request_id', 20)->first());
        self::assertEquals($itemsBefore, DB::table('student_registration_request_items')->orderBy('student_registration_request_item_id')->get()->toArray());
    }

    public function test_real_add_only_approval_materializes_and_links_the_official_registration(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $approved = $harness['service']->approve($this->advisorActor(), $this->modification($draft));
        $materializedId = DB::table('student_registration_modification_items')->where('course_offering_id', 2)->value('materialized_student_course_registration_id');

        self::assertSame('approved', $approved['status']);
        self::assertNotNull($materializedId);
        self::assertSame('registered', $this->statusForOffering(2));
        self::assertSame((int) $materializedId, (int) DB::table('student_course_registrations')->where('course_offering_id', 2)->value('student_course_registration_id'));
    }

    public function test_real_eighteen_hour_replace_succeeds_and_approved_presentation_uses_immutable_snapshot(): void
    {
        $harness = $this->realWorkflow(['cgpa' => 2.5]);
        foreach (range(2, 6) as $id) {
            $this->addOffering($id, $id, 'C10'.$id, sprintf('%02d:00', 7 + $id), sprintf('%02d:00', 8 + $id));
            $this->registerCurrent(9 + $id, $id);
        }
        $this->addOffering(7, 7, 'C107', '16:00', '17:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->markRemove($harness['service'], $draft, 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(7));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $approved = $harness['service']->approve($this->advisorActor(), $this->modification($draft));
        $shown = $harness['service']->advisorShow($this->advisorActor(), $this->modification($draft));

        self::assertSame(18, $approved['hours']['registered_hours_before']);
        self::assertSame(3, $approved['hours']['removed_hours']);
        self::assertSame(3, $approved['hours']['added_hours']);
        self::assertSame(18, $approved['hours']['projected_hours']);
        self::assertSame(18, $shown['hours']['registered_hours_before']);
        self::assertSame(3, $shown['hours']['removed_hours']);
        self::assertSame(3, $shown['hours']['added_hours']);
        self::assertSame(18, $shown['hours']['projected_hours']);
        self::assertNotSame(21, $shown['hours']['projected_hours']);
    }

    public function test_real_same_add_without_removal_exceeds_canonical_eighteen_hour_limit(): void
    {
        $harness = $this->realWorkflow(['cgpa' => 2.5]);
        foreach (range(2, 6) as $id) {
            $this->addOffering($id, $id, 'C10'.$id, sprintf('%02d:00', 7 + $id), sprintf('%02d:00', 8 + $id));
            $this->registerCurrent(9 + $id, $id);
        }
        $this->addOffering(7, 7, 'C107', '16:00', '17:00');
        $this->approvedInitialForCurrent();
        $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(7));

        $this->expectRequestCode(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );
    }

    public function test_real_below_twelve_hour_projection_warns_without_blocking_submission(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));

        $submitted = $harness['service']->submit($this->student(), $this->studentActor(), 1);

        self::assertSame('submitted', $submitted['status']);
        self::assertSame(6, $submitted['hours']['projected_hours']);
        self::assertTrue($submitted['hours']['below_recommended_minimum']);
    }

    public function test_real_timetable_conflict_against_keep_blocks_but_removing_that_peer_allows_replacement(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '08:30', '09:30');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));

        $this->expectRequestCode(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );

        $this->markRemove($harness['service'], $draft, 1);
        $submitted = $harness['service']->submit($this->student(), $this->studentActor(), 1);
        self::assertSame('submitted', $submitted['status']);
        self::assertSame([], $submitted['failures']);
    }

    public function test_real_add_against_add_timetable_conflict_blocks_submission(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->addOffering(3, 3, 'C103', '10:30', '11:30');
        $this->approvedInitialForCurrent();
        $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(3));

        $this->expectRequestCode(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );
    }

    public function test_real_missing_add_timetable_blocks_submission(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', null, null);
        $this->approvedInitialForCurrent();
        $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));

        $this->expectRequestCode(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );
    }

    public function test_real_passed_course_is_rejected_again_at_submit_with_stable_reason(): void
    {
        $harness = $this->realWorkflow(['passed' => [2]]);
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->expectRequestCode(
            fn () => $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2)),
            RegistrationException::COURSE_ALREADY_PASSED,
        );
        $this->insertAddItem($draft, 2);

        $exception = $this->captureRequestException(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );
        self::assertSame(RegistrationException::COURSE_ALREADY_PASSED, $exception->itemFailures[0]['reason'] ?? null);
    }

    public function test_real_missing_prerequisite_is_rejected_again_at_submit_with_structured_course_data(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'PRE100', '10:00', '11:00');
        $this->addOffering(3, 3, 'ADV200', '12:00', '13:00');
        DB::table('course_prerequisites')->insert(['course_id' => 3, 'prerequisite_course_id' => 2]);
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->expectRequestCode(
            fn () => $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(3)),
            'missing_prerequisites',
        );
        $this->insertAddItem($draft, 3);

        $exception = $this->captureRequestException(
            fn () => $harness['service']->submit($this->student(), $this->studentActor(), 1),
            'registration_modification_invalid',
        );
        $failure = collect($exception->itemFailures)->firstWhere('reason', 'missing_prerequisites');
        self::assertSame(2, (int) ($failure['missing_prerequisites'][0]['course_id'] ?? 0));
        self::assertSame('PRE100', $failure['missing_prerequisites'][0]['course_code'] ?? null);
    }

    public function test_real_failed_history_does_not_count_as_passed_and_satisfied_prerequisite_allows_submit(): void
    {
        $harness = $this->realWorkflow(['passed' => [2]]);
        $this->addOffering(2, 2, 'PRE100', '10:00', '11:00');
        $this->addOffering(3, 3, 'ADV200', '12:00', '13:00');
        $this->addOffering(4, 4, 'FAILED300', '14:00', '15:00');
        DB::table('course_prerequisites')->insert(['course_id' => 3, 'prerequisite_course_id' => 2]);
        $this->approvedInitialForCurrent();
        $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(3));
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(4));

        $submitted = $harness['service']->submit($this->student(), $this->studentActor(), 1);
        self::assertSame('submitted', $submitted['status']);
        self::assertSame([], $submitted['failures']);
    }

    public function test_real_stale_cgpa_before_approval_can_invalidate_twenty_one_hour_projection(): void
    {
        $harness = $this->realWorkflow(['cgpa' => 3.2]);
        foreach (range(2, 6) as $id) {
            $this->addOffering($id, $id, 'C10'.$id, sprintf('%02d:00', 7 + $id), sprintf('%02d:00', 8 + $id));
            $this->registerCurrent(9 + $id, $id);
        }
        $this->addOffering(7, 7, 'C107', '16:00', '17:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(7));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->cgpa = 2.9;
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $this->expectRequestCode(
            fn () => $harness['service']->approve($this->advisorActor(), $this->modification($draft)),
            'registration_modification_approval_failed',
        );
        self::assertSame('submitted', DB::table('student_registration_modification_requests')->value('status'));
    }

    public function test_real_credit_capacity_released_by_remove_can_be_consumed_by_add(): void
    {
        $harness = $this->realWorkflow(['cgpa' => 2.5]);
        foreach (range(2, 6) as $id) {
            $this->addOffering($id, $id, 'C10'.$id, sprintf('%02d:00', 7 + $id), sprintf('%02d:00', 8 + $id));
            $this->registerCurrent(9 + $id, $id);
        }
        $this->addOffering(7, 7, 'ELECTIVE', '16:00', '17:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->markRemove($harness['service'], $draft, 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(7));
        $submitted = $harness['service']->submit($this->student(), $this->studentActor(), 1);

        self::assertSame(18, $submitted['hours']['projected_hours']);
        self::assertSame([], $submitted['failures']);
    }

    public function test_real_closed_remove_source_requires_withdrawal(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->markRemove($harness['service'], $draft, 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'closed']);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $this->expectRequestCode(
            fn () => $harness['service']->approve($this->advisorActor(), $this->modification($draft)),
            'registration_modification_source_requires_withdrawal',
        );
        self::assertSame('registered', $this->statusForOffering(1));
    }

    public function test_real_unresolved_withdrawal_request_blocks_approval(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->markRemove($harness['service'], $draft, 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        DB::table('student_registration_withdrawal_requests')->insert([
            'student_registration_withdrawal_request_id' => 1,
            'student_course_registration_id' => 10,
            'current_slot' => 1,
        ]);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $this->expectRequestCode(
            fn () => $harness['service']->approve($this->advisorActor(), $this->modification($draft)),
            'registration_modification_withdrawal_conflict',
        );
        self::assertSame('registered', $this->statusForOffering(1));
    }

    public function test_real_baseline_drift_persists_superseded_state_and_event_before_stale_conflict(): void
    {
        $harness = $this->realWorkflow();
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->registerCurrent(11, 2);

        $this->expectRequestCode(
            fn () => $harness['service']->updateNotes($this->student(), $this->studentActor(), 'changed', 1),
            'registration_modification_stale',
        );

        self::assertSame('superseded', DB::table('student_registration_modification_requests')->where('student_registration_modification_request_id', $draft['student_registration_modification_request_id'])->value('status'));
        self::assertSame('superseded_baseline_changed', DB::table('student_registration_modification_events')->orderByDesc('student_registration_modification_event_id')->value('event_type'));
    }

    public function test_real_returned_before_student_deadline_can_edit_and_resubmit(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['service']->returnForModification($this->advisorActor(), $this->modification($draft), 'Reason long enough');

        $harness['service']->updateNotes($this->student(), $this->studentActor(), 'corrected', 1);
        $resubmitted = $harness['service']->submit($this->student(), $this->studentActor(), 1);
        self::assertSame('submitted', $resubmitted['status']);
        self::assertSame(2, $resubmitted['submission_version']);
    }

    public function test_real_returned_after_student_deadline_is_read_only(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T00:00:00Z'));
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;
        $harness['service']->returnForModification($this->advisorActor(), $this->modification($draft), 'Reason long enough');

        $this->expectRegistrationCode(
            fn () => $harness['service']->updateNotes($this->student(), $this->studentActor(), 'late', 1),
            RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
        );
    }

    public function test_real_advisor_approval_after_student_cutoff_before_advisor_cutoff_succeeds(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T00:00:00Z'));
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;

        $approved = $harness['service']->approve($this->advisorActor(), $this->modification($draft));
        self::assertSame('approved', $approved['status']);
    }

    public function test_real_request_after_advisor_deadline_expires_without_materialization(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11T00:00:00Z'));
        $harness['state']->phase = CourseRegistrationPhase::CLOSED;

        $this->expectRequestCode(
            fn () => $harness['service']->approve($this->advisorActor(), $this->modification($draft)),
            RegistrationRequestException::ADVISOR_DEADLINE_CLOSED,
        );
        self::assertSame('expired', DB::table('student_registration_modification_requests')->value('status'));
        self::assertNull(DB::table('student_course_registrations')->where('course_offering_id', 2)->value('student_course_registration_id'));
    }

    public function test_real_sequential_approved_modification_snapshots_the_new_official_set(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'C102', '10:00', '11:00');
        $this->approvedInitialForCurrent();
        $first = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(2));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;
        $harness['service']->approve($this->advisorActor(), $this->modification($first));
        $harness['state']->phase = CourseRegistrationPhase::STUDENT_OPEN;

        $second = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        self::assertSame([1, 2], collect($second['items'])->pluck('course_offering_id')->sort()->values()->all());
        self::assertSame([10, 11], collect($second['items'])->pluck('source_student_course_registration_id')->sort()->values()->all());
    }

    public function test_real_atomic_approval_rolls_back_prior_remove_and_add_when_later_add_fails(): void
    {
        $harness = $this->realWorkflow();
        $this->addOffering(2, 2, 'B', '10:00', '11:00');
        $this->addOffering(3, 3, 'C', '12:00', '13:00');
        $this->registerCurrent(11, 2);
        $this->registerCurrent(12, 3);
        $this->addOffering(4, 4, 'D', '14:00', '15:00');
        $this->addOffering(5, 5, 'E', '16:00', '17:00');
        $this->approvedInitialForCurrent();
        $draft = $harness['service']->createDraft($this->student(), $this->studentActor(), 1);
        $this->markRemove($harness['service'], $draft, 1);
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(4));
        $harness['service']->addItem($this->student(), $this->studentActor(), CourseOffering::query()->findOrFail(5));
        $harness['service']->submit($this->student(), $this->studentActor(), 1);
        $harness['state']->hardFailureOfferingId = 5;
        $harness['state']->phase = CourseRegistrationPhase::ADVISOR_REVIEW;
        $before = $this->officialSet();

        $this->expectRegistrationCode(
            fn () => $harness['service']->approve($this->advisorActor(), $this->modification($draft)),
            AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM,
            422,
        );

        self::assertSame($before, $this->officialSet());
        self::assertSame('registered', $this->statusForOffering(1));
        self::assertNull(DB::table('student_course_registrations')->whereIn('course_offering_id', [4, 5])->value('student_course_registration_id'));
        self::assertSame('submitted', DB::table('student_registration_modification_requests')->value('status'));
        self::assertNull(DB::table('student_registration_modification_items')->whereIn('course_offering_id', [4, 5])->whereNotNull('materialized_student_course_registration_id')->value('materialized_student_course_registration_id'));
    }

    /** @return array{service: RegistrationModificationService, registration: RegistrationService, state: object} */
    private function realWorkflow(array $options = []): array
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T00:00:00Z'));
        $this->phase5Schema();
        $state = (object) [
            'phase' => CourseRegistrationPhase::STUDENT_OPEN,
            'cgpa' => (float) ($options['cgpa'] ?? 2.5),
            'passed' => array_values($options['passed'] ?? []),
            'hardFailureOfferingId' => null,
        ];

        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('buildRegistrationCommitmentContext')->zeroOrMoreTimes()->andReturn([]);
        $requirements->shouldReceive('evaluateRegistrationCandidate')->zeroOrMoreTimes()->andReturn([
            'allowed' => true,
            'reason' => null,
            'requirement_group_id' => null,
        ]);
        $requirements->shouldReceive('validateProjectedCandidates')->zeroOrMoreTimes()->andReturn([]);
        $requirements->shouldReceive('assertRegistrationCandidateAllowed')->zeroOrMoreTimes()
            ->andReturnUsing(function (Student $student, CourseOffering $offering) use ($state): void {
                if ((int) $state->hardFailureOfferingId === (int) $offering->course_offering_id) {
                    throw new RegistrationException(
                        'Canonical academic requirement changed.',
                        ['course_offering_id' => [AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM]],
                        422,
                        AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM,
                    );
                }
            });

        $calendar = Mockery::mock(AcademicCalendarPolicyService::class);
        $calendar->shouldReceive('courseRegistrationDeadlines')->zeroOrMoreTimes()
            ->andReturnUsing(fn (): CourseRegistrationDeadlineResult => $this->deadline($state->phase));
        $coverage = Mockery::mock(CourseOfferingInstructorCoverageService::class);
        $coverage->shouldReceive('requiredRoles')->zeroOrMoreTimes()->andReturn(['theoretical']);
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canStaffAccessStudent')->zeroOrMoreTimes()->andReturnTrue();
        $teaching = Mockery::mock(TeachingAssignmentService::class);
        $schedules = new CourseOfferingScheduleService($coverage, $calendar, $scope, $teaching);
        $grades = Mockery::mock(GradeService::class);
        $grades->shouldReceive('officialCumulativeMetrics')->zeroOrMoreTimes()->andReturnUsing(function () use ($state): array {
            return [
                'cumulative_gpa' => $state->cgpa,
                'official_completed_courses' => collect($state->passed)
                    ->map(fn (int $courseId): array => ['course_id' => $courseId])
                    ->all(),
            ];
        });
        $registration = new RegistrationService($requirements, $calendar, $grades, $schedules);
        $terms = Mockery::mock(AcademicTermResolver::class);
        $terms->shouldReceive('uniqueCurrentAcademicYearId')->zeroOrMoreTimes()->andReturn(1);
        $this->app->instance(AcademicTermResolver::class, $terms);

        return [
            'service' => new RegistrationModificationService($registration, $requirements, $schedules, $terms, $scope),
            'registration' => $registration,
            'state' => $state,
        ];
    }

    private function studentActor(): User
    {
        return User::query()->findOrFail(7);
    }

    private function advisorActor(): User
    {
        $advisor = Mockery::mock(User::class)->makePartial();
        $advisor->setRawAttributes(['user_id' => 8, 'username' => 'advisor']);
        $advisor->exists = true;
        $advisor->shouldReceive('hasPermission')->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $code): bool => in_array($code, ['registration_requests.view', 'registration_requests.review'], true));

        return $advisor;
    }

    private function modification(array $payload): \App\Models\StudentRegistrationModificationRequest
    {
        return \App\Models\StudentRegistrationModificationRequest::query()->findOrFail(
            $payload['student_registration_modification_request_id'],
        );
    }

    private function addOffering(
        int $offeringId,
        int $courseId,
        string $code,
        ?string $startsAt,
        ?string $endsAt,
        int $creditHours = 3,
    ): void {
        DB::table('courses')->insert([
            'course_id' => $courseId,
            'course_code' => $code,
            'course_name' => $code,
            'credit_hours' => $creditHours,
            'theoretical_hours' => 1,
            'practical_hours' => 0,
        ]);
        DB::table('program_courses')->insert([
            'program_course_id' => $courseId,
            'academic_program_id' => 1,
            'course_id' => $courseId,
            'is_active' => 1,
        ]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => $offeringId,
            'course_id' => $courseId,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_program_id' => 1,
            'status' => 'open',
        ]);
        if ($startsAt !== null && $endsAt !== null) {
            $this->scheduleOffering($offeringId, $startsAt, $endsAt);
        }
    }

    private function scheduleOffering(int $offeringId, string $startsAt, string $endsAt): void
    {
        DB::table('course_offering_schedule_slots')->insert([
            'course_offering_id' => $offeringId,
            'component_type' => 'theoretical',
            'day_of_week' => 1,
            'start_time' => $startsAt,
            'end_time' => $endsAt,
            'created_by_user_id' => 7,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }

    private function registerCurrent(int $registrationId, int $offeringId): void
    {
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => $registrationId,
            'student_id' => 1,
            'course_offering_id' => $offeringId,
            'registration_status_id' => 1,
            'registration_date' => '2026-09-01',
            'registered_by_user_id' => 7,
        ]);
    }

    private function approvedInitialForCurrent(): void
    {
        DB::table('student_registration_request_items')->delete();
        DB::table('student_registration_requests')->delete();
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 20,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'approved',
            'submission_version' => 1,
            'first_submitted_at' => '2026-09-02 00:00:00',
            'last_submitted_at' => '2026-09-02 00:00:00',
            'approved_at' => '2026-09-03 00:00:00',
        ]);
        $registrations = DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->where('rs.status_code', 'registered')
            ->orderBy('scr.student_course_registration_id')
            ->get(['scr.student_course_registration_id', 'scr.course_offering_id']);
        foreach ($registrations as $offset => $registration) {
            DB::table('student_registration_request_items')->insert([
                'student_registration_request_item_id' => 30 + $offset,
                'student_registration_request_id' => 20,
                'course_offering_id' => $registration->course_offering_id,
                'student_course_registration_id' => $registration->student_course_registration_id,
            ]);
        }
    }

    private function markRemove(RegistrationModificationService $service, array $draft, int $offeringId): void
    {
        $item = \App\Models\StudentRegistrationModificationItem::query()
            ->where('student_registration_modification_request_id', $draft['student_registration_modification_request_id'])
            ->where('course_offering_id', $offeringId)
            ->firstOrFail();
        $service->toggleBaselineItem($this->student(), $this->studentActor(), $item, 'remove');
    }

    private function insertAddItem(array $draft, int $offeringId): void
    {
        DB::table('student_registration_modification_items')->insert([
            'student_registration_modification_request_id' => $draft['student_registration_modification_request_id'],
            'operation' => 'add',
            'course_offering_id' => $offeringId,
            'created_at' => '2026-09-05 00:00:00',
            'updated_at' => '2026-09-05 00:00:00',
        ]);
    }

    private function statusForOffering(int $offeringId): ?string
    {
        return DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->where('scr.student_id', 1)
            ->where('scr.course_offering_id', $offeringId)
            ->orderByDesc('scr.student_course_registration_id')
            ->value('rs.status_code');
    }

    private function officialSet(): array
    {
        return DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->where('scr.student_id', 1)
            ->where('rs.status_code', 'registered')
            ->orderBy('scr.course_offering_id')
            ->pluck('scr.course_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function captureRequestException(callable $operation, string $code): RegistrationRequestException
    {
        try {
            $operation();
            self::fail('Expected registration request failure '.$code);
        } catch (RegistrationRequestException $exception) {
            self::assertSame($code, $exception->errorCode);

            return $exception;
        }
    }

    private function expectRequestCode(callable $operation, string $code): void
    {
        $this->captureRequestException($operation, $code);
    }

    private function registrationService(): RegistrationService
    {
        return new RegistrationService(
            $this->createMock(AcademicRequirementService::class),
            $this->createMock(AcademicCalendarPolicyService::class),
            $this->createMock(GradeService::class),
            $this->createMock(CourseOfferingScheduleService::class),
        );
    }

    private function deadline(CourseRegistrationPhase $phase): CourseRegistrationDeadlineResult
    {
        return new CourseRegistrationDeadlineResult(
            $phase,
            1,
            1,
            CarbonImmutable::now('UTC'),
            CarbonImmutable::parse('2026-09-01T00:00:00Z'),
            CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            CarbonImmutable::parse('2026-09-10T00:00:00Z'),
        );
    }

    private function approvedInitialRequest(bool $withItemLink = true): void
    {
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 20,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'approved',
            'approved_at' => '2026-09-01 00:00:00',
        ]);
        if ($withItemLink) {
            DB::table('student_registration_request_items')->insert([
                'student_registration_request_item_id' => 30,
                'student_registration_request_id' => 20,
                'course_offering_id' => 1,
                'student_course_registration_id' => 10,
            ]);
        }
    }

    private function student(): Student
    {
        return Student::query()->findOrFail(1);
    }

    private function registration(): StudentCourseRegistration
    {
        return StudentCourseRegistration::query()->findOrFail(10);
    }

    private function currentStatus(): string
    {
        return (string) DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->where('scr.student_course_registration_id', 10)
            ->value('rs.status_code');
    }

    private function expectRegistrationCode(callable $operation, string $code, int $status = 409): void
    {
        try {
            $operation();
            self::fail('Expected registration failure '.$code);
        } catch (RegistrationException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->status);
        }
    }

    private function seed(): void
    {
        DB::table('colleges')->insert(['college_id' => 1, 'college_name' => 'College', 'is_active' => 1]);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1, 'department_name' => 'Department', 'is_active' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => 1, 'department_id' => 1, 'program_name' => 'Program', 'is_active' => 1]);
        DB::table('students')->insert(['student_id' => 1, 'student_number' => 'S1', 'academic_program_id' => 1]);
        DB::table('users')->insert(['user_id' => 7, 'student_id' => 1, 'username' => 'student']);
        DB::table('users')->insert(['user_id' => 8, 'student_id' => null, 'username' => 'advisor']);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027', 'is_current' => 1, 'is_active' => 1]);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'First', 'semester_order' => 1, 'is_active' => 1]);
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'C101', 'course_name' => 'Course', 'credit_hours' => 3, 'theoretical_hours' => 1, 'practical_hours' => 0]);
        DB::table('program_courses')->insert(['program_course_id' => 1, 'academic_program_id' => 1, 'course_id' => 1, 'is_active' => 1]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 1,
            'course_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_program_id' => 1,
            'status' => 'open',
        ]);
        DB::table('registration_statuses')->insert([
            ['registration_status_id' => 1, 'status_code' => 'registered'],
            ['registration_status_id' => 2, 'status_code' => 'dropped'],
            ['registration_status_id' => 3, 'status_code' => 'withdrawn'],
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 10,
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 1,
            'registration_date' => '2026-09-01',
            'registered_by_user_id' => 7,
        ]);
        $this->scheduleOffering(1, '08:00', '09:00');
    }

    private function schema(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('colleges', function (Blueprint $table): void {
            $table->increments('college_id');
            $table->string('college_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id');
            $table->string('department_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->integer('department_id');
            $table->string('program_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->integer('student_id')->nullable();
            $table->integer('employee_id')->nullable();
            $table->string('username')->nullable();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('year_name')->nullable();
            $table->boolean('is_current')->default(false);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_name')->nullable();
            $table->integer('semester_order')->default(1);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->integer('theoretical_hours')->default(1);
            $table->integer('practical_hours')->default(0);
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->increments('program_course_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('course_prerequisites', function (Blueprint $table): void {
            $table->increments('course_prerequisite_id');
            $table->integer('course_id');
            $table->integer('prerequisite_course_id');
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('academic_program_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('registration_statuses', function (Blueprint $table): void {
            $table->increments('registration_status_id');
            $table->string('status_code')->unique();
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->increments('student_course_registration_id');
            $table->integer('student_id');
            $table->integer('course_offering_id');
            $table->integer('registration_status_id');
            $table->integer('result_status_id')->nullable();
            $table->date('registration_date')->nullable();
            $table->integer('registered_by_user_id')->nullable();
            $table->integer('advisor_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->integer('submission_version')->default(1);
            $table->dateTime('first_submitted_at')->nullable();
            $table->dateTime('last_submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
            $table->integer('student_course_registration_id')->nullable();
        });
        Schema::create('student_registration_withdrawal_requests', function (Blueprint $table): void {
            $table->increments('student_registration_withdrawal_request_id');
            $table->integer('student_course_registration_id');
            $table->integer('current_slot')->nullable();
        });
        Schema::create('course_offering_schedule_slots', function (Blueprint $table): void {
            $table->increments('course_offering_schedule_slot_id');
            $table->integer('course_offering_id');
            $table->string('component_type');
            $table->integer('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location_label')->nullable();
            $table->integer('created_by_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_materializations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_materialization_id');
            $table->integer('student_course_registration_id');
        });
        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->increments('supplementary_exam_period_id');
            $table->string('status');
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->increments('supplementary_exam_offering_id');
            $table->integer('supplementary_exam_period_id');
        });
        Schema::create('supplementary_exam_registrations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_course_registration_id');
            $table->string('status');
            $table->integer('current_slot')->nullable();
        });
    }

    private function phase5Schema(): void
    {
        Schema::create('student_registration_modification_requests', function (Blueprint $table): void {
            $table->increments('student_registration_modification_request_id');
            $table->integer('initial_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status')->default('draft');
            $table->integer('submission_version')->default(0);
            $table->integer('current_slot')->nullable()->default(1);
            $table->text('student_notes')->nullable();
            $table->integer('advisor_user_id')->nullable();
            $table->text('advisor_notes')->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->dateTime('last_submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('materialized_at')->nullable();
            $table->integer('registered_hours_before_approval')->nullable();
            $table->integer('removed_hours_at_approval')->nullable();
            $table->integer('added_hours_at_approval')->nullable();
            $table->integer('projected_hours_at_approval')->nullable();
            $table->integer('max_allowed_hours_at_approval')->nullable();
            $table->integer('remaining_hours_after_approval')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id', 'semester_id', 'current_slot']);
        });
        Schema::create('student_registration_modification_items', function (Blueprint $table): void {
            $table->increments('student_registration_modification_item_id');
            $table->integer('student_registration_modification_request_id');
            $table->string('operation');
            $table->integer('course_offering_id');
            $table->integer('source_student_course_registration_id')->nullable();
            $table->integer('materialized_student_course_registration_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_modification_events', function (Blueprint $table): void {
            $table->increments('student_registration_modification_event_id');
            $table->integer('student_registration_modification_request_id');
            $table->string('event_type');
            $table->integer('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('submission_version')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
        });
    }
}
