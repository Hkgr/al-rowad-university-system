<?php

namespace Tests\Feature;

use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicCalendarPolicyService;
use App\Services\CourseOfferingClosureWorkflowService;
use App\Services\DataScopeService;
use App\Services\MinimumEnrollmentCancellationMaterializer;
use App\Services\MinimumEnrollmentReviewService;
use App\Services\RegistrationService;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
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

    private function minimumService(): MinimumEnrollmentReviewService
    {
        $calendar=Mockery::mock(AcademicCalendarPolicyService::class);
        $calendar->shouldReceive('courseRegistrationDeadlines')->once()->andReturn(new CourseRegistrationDeadlineResult(CourseRegistrationPhase::CLOSED,1,1,CarbonImmutable::parse('2026-09-09T00:00:00Z'),CarbonImmutable::parse('2026-09-01T00:00:00Z'),CarbonImmutable::parse('2026-09-05T00:00:00Z'),CarbonImmutable::parse('2026-09-08T00:00:00Z')));
        return new MinimumEnrollmentReviewService($calendar, Mockery::mock(DataScopeService::class), Mockery::mock(CourseOfferingClosureWorkflowService::class));
    }

    private function actor(): User
    {
        $actor=new User();$actor->setAttribute('user_id',99);return $actor;
    }

    private function registrationStatus(int $registrationId): string
    {
        return (string) DB::table('student_course_registrations as scr')->join('registration_statuses as rs','rs.registration_status_id','=','scr.registration_status_id')->where('scr.student_course_registration_id',$registrationId)->value('rs.status_code');
    }

    private function schema(): void
    {
        Schema::create('course_offerings',function(Blueprint $t):void{$t->increments('course_offering_id');$t->integer('academic_year_id');$t->integer('semester_id');$t->string('status');});
        Schema::create('semester_offering_requests',function(Blueprint $t):void{$t->increments('semester_offering_request_id');$t->integer('course_offering_id');$t->integer('program_course_id');$t->boolean('is_selected');$t->integer('minimum_enrollment')->nullable();$t->string('status');$t->dateTime('materialized_at')->nullable();});
        Schema::create('registration_statuses',function(Blueprint $t):void{$t->increments('registration_status_id');$t->string('status_code');});
        Schema::create('student_course_registrations',function(Blueprint $t):void{$t->increments('student_course_registration_id');$t->integer('student_id');$t->integer('course_offering_id');$t->integer('registration_status_id');});
        Schema::create('course_offering_minimum_enrollment_reviews',function(Blueprint $t):void{$t->increments('course_offering_minimum_enrollment_review_id');$t->integer('semester_offering_request_id');$t->integer('course_offering_id');$t->integer('academic_year_id');$t->integer('semester_id');$t->integer('minimum_enrollment_snapshot');$t->integer('enrolled_count_snapshot');$t->dateTime('finalization_deadline_at');$t->dateTime('finalized_at');$t->string('status');$t->string('dean_recommendation')->nullable();$t->integer('dean_user_id')->nullable();$t->text('dean_notes')->nullable();$t->dateTime('dean_recommended_at')->nullable();$t->string('scientific_decision')->nullable();$t->integer('scientific_user_id')->nullable();$t->text('scientific_notes')->nullable();$t->dateTime('scientific_decided_at')->nullable();$t->integer('course_offering_closure_request_id')->nullable();$t->dateTime('continued_at')->nullable();$t->dateTime('cancelled_at')->nullable();$t->dateTime('superseded_at')->nullable();$t->integer('affected_registration_count')->nullable();$t->timestamps();});
        Schema::create('course_offering_minimum_enrollment_events',function(Blueprint $t):void{$t->increments('course_offering_minimum_enrollment_event_id');$t->integer('course_offering_minimum_enrollment_review_id');$t->string('event_type');$t->integer('actor_user_id')->nullable();$t->text('notes')->nullable();$t->timestamp('created_at')->nullable();});
        Schema::create('student_registration_replacement_requests',function(Blueprint $t):void{$t->increments('student_registration_replacement_request_id');});
        Schema::create('student_registration_replacement_items',function(Blueprint $t):void{$t->increments('student_registration_replacement_item_id');$t->integer('student_registration_replacement_request_id');$t->integer('source_minimum_enrollment_review_id');$t->integer('source_student_course_registration_id');$t->integer('replacement_course_offering_id');$t->integer('materialized_student_course_registration_id')->nullable();$t->tinyInteger('source_consumed_slot')->nullable();$t->unique(['source_student_course_registration_id','source_consumed_slot'],'uq_srrpi_source_consumed');$t->unique(['student_registration_replacement_request_id','source_student_course_registration_id']);$t->unique(['student_registration_replacement_request_id','replacement_course_offering_id']);});
        Schema::create('student_registration_replacement_events',function(Blueprint $t):void{$t->increments('student_registration_replacement_event_id');});
    }
}
