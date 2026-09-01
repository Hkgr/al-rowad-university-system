<?php

namespace Tests\Feature;

use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\CourseOffering;
use App\Models\CourseOfferingClosureRequest;
use App\Models\User;
use App\Services\AcademicCalendarPolicyService;
use App\Services\CourseOfferingClosureWorkflowService;
use App\Services\DataScopeService;
use App\Services\MinimumEnrollmentCancellationMaterializer;
use App\Services\MinimumEnrollmentReviewService;
use App\Services\RegistrationService;
use App\Services\RegistrationReplacementService;
use App\Services\AcademicRequirementService;
use App\Services\CourseOfferingScheduleService;
use App\Services\GradeService;
use App\Models\StudentRegistrationReplacementItem;
use App\Models\StudentRegistrationReplacementRequest;
use App\Services\SemesterOfferingGovernanceService;
use App\Services\TeachingAssignmentService;
use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Support\CourseOfferingClosureWorkflow;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use App\Support\SemesterOfferingGovernance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SemesterRegistrationMinimumCancellationReplacementPhase6BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        DB::table('registration_statuses')->insert([
            ['registration_status_id'=>1,'status_code'=>'registered'],
            ['registration_status_id'=>2,'status_code'=>'cancelled'],
            ['registration_status_id'=>3,'status_code'=>'dropped'],
            ['registration_status_id'=>4,'status_code'=>'withdrawn'],
        ]);
    }

    public function test_closed_before_first_reconciliation_creates_terminal_superseded_without_cancellation_or_replacement_rights(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('semester_offering_requests')->insert(['semester_offering_request_id'=>1,'course_offering_id'=>1,'program_course_id'=>1,'is_selected'=>1,'minimum_enrollment'=>10,'status'=>'approved','materialized_at'=>'2026-09-01 00:00:00']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1]);

        $this->minimumService()->reconcileTerm(1, 1);

        $review = CourseOfferingMinimumEnrollmentReview::query()->firstOrFail();
        self::assertSame('superseded', $review->status);
        self::assertSame(1, $review->enrolled_count_snapshot);
        self::assertSame('superseded_external_closure', DB::table('course_offering_minimum_enrollment_events')->value('event_type'));
        self::assertSame('registered', DB::table('registration_statuses')->join('student_course_registrations','student_course_registrations.registration_status_id','=','registration_statuses.registration_status_id')->value('status_code'));
        self::assertSame(0, DB::table('student_registration_replacement_items')->count());
    }

    public function test_expired_and_superseded_history_can_reuse_source_until_one_materialization_consumes_it(): void
    {
        DB::table('student_registration_replacement_items')->insert(['student_registration_replacement_item_id'=>1,'student_registration_replacement_request_id'=>1,'source_minimum_enrollment_review_id'=>1,'source_student_course_registration_id'=>50,'replacement_course_offering_id'=>101,'source_consumed_slot'=>null]);
        DB::table('student_registration_replacement_items')->insert(['student_registration_replacement_item_id'=>2,'student_registration_replacement_request_id'=>2,'source_minimum_enrollment_review_id'=>1,'source_student_course_registration_id'=>50,'replacement_course_offering_id'=>102,'source_consumed_slot'=>null]);
        DB::table('student_registration_replacement_items')->insert(['student_registration_replacement_item_id'=>3,'student_registration_replacement_request_id'=>3,'source_minimum_enrollment_review_id'=>1,'source_student_course_registration_id'=>50,'replacement_course_offering_id'=>103,'source_consumed_slot'=>1]);
        self::assertSame(3, DB::table('student_registration_replacement_items')->where('source_student_course_registration_id',50)->count());

        $this->expectException(QueryException::class);
        DB::table('student_registration_replacement_items')->insert(['student_registration_replacement_item_id'=>4,'student_registration_replacement_request_id'=>4,'source_minimum_enrollment_review_id'=>1,'source_student_course_registration_id'=>50,'replacement_course_offering_id'=>104,'source_consumed_slot'=>1]);
    }

    public function test_ordinary_closure_without_a_linked_phase6_review_does_not_cancel_registrations(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1]);
        $registrations=Mockery::mock(RegistrationService::class);
        $registrations->shouldNotReceive('transitionRegisteredToCancelled');

        (new MinimumEnrollmentCancellationMaterializer($registrations))->materializeIfLinked(CourseOffering::query()->findOrFail(1),99,$this->actor());

        self::assertSame('registered',$this->registrationStatus(1));
    }

    public function test_linked_formal_minimum_closure_cancels_current_rows_and_finalizes_review_atomically(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('semester_offering_requests')->insert(['semester_offering_request_id'=>1,'course_offering_id'=>1,'program_course_id'=>1,'is_selected'=>1,'minimum_enrollment'=>10,'status'=>'approved','materialized_at'=>'2026-09-01 00:00:00']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1]);
        DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>1,'semester_offering_request_id'=>1,'course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'minimum_enrollment_snapshot'=>10,'enrolled_count_snapshot'=>1,'finalization_deadline_at'=>'2026-09-08 00:00:00','finalized_at'=>'2026-09-09 00:00:00','status'=>'closure_pending','dean_recommendation'=>'cancel','dean_user_id'=>10,'dean_notes'=>'cancel','dean_recommended_at'=>'2026-09-09 00:00:00','scientific_decision'=>'cancel','scientific_user_id'=>20,'scientific_notes'=>'cancel','scientific_decided_at'=>'2026-09-09 01:00:00','course_offering_closure_request_id'=>30]);
        $registrations=Mockery::mock(RegistrationService::class);
        $registrations->shouldReceive('transitionRegisteredToCancelled')->once()->andReturnUsing(function($row):void{$row->update(['registration_status_id'=>2]);});

        DB::transaction(fn()=>(new MinimumEnrollmentCancellationMaterializer($registrations))->materializeIfLinked(CourseOffering::query()->findOrFail(1),30,$this->actor()));

        self::assertSame('cancelled',$this->registrationStatus(1));
        $review=CourseOfferingMinimumEnrollmentReview::query()->findOrFail(1);
        self::assertSame('cancelled',$review->status);
        self::assertSame(1,$review->affected_registration_count);
        self::assertNotNull($review->cancelled_at);
    }

    public function test_full_minimum_cancellation_bridge_requires_both_vps_and_cancels_only_on_administrative_materialization(): void
    {
        DB::table('users')->insert([
            ['user_id'=>10,'username'=>'dean'],
            ['user_id'=>20,'username'=>'scientific'],
            ['user_id'=>30,'username'=>'administrative'],
        ]);
        DB::table('colleges')->insert(['college_id'=>1,'college_name'=>'Medicine']);
        DB::table('departments')->insert(['department_id'=>1,'college_id'=>1,'department_name'=>'Medicine']);
        DB::table('academic_programs')->insert(['academic_program_id'=>1,'department_id'=>1,'program_name'=>'Medicine']);
        DB::table('academic_years')->insert(['academic_year_id'=>1,'year_name'=>'2026-2027']);
        DB::table('semesters')->insert(['semester_id'=>1,'semester_code'=>'first','semester_name'=>'First']);
        DB::table('courses')->insert(['course_id'=>1,'course_code'=>'MED101','course_name'=>'Medicine','credit_hours'=>3]);
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'course_id'=>1,'academic_program_id'=>1,'department_id'=>1,
            'academic_year_id'=>1,'semester_id'=>1,'status'=>'open']);
        DB::table('semester_offering_requests')->insert(['semester_offering_request_id'=>1,'course_offering_id'=>1,'program_course_id'=>1,
            'is_selected'=>1,'minimum_enrollment'=>3,'status'=>'approved','materialized_at'=>'2026-09-01 00:00:00']);
        DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>1,
            'semester_offering_request_id'=>1,'course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,
            'minimum_enrollment_snapshot'=>3,'enrolled_count_snapshot'=>2,'finalization_deadline_at'=>'2026-09-08 00:00:00',
            'finalized_at'=>'2026-09-09 00:00:00','status'=>'under_minimum']);
        DB::table('student_course_registrations')->insert([
            ['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1],
            ['student_course_registration_id'=>2,'student_id'=>2,'course_offering_id'=>1,'registration_status_id'=>1],
            ['student_course_registration_id'=>3,'student_id'=>3,'course_offering_id'=>1,'registration_status_id'=>3],
        ]);

        $scope=Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canAccessOffering')->zeroOrMoreTimes()->andReturnTrue();
        $scope->shouldReceive('hasActualUniversityScope')->zeroOrMoreTimes()->andReturnTrue();
        $assignments=Mockery::mock(TeachingAssignmentService::class);
        $assignments->shouldReceive('accessibleCollegeIdList')->zeroOrMoreTimes()->andReturn([]);
        $registration=$this->realRegistrationService();
        $closures=new CourseOfferingClosureWorkflowService(
            $assignments,
            $scope,
            new MinimumEnrollmentCancellationMaterializer($registration),
        );
        $calendar=Mockery::mock(AcademicCalendarPolicyService::class);
        $governance=Mockery::mock(SemesterOfferingGovernanceService::class);
        $minimum=new MinimumEnrollmentReviewService($calendar,$scope,$closures,$governance);
        $dean=$this->roleActor(10,'isDean',SemesterOfferingGovernance::PERMISSION_MANAGE);
        $scientific=$this->roleActor(20,'isScientificVicePresident',SemesterOfferingGovernance::PERMISSION_REVIEW_SCIENTIFIC);
        $administrative=$this->roleActor(30,'isAdministrativeVicePresident',CourseOfferingClosureWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE);

        $minimum->recommend($dean,CourseOfferingMinimumEnrollmentReview::query()->findOrFail(1),'cancel','Below the approved minimum.');
        $minimum->decide($scientific,CourseOfferingMinimumEnrollmentReview::query()->findOrFail(1),'cancel','Cancel after Dean recommendation.');

        $closure=CourseOfferingClosureRequest::query()->firstOrFail();
        self::assertSame('submitted',$closure->status);
        self::assertSame('approved',DB::table('course_offering_closure_reviews')->where('review_authority','scientific')->value('status'));
        self::assertSame('pending',DB::table('course_offering_closure_reviews')->where('review_authority','administrative')->value('status'));
        self::assertSame('open',DB::table('course_offerings')->where('course_offering_id',1)->value('status'));
        self::assertSame('registered',$this->registrationStatus(1));
        self::assertSame('registered',$this->registrationStatus(2));

        $closures->approveAdministrative($administrative,$closure);

        self::assertSame('closed',DB::table('course_offerings')->where('course_offering_id',1)->value('status'));
        self::assertSame('cancelled',$this->registrationStatus(1));
        self::assertSame('cancelled',$this->registrationStatus(2));
        self::assertSame('dropped',$this->registrationStatus(3));
        self::assertDatabaseHas('course_offering_minimum_enrollment_reviews',[
            'course_offering_minimum_enrollment_review_id'=>1,'status'=>'cancelled','affected_registration_count'=>2,
        ]);
        self::assertDatabaseHas('course_offering_closure_requests',[
            'course_offering_closure_request_id'=>$closure->getKey(),'status'=>'approved','current_slot'=>null,
        ]);
        self::assertNotNull(DB::table('course_offering_closure_requests')->where('course_offering_closure_request_id',$closure->getKey())->value('materialized_at'));
        self::assertDatabaseHas('course_offering_minimum_enrollment_events',['course_offering_minimum_enrollment_review_id'=>1,'event_type'=>'cancellation_materialized']);
        self::assertDatabaseHas('course_offering_closure_events',['course_offering_closure_request_id'=>$closure->getKey(),'event_type'=>'materialized']);
    }

    public function test_minimum_cannot_finalize_before_or_at_the_inclusive_advisor_deadline(): void
    {
        $this->seedApplicableOffering(1, 2, 'open');
        foreach ([CourseRegistrationPhase::STUDENT_OPEN, CourseRegistrationPhase::ADVISOR_REVIEW] as $phase) {
            try {
                $this->minimumService($phase)->reconcileTerm(1, 1);
                self::fail('Finalization unexpectedly succeeded before the advisor deadline closed.');
            } catch (SemesterRegistrationPhase6Exception $exception) {
                self::assertSame('minimum_enrollment_not_finalizable', $exception->errorCode);
            }
        }
        self::assertSame(0, DB::table('course_offering_minimum_enrollment_reviews')->count());
    }

    public function test_registered_count_excludes_dropped_withdrawn_cancelled_and_pending_workflow_rows(): void
    {
        $this->seedApplicableOffering(1, 2, 'open');
        foreach ([1=>1,2=>1,3=>3,4=>4,5=>2] as $id=>$statusId) {
            DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>$id,'course_offering_id'=>1,'registration_status_id'=>$statusId]);
        }
        Schema::create('student_registration_request_items',function(Blueprint $t):void{$t->increments('student_registration_request_item_id');$t->integer('course_offering_id');$t->string('status')->nullable();});
        Schema::create('student_registration_modification_items',function(Blueprint $t):void{$t->increments('student_registration_modification_item_id');$t->integer('course_offering_id');$t->string('operation');});
        DB::table('student_registration_request_items')->insert(['course_offering_id'=>1,'status'=>'submitted']);
        DB::table('student_registration_modification_items')->insert(['course_offering_id'=>1,'operation'=>'add']);

        $this->minimumService()->reconcileTerm(1,1);

        $review=CourseOfferingMinimumEnrollmentReview::query()->firstOrFail();
        self::assertSame(2,$review->enrolled_count_snapshot);
        self::assertSame('satisfied',$review->status);
    }

    public function test_minimum_comparison_handles_above_equal_and_below_and_is_idempotent(): void
    {
        foreach ([[1,2,3,'satisfied'],[2,2,2,'satisfied'],[3,3,2,'under_minimum']] as [$offeringId,$minimum,$registered,$expected]) {
            $this->seedApplicableOffering($offeringId,$minimum,'open');
            for($i=1;$i<=$registered;$i++)DB::table('student_course_registrations')->insert([
                'student_course_registration_id'=>$offeringId*10+$i,'student_id'=>$offeringId*10+$i,'course_offering_id'=>$offeringId,'registration_status_id'=>1,
            ]);
        }
        $service=$this->minimumService();$service->reconcileTerm(1,1);
        self::assertSame(['satisfied','satisfied','under_minimum'],CourseOfferingMinimumEnrollmentReview::query()->orderBy('course_offering_id')->pluck('status')->all());
        $snapshots=CourseOfferingMinimumEnrollmentReview::query()->orderBy('course_offering_id')->get()->map->only(['minimum_enrollment_snapshot','enrolled_count_snapshot','finalized_at'])->all();
        $service=$this->minimumService();$service->reconcileTerm(1,1);
        self::assertSame(3,CourseOfferingMinimumEnrollmentReview::query()->count());
        self::assertSame($snapshots,CourseOfferingMinimumEnrollmentReview::query()->orderBy('course_offering_id')->get()->map->only(['minimum_enrollment_snapshot','enrolled_count_snapshot','finalized_at'])->all());
    }

    public function test_contradictory_phase1_minimum_fails_closed_without_review(): void
    {
        $this->seedApplicableOffering(1,10,'open');
        $governance=Mockery::mock(SemesterOfferingGovernanceService::class);
        $governance->shouldReceive('assertMinimumEnrollmentApplicability')->once()->andThrow(
            SemesterRegistrationPhase6Exception::fail('minimum_enrollment_configuration_invalid','Contradictory Phase 1 minimum.')
        );
        try {
            $this->minimumService(CourseRegistrationPhase::CLOSED,$governance)->reconcileTerm(1,1);
            self::fail('Contradictory minimum unexpectedly entered cancellation governance.');
        } catch (SemesterRegistrationPhase6Exception $exception) {
            self::assertSame('minimum_enrollment_configuration_invalid',$exception->errorCode);
        }
        self::assertSame(0,CourseOfferingMinimumEnrollmentReview::query()->count());
    }

    public function test_trusted_boundary_revalidates_complete_request_and_materializes_nothing_when_aggregate_exceeds_cap(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('courses')->insert([['course_id'=>10,'credit_hours'=>3],['course_id'=>11,'credit_hours'=>3],['course_id'=>20,'credit_hours'=>3],['course_id'=>21,'credit_hours'=>3]]);
        foreach([[1,10,'closed'],[2,11,'closed'],[3,20,'open'],[4,21,'open']] as [$id,$course,$status])DB::table('course_offerings')->insert([
            'course_offering_id'=>$id,'course_id'=>$course,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>$status,
        ]);
        foreach([1,2] as $id){DB::table('course_offering_closure_requests')->insert(['course_offering_closure_request_id'=>$id,'course_offering_id'=>$id,'status'=>'approved','materialized_at'=>'2026-09-09 00:00:00']);
            DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>$id,'semester_offering_request_id'=>$id,
                'course_offering_id'=>$id,'academic_year_id'=>1,'semester_id'=>1,'minimum_enrollment_snapshot'=>10,'enrolled_count_snapshot'=>1,
                'finalization_deadline_at'=>'2026-09-08 00:00:00','finalized_at'=>'2026-09-09 00:00:00','status'=>'cancelled','course_offering_closure_request_id'=>$id]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>1,'course_offering_id'=>$id,'registration_status_id'=>2]);}
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>9,'student_id'=>1,
            'academic_year_id'=>1,'semester_id'=>1,'status'=>'submitted','submission_version'=>1,'current_slot'=>1,'last_submitted_at'=>'2026-09-05 00:00:00']);
        foreach([[1,1,3],[2,2,4]] as [$id,$source,$target])DB::table('student_registration_replacement_items')->insert([
            'student_registration_replacement_item_id'=>$id,'student_registration_replacement_request_id'=>1,'source_minimum_enrollment_review_id'=>$source,
            'source_student_course_registration_id'=>$source,'replacement_course_offering_id'=>$target,'source_consumed_slot'=>null,
        ]);

        $calendar=Mockery::mock(AcademicCalendarPolicyService::class);$calendar->shouldReceive('courseRegistrationReplacementDeadlines')->once()->andReturn(
            new CourseRegistrationDeadlineResult(CourseRegistrationPhase::ADVISOR_REVIEW,1,1,CarbonImmutable::parse('2026-09-07T00:00:00Z'),
                CarbonImmutable::parse('2026-09-01T00:00:00Z'),CarbonImmutable::parse('2026-09-05T00:00:00Z'),CarbonImmutable::parse('2026-09-08T00:00:00Z'),9,90)
        );
        $requirements=Mockery::mock(AcademicRequirementService::class);$requirements->shouldReceive('validateProjectedCandidates')->once()->andReturn([]);
        $schedules=Mockery::mock(CourseOfferingScheduleService::class);$schedules->shouldReceive('registrationEvaluations')->once()->andReturn([]);
        $registration=Mockery::mock(RegistrationService::class,[$requirements,$calendar,Mockery::mock(GradeService::class),$schedules])->makePartial();
        $registration->shouldReceive('evaluateRegistrationCandidatesForProjection')->once()->andReturn([]);
        $registration->shouldReceive('currentOfferingIds')->once()->andReturn([]);
        $registration->shouldReceive('hoursSnapshot')->once()->andReturn(['registered_hours'=>18,'max_allowed_hours'=>18,'official_cgpa'=>2.5]);
        $governance=Mockery::mock(SemesterOfferingGovernanceService::class);$governance->shouldReceive('assertFinallyApprovedForReplacement')->twice()->andReturnNull();
        $this->app->instance(SemesterOfferingGovernanceService::class,$governance);
        $request=StudentRegistrationReplacementRequest::query()->findOrFail(1);$item=StudentRegistrationReplacementItem::query()->findOrFail(1);

        try{DB::transaction(fn()=>$registration->materializeAdvisorApprovedReplacementItemWithinTransaction($request,$item,99,CarbonImmutable::parse('2026-09-07T00:00:00Z')));
            self::fail('Trusted boundary materialized one target from an aggregate-invalid request.');}
        catch(SemesterRegistrationPhase6Exception $exception){self::assertSame('registration_replacement_validation_failed',$exception->errorCode);}

        self::assertSame(0,DB::table('student_course_registrations')->whereIn('course_offering_id',[3,4])->count());
        self::assertSame(0,DB::table('student_registration_replacement_items')->whereNotNull('materialized_student_course_registration_id')->count());
    }

    public function test_multi_target_replacement_rolls_back_first_materialization_when_second_target_fails_inside_trusted_boundary(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,
            'event_type_code'=>'course_registration_replacement','is_active'=>1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id'=>9,
            'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>null]);
        DB::table('academic_years')->insert(['academic_year_id'=>1,'year_name'=>'2026-2027']);
        DB::table('semesters')->insert(['semester_id'=>1,'semester_code'=>'first','semester_name'=>'First']);
        DB::table('users')->insert(['user_id'=>99,'username'=>'advisor']);
        DB::table('courses')->insert([
            ['course_id'=>10,'course_code'=>'SRC10','course_name'=>'Source 10','credit_hours'=>3],
            ['course_id'=>11,'course_code'=>'SRC11','course_name'=>'Source 11','credit_hours'=>3],
            ['course_id'=>20,'course_code'=>'DST20','course_name'=>'Target 20','credit_hours'=>3],
            ['course_id'=>21,'course_code'=>'DST21','course_name'=>'Target 21','credit_hours'=>3],
        ]);
        foreach([[1,10,'closed'],[2,11,'closed'],[3,20,'open'],[4,21,'open']] as [$id,$course,$status])DB::table('course_offerings')->insert([
            'course_offering_id'=>$id,'course_id'=>$course,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>$status,
        ]);
        foreach([1,2] as $id){
            DB::table('course_offering_closure_requests')->insert(['course_offering_closure_request_id'=>$id,'course_offering_id'=>$id,
                'status'=>'approved','materialized_at'=>'2026-09-09 00:00:00']);
            DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>$id,
                'semester_offering_request_id'=>$id,'course_offering_id'=>$id,'academic_year_id'=>1,'semester_id'=>1,
                'minimum_enrollment_snapshot'=>10,'enrolled_count_snapshot'=>1,'finalization_deadline_at'=>'2026-09-08 00:00:00',
                'finalized_at'=>'2026-09-09 00:00:00','status'=>'cancelled','course_offering_closure_request_id'=>$id]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>1,
                'course_offering_id'=>$id,'registration_status_id'=>2]);
        }
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,
            'academic_calendar_event_id'=>9,'student_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'submitted',
            'submission_version'=>1,'current_slot'=>1,'last_submitted_at'=>'2026-09-05 00:00:00']);
        foreach([[1,1,3],[2,2,4]] as [$id,$source,$target])DB::table('student_registration_replacement_items')->insert([
            'student_registration_replacement_item_id'=>$id,'student_registration_replacement_request_id'=>1,
            'source_minimum_enrollment_review_id'=>$source,'source_student_course_registration_id'=>$source,
            'replacement_course_offering_id'=>$target,'source_consumed_slot'=>null,
        ]);

        $calendar=Mockery::mock(AcademicCalendarPolicyService::class);
        $calendar->shouldReceive('courseRegistrationReplacementDeadlines')->twice()->andReturn(
            new CourseRegistrationDeadlineResult(CourseRegistrationPhase::ADVISOR_REVIEW,1,1,CarbonImmutable::parse('2026-09-07T00:00:00Z'),
                CarbonImmutable::parse('2026-09-01T00:00:00Z'),CarbonImmutable::parse('2026-09-05T00:00:00Z'),
                CarbonImmutable::parse('2026-09-08T00:00:00Z'),9,90)
        );
        $requirements=Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('validateProjectedCandidates')->twice()->andReturn([]);
        $requirements->shouldReceive('assertRegistrationCandidateAllowed')->twice()->andReturnNull();
        $grades=Mockery::mock(GradeService::class);
        $grades->shouldReceive('officialCumulativeMetrics')->zeroOrMoreTimes()->andReturn([
            'cumulative_gpa'=>2.5,'official_completed_courses'=>[],
        ]);
        $schedules=Mockery::mock(CourseOfferingScheduleService::class);
        $schedules->shouldReceive('registrationEvaluations')->zeroOrMoreTimes()->andReturnUsing(
            fn($student,$offerings)=>collect($offerings)->mapWithKeys(fn($offering)=>[(int)$offering->getKey()=>['reason'=>null]])->all()
        );
        $registration=Mockery::mock(RegistrationService::class,[$requirements,$calendar,$grades,$schedules])->makePartial();
        $registration->shouldReceive('evaluateRegistrationCandidatesForProjection')->twice()->andReturn([]);
        $registration->shouldReceive('currentOfferingIds')->times(4)->andReturn([]);
        $governance=Mockery::mock(SemesterOfferingGovernanceService::class);
        $governance->shouldReceive('assertFinallyApprovedForReplacement')->times(4)->andReturnNull();
        $this->app->instance(SemesterOfferingGovernanceService::class,$governance);
        $scope=Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canStaffAccessStudent')->once()->andReturnTrue();
        $service=new RegistrationReplacementService($registration,$requirements,$schedules,$governance,$scope);
        $advisor=Mockery::mock(User::class)->makePartial();
        $advisor->setAttribute('user_id',99);
        $advisor->shouldReceive('hasPermission')->once()->with('registration_requests.review')->andReturnTrue();
        DB::unprepared("CREATE TRIGGER fail_second_replacement_target BEFORE INSERT ON student_course_registrations WHEN NEW.course_offering_id = 4 BEGIN SELECT RAISE(ABORT, 'deterministic_target_y_failure'); END");

        try {
            $service->approve($advisor,StudentRegistrationReplacementRequest::query()->findOrFail(1));
            self::fail('The second target failure did not abort the trusted replacement transaction.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('deterministic_target_y_failure',$exception->getMessage());
        }

        self::assertSame(0,DB::table('student_course_registrations')->whereIn('course_offering_id',[3,4])->count());
        self::assertSame(0,DB::table('student_registration_replacement_items')->whereNotNull('materialized_student_course_registration_id')->count());
        self::assertSame(0,DB::table('student_registration_replacement_items')->where('source_consumed_slot',1)->count());
        self::assertDatabaseHas('student_registration_replacement_requests',[
            'student_registration_replacement_request_id'=>1,'status'=>'submitted','current_slot'=>1,
            'approved_at'=>null,'materialized_at'=>null,
        ]);
        self::assertSame(0,DB::table('student_registration_replacement_events')->whereIn('event_type',['approved','materialized'])->count());
        self::assertSame('cancelled',$this->registrationStatus(1));
        self::assertSame('cancelled',$this->registrationStatus(2));
    }

    public function test_replacement_event_change_supersedes_old_draft_without_rebinding_it(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,'event_type_code'=>'course_registration_replacement','is_active'=>1]);
        DB::table('academic_calendar_events')->insert([
            ['academic_calendar_event_id'=>8,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>'2026-09-02 00:00:00'],
            ['academic_calendar_event_id'=>9,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>null],
        ]);
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>8,'student_id'=>1,
            'academic_year_id'=>1,'semester_id'=>1,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);
        $deadline=$this->replacementDeadline(9);

        try{$this->replacementService($deadline)->updateNotes(\App\Models\Student::query()->findOrFail(1),$this->actor(),'note',1,1);self::fail('Old event draft was silently rebound.');}
        catch(SemesterRegistrationPhase6Exception $exception){self::assertSame('registration_replacement_stale',$exception->errorCode);}

        self::assertDatabaseHas('student_registration_replacement_requests',['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>8,'status'=>'superseded','current_slot'=>null]);
        self::assertDatabaseHas('student_registration_replacement_events',['student_registration_replacement_request_id'=>1,'event_type'=>'superseded_calendar_event_changed']);
    }

    public function test_create_commits_stale_supersession_before_reporting_that_no_eligible_source_remains(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,'event_type_code'=>'course_registration_replacement','is_active'=>1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id'=>9,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>null]);
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>8,'student_id'=>1,
            'academic_year_id'=>1,'semester_id'=>1,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);

        try{$this->replacementService($this->replacementDeadline(9))->create(\App\Models\Student::query()->findOrFail(1),$this->actor(),1,1);
            self::fail('Create unexpectedly ignored that no eligible replacement source remained.');}
        catch(SemesterRegistrationPhase6Exception $exception){self::assertSame('replacement_source_not_eligible',$exception->errorCode);}

        self::assertDatabaseHas('student_registration_replacement_requests',['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>8,
            'status'=>'superseded','current_slot'=>null]);
        self::assertDatabaseHas('student_registration_replacement_events',['student_registration_replacement_request_id'=>1,'event_type'=>'superseded_calendar_event_changed']);
        self::assertSame(0,StudentRegistrationReplacementRequest::query()->where('current_slot',1)->count());
    }

    public function test_stale_source_is_persistently_superseded_before_conflict_is_returned(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,'event_type_code'=>'course_registration_replacement','is_active'=>1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id'=>9,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>null]);
        DB::table('courses')->insert(['course_id'=>1,'credit_hours'=>3]);
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('course_offering_closure_requests')->insert(['course_offering_closure_request_id'=>1,'course_offering_id'=>1,'status'=>'approved','materialized_at'=>'2026-09-09 00:00:00']);
        DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>1,'semester_offering_request_id'=>1,
            'course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'minimum_enrollment_snapshot'=>10,'enrolled_count_snapshot'=>1,
            'finalization_deadline_at'=>'2026-09-08 00:00:00','finalized_at'=>'2026-09-09 00:00:00','status'=>'cancelled','course_offering_closure_request_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1]);
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>9,'student_id'=>1,
            'academic_year_id'=>1,'semester_id'=>1,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);
        DB::table('student_registration_replacement_items')->insert(['student_registration_replacement_item_id'=>1,'student_registration_replacement_request_id'=>1,
            'source_minimum_enrollment_review_id'=>1,'source_student_course_registration_id'=>1,'replacement_course_offering_id'=>1]);

        try{$this->replacementService($this->replacementDeadline(9))->updateNotes(\App\Models\Student::query()->findOrFail(1),$this->actor(),'note',1,1);self::fail('Changed source provenance was not terminally persisted.');}
        catch(SemesterRegistrationPhase6Exception $exception){self::assertSame('registration_replacement_stale',$exception->errorCode);}

        self::assertDatabaseHas('student_registration_replacement_requests',['student_registration_replacement_request_id'=>1,'status'=>'superseded','current_slot'=>null]);
        self::assertDatabaseHas('student_registration_replacement_events',['student_registration_replacement_request_id'=>1,'event_type'=>'superseded_source_changed']);
    }

    public function test_current_registered_target_is_rejected_before_request_submission(): void
    {
        DB::table('students')->insert(['student_id'=>1,'academic_program_id'=>1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,'event_type_code'=>'course_registration_replacement','is_active'=>1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id'=>9,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'cancelled_at'=>null]);
        DB::table('courses')->insert([['course_id'=>1,'credit_hours'=>3],['course_id'=>2,'credit_hours'=>3]]);
        DB::table('course_offerings')->insert([['course_offering_id'=>1,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed'],['course_offering_id'=>2,'course_id'=>2,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'open']]);
        DB::table('course_offering_closure_requests')->insert(['course_offering_closure_request_id'=>1,'course_offering_id'=>1,'status'=>'approved','materialized_at'=>'2026-09-09 00:00:00']);
        DB::table('course_offering_minimum_enrollment_reviews')->insert(['course_offering_minimum_enrollment_review_id'=>1,'semester_offering_request_id'=>1,'course_offering_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'minimum_enrollment_snapshot'=>10,'enrolled_count_snapshot'=>1,'finalization_deadline_at'=>'2026-09-08 00:00:00','finalized_at'=>'2026-09-09 00:00:00','status'=>'cancelled','course_offering_closure_request_id'=>1]);
        DB::table('student_course_registrations')->insert([['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>2],['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]]);
        DB::table('student_registration_replacement_requests')->insert(['student_registration_replacement_request_id'=>1,'academic_calendar_event_id'=>9,'student_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);
        $registration=Mockery::mock(RegistrationService::class);$registration->shouldReceive('courseRegistrationReplacementDeadlines')->andReturn($this->replacementDeadline(9));$registration->shouldReceive('currentOfferingIds')->once()->andReturn([2]);
        $service=new RegistrationReplacementService($registration,Mockery::mock(AcademicRequirementService::class),Mockery::mock(CourseOfferingScheduleService::class),Mockery::mock(SemesterOfferingGovernanceService::class),Mockery::mock(DataScopeService::class));

        try{$service->addItem(\App\Models\Student::query()->findOrFail(1),$this->actor(),1,1,1,2);self::fail('A current registered target was accepted.');}
        catch(SemesterRegistrationPhase6Exception $exception){self::assertSame('replacement_target_already_registered',$exception->errorCode);}
        self::assertSame(0,DB::table('student_registration_replacement_items')->count());
    }

    private function minimumService(CourseRegistrationPhase $phase=CourseRegistrationPhase::CLOSED, ?SemesterOfferingGovernanceService $governance=null): MinimumEnrollmentReviewService
    {
        $calendar=Mockery::mock(AcademicCalendarPolicyService::class);
        $evaluated=$phase===CourseRegistrationPhase::ADVISOR_REVIEW?'2026-09-08T00:00:00Z':($phase===CourseRegistrationPhase::STUDENT_OPEN?'2026-09-05T00:00:00Z':'2026-09-09T00:00:00Z');
        $calendar->shouldReceive('courseRegistrationDeadlines')->once()->andReturn(new CourseRegistrationDeadlineResult($phase,1,1,CarbonImmutable::parse($evaluated),CarbonImmutable::parse('2026-09-01T00:00:00Z'),CarbonImmutable::parse('2026-09-05T00:00:00Z'),CarbonImmutable::parse('2026-09-08T00:00:00Z')));
        if($governance===null){$governance=Mockery::mock(SemesterOfferingGovernanceService::class);$governance->shouldReceive('assertMinimumEnrollmentApplicability')->zeroOrMoreTimes()->andReturnNull();}
        return new MinimumEnrollmentReviewService($calendar, Mockery::mock(DataScopeService::class), Mockery::mock(CourseOfferingClosureWorkflowService::class),$governance);
    }

    private function seedApplicableOffering(int $id,int $minimum,string $status): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>$id,'academic_year_id'=>1,'semester_id'=>1,'status'=>$status]);
        DB::table('semester_offering_requests')->insert(['semester_offering_request_id'=>$id,'course_offering_id'=>$id,'program_course_id'=>$id,'is_selected'=>1,'minimum_enrollment'=>$minimum,'status'=>'approved','materialized_at'=>'2026-09-01 00:00:00']);
    }

    private function replacementDeadline(int $eventId): CourseRegistrationDeadlineResult
    {
        return new CourseRegistrationDeadlineResult(CourseRegistrationPhase::STUDENT_OPEN,1,1,CarbonImmutable::parse('2026-09-04T00:00:00Z'),
            CarbonImmutable::parse('2026-09-01T00:00:00Z'),CarbonImmutable::parse('2026-09-05T00:00:00Z'),CarbonImmutable::parse('2026-09-08T00:00:00Z'),$eventId,$eventId*10);
    }

    private function replacementService(CourseRegistrationDeadlineResult $deadline): RegistrationReplacementService
    {
        $registration=Mockery::mock(RegistrationService::class);$registration->shouldReceive('courseRegistrationReplacementDeadlines')->andReturn($deadline);
        return new RegistrationReplacementService($registration,Mockery::mock(AcademicRequirementService::class),Mockery::mock(CourseOfferingScheduleService::class),
            Mockery::mock(SemesterOfferingGovernanceService::class),Mockery::mock(DataScopeService::class));
    }

    private function actor(): User
    {
        $actor=new User();$actor->setAttribute('user_id',99);return $actor;
    }

    private function roleActor(int $id, string $roleMethod, string $permission): User
    {
        $actor=Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('user_id',$id);
        $actor->shouldReceive($roleMethod)->zeroOrMoreTimes()->andReturnTrue();
        $actor->shouldReceive('effectivePermissions')->zeroOrMoreTimes()->andReturn(collect([$permission]));
        return $actor;
    }

    private function realRegistrationService(): RegistrationService
    {
        return new RegistrationService(
            Mockery::mock(AcademicRequirementService::class),
            Mockery::mock(AcademicCalendarPolicyService::class),
            Mockery::mock(GradeService::class),
            Mockery::mock(CourseOfferingScheduleService::class),
        );
    }

    private function registrationStatus(int $registrationId): string
    {
        return (string) DB::table('student_course_registrations as scr')->join('registration_statuses as rs','rs.registration_status_id','=','scr.registration_status_id')->where('scr.student_course_registration_id',$registrationId)->value('rs.status_code');
    }

    private function schema(): void
    {
        Schema::create('users',function(Blueprint $t):void{$t->increments('user_id');$t->string('username')->nullable();$t->timestamps();});
        Schema::create('colleges',function(Blueprint $t):void{$t->increments('college_id');$t->string('college_name')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('departments',function(Blueprint $t):void{$t->increments('department_id');$t->integer('college_id')->nullable();$t->string('department_name')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('academic_programs',function(Blueprint $t):void{$t->increments('academic_program_id');$t->integer('department_id')->nullable();$t->string('program_name')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('academic_years',function(Blueprint $t):void{$t->increments('academic_year_id');$t->string('year_name')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('semesters',function(Blueprint $t):void{$t->increments('semester_id');$t->string('semester_code')->nullable();$t->string('semester_name')->nullable();$t->integer('semester_order')->default(1);$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('students',function(Blueprint $t):void{$t->increments('student_id');$t->integer('academic_program_id')->nullable();$t->softDeletes();$t->timestamps();});
        Schema::create('courses',function(Blueprint $t):void{$t->increments('course_id');$t->string('course_code')->nullable();$t->string('course_name')->nullable();$t->integer('credit_hours')->default(0);$t->timestamps();});
        Schema::create('course_prerequisites',function(Blueprint $t):void{$t->increments('course_prerequisite_id');$t->integer('course_id');$t->integer('prerequisite_course_id');$t->integer('minimum_result_status_id')->nullable();});
        Schema::create('academic_calendar_event_types',function(Blueprint $t):void{$t->increments('academic_calendar_event_type_id');$t->string('event_type_code');$t->boolean('is_active');});
        Schema::create('academic_calendar_events',function(Blueprint $t):void{$t->increments('academic_calendar_event_id');$t->integer('academic_calendar_event_type_id');$t->integer('academic_year_id');$t->integer('semester_id')->nullable();$t->dateTime('cancelled_at')->nullable();});
        Schema::create('course_offerings',function(Blueprint $t):void{$t->increments('course_offering_id');$t->integer('course_id')->nullable();$t->integer('academic_program_id')->nullable();$t->integer('department_id')->nullable();$t->integer('academic_year_id');$t->integer('semester_id');$t->integer('capacity')->nullable();$t->integer('available_seats')->nullable();$t->string('status');$t->timestamps();});
        Schema::create('semester_offering_requests',function(Blueprint $t):void{$t->increments('semester_offering_request_id');$t->integer('course_offering_id');$t->integer('program_course_id');$t->boolean('is_selected');$t->integer('minimum_enrollment')->nullable();$t->string('status');$t->dateTime('materialized_at')->nullable();});
        Schema::create('registration_statuses',function(Blueprint $t):void{$t->increments('registration_status_id');$t->string('status_code');});
        Schema::create('student_course_registrations',function(Blueprint $t):void{$t->increments('student_course_registration_id');$t->integer('student_id');$t->integer('course_offering_id');$t->date('registration_date')->nullable();$t->integer('registered_by_user_id')->nullable();$t->integer('advisor_user_id')->nullable();$t->integer('registration_status_id');$t->integer('result_status_id')->nullable();$t->text('notes')->nullable();$t->timestamps();});
        Schema::create('course_offering_minimum_enrollment_reviews',function(Blueprint $t):void{$t->increments('course_offering_minimum_enrollment_review_id');$t->integer('semester_offering_request_id');$t->integer('course_offering_id');$t->integer('academic_year_id');$t->integer('semester_id');$t->integer('minimum_enrollment_snapshot');$t->integer('enrolled_count_snapshot');$t->dateTime('finalization_deadline_at');$t->dateTime('finalized_at');$t->string('status');$t->string('dean_recommendation')->nullable();$t->integer('dean_user_id')->nullable();$t->text('dean_notes')->nullable();$t->dateTime('dean_recommended_at')->nullable();$t->string('scientific_decision')->nullable();$t->integer('scientific_user_id')->nullable();$t->text('scientific_notes')->nullable();$t->dateTime('scientific_decided_at')->nullable();$t->integer('course_offering_closure_request_id')->nullable();$t->dateTime('continued_at')->nullable();$t->dateTime('cancelled_at')->nullable();$t->dateTime('superseded_at')->nullable();$t->integer('affected_registration_count')->nullable();$t->timestamps();});
        Schema::create('course_offering_minimum_enrollment_events',function(Blueprint $t):void{$t->increments('course_offering_minimum_enrollment_event_id');$t->integer('course_offering_minimum_enrollment_review_id');$t->string('event_type');$t->integer('actor_user_id')->nullable();$t->text('notes')->nullable();$t->timestamp('created_at')->nullable();});
        Schema::create('course_offering_closure_requests',function(Blueprint $t):void{$t->increments('course_offering_closure_request_id');$t->integer('course_offering_id')->nullable();$t->integer('requested_by_user_id')->nullable();$t->text('request_reason')->nullable();$t->string('status');$t->integer('submission_version')->default(1);$t->tinyInteger('current_slot')->nullable();$t->integer('course_id_snapshot')->nullable();$t->integer('academic_program_id_snapshot')->nullable();$t->integer('academic_year_id_snapshot')->nullable();$t->integer('semester_id_snapshot')->nullable();$t->integer('department_id_snapshot')->nullable();$t->dateTime('submitted_at')->nullable();$t->dateTime('approved_at')->nullable();$t->dateTime('materialized_at')->nullable();$t->dateTime('superseded_at')->nullable();$t->integer('superseded_by_request_id')->nullable();$t->string('supersede_reason')->nullable();$t->timestamps();});
        Schema::create('course_offering_closure_reviews',function(Blueprint $t):void{$t->increments('course_offering_closure_review_id');$t->integer('course_offering_closure_request_id');$t->integer('submission_version');$t->string('review_authority');$t->string('status');$t->integer('reviewed_by_user_id')->nullable();$t->dateTime('reviewed_at')->nullable();$t->text('reason')->nullable();$t->timestamps();});
        Schema::create('course_offering_closure_events',function(Blueprint $t):void{$t->increments('course_offering_closure_event_id');$t->integer('course_offering_closure_request_id');$t->string('event_type');$t->integer('actor_user_id')->nullable();$t->integer('submission_version');$t->text('notes')->nullable();$t->dateTime('created_at')->nullable();});
        Schema::create('supplementary_exam_materializations',function(Blueprint $t):void{$t->increments('supplementary_exam_materialization_id');$t->integer('student_course_registration_id');});
        Schema::create('supplementary_exam_periods',function(Blueprint $t):void{$t->increments('supplementary_exam_period_id');$t->string('status');});
        Schema::create('supplementary_exam_offerings',function(Blueprint $t):void{$t->increments('supplementary_exam_offering_id');$t->integer('supplementary_exam_period_id');});
        Schema::create('supplementary_exam_registrations',function(Blueprint $t):void{$t->increments('supplementary_exam_registration_id');$t->integer('supplementary_exam_offering_id');$t->integer('student_course_registration_id');$t->string('status');$t->tinyInteger('current_slot')->nullable();});
        Schema::create('student_registration_replacement_requests',function(Blueprint $t):void{$t->increments('student_registration_replacement_request_id');$t->integer('academic_calendar_event_id')->nullable();$t->integer('student_id')->nullable();$t->integer('academic_year_id')->nullable();$t->integer('semester_id')->nullable();$t->string('status')->nullable();$t->integer('submission_version')->default(0);$t->tinyInteger('current_slot')->nullable();$t->text('student_notes')->nullable();$t->integer('advisor_user_id')->nullable();$t->text('advisor_notes')->nullable();$t->dateTime('first_submitted_at')->nullable();$t->dateTime('last_submitted_at')->nullable();$t->dateTime('reviewed_at')->nullable();$t->dateTime('approved_at')->nullable();$t->dateTime('expired_at')->nullable();$t->dateTime('superseded_at')->nullable();$t->dateTime('materialized_at')->nullable();$t->integer('registered_hours_before_approval')->nullable();$t->integer('replacement_hours_at_approval')->nullable();$t->integer('projected_hours_at_approval')->nullable();$t->integer('max_allowed_hours_at_approval')->nullable();$t->integer('remaining_hours_after_approval')->nullable();$t->timestamps();});
        Schema::create('student_registration_replacement_items',function(Blueprint $t):void{$t->increments('student_registration_replacement_item_id');$t->integer('student_registration_replacement_request_id');$t->integer('source_minimum_enrollment_review_id');$t->integer('source_student_course_registration_id');$t->integer('replacement_course_offering_id');$t->integer('materialized_student_course_registration_id')->nullable();$t->tinyInteger('source_consumed_slot')->nullable();$t->unique(['source_student_course_registration_id','source_consumed_slot'],'uq_srrpi_source_consumed');$t->unique(['student_registration_replacement_request_id','source_student_course_registration_id']);$t->unique(['student_registration_replacement_request_id','replacement_course_offering_id']);});
        Schema::create('student_registration_replacement_events',function(Blueprint $t):void{$t->increments('student_registration_replacement_event_id');$t->integer('student_registration_replacement_request_id');$t->string('event_type');$t->integer('actor_user_id')->nullable();$t->string('from_status')->nullable();$t->string('to_status')->nullable();$t->integer('submission_version')->nullable();$t->text('notes')->nullable();$t->dateTime('created_at')->nullable();});
    }
}
