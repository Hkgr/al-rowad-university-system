<?php

namespace Tests\Feature;

use App\Exceptions\CourseOfferingScheduleException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingScheduleSlot;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicCalendarPolicyService;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\CourseOfferingScheduleService;
use App\Services\DataScopeService;
use App\Services\TeachingAssignmentService;
use App\Support\SemesterOfferingGovernance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SemesterRegistrationTimetablePhase4BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $this->seedContext();
    }

    public function test_zero_hour_course_is_readable_but_never_vacuously_complete_or_registration_eligible(): void
    {
        $service = $this->service();
        $offering = CourseOffering::query()->with('course')->findOrFail(1);

        $description = $service->describe($offering);
        self::assertTrue($description['schema_ready']);
        self::assertFalse($description['components_defined']);
        self::assertFalse($description['complete']);
        self::assertSame([], $description['required_components']);
        self::assertSame([], $description['missing_components']);

        $evaluation = $service->registrationEvaluations(
            new Student(['student_id' => 1]),
            collect([$offering]),
            [],
            [],
        )[1];
        self::assertSame(CourseOfferingScheduleException::INCOMPLETE, $evaluation['reason']);
        self::assertFalse($evaluation['schedule']['components_defined']);
    }

    public function test_theoretical_only_course_rejects_practical_slot(): void
    {
        $this->expectExceptionObject(CourseOfferingScheduleException::invalidComponent('practical'));

        $this->service()->replace($this->dean(), CourseOffering::findOrFail(2), [
            $this->slot('practical'),
        ]);
    }

    public function test_practical_only_course_rejects_theoretical_slot(): void
    {
        $this->expectExceptionObject(CourseOfferingScheduleException::invalidComponent('theoretical'));

        $this->service()->replace($this->dean(), CourseOffering::findOrFail(3), [
            $this->slot('theoretical'),
        ]);
    }

    public function test_mixed_course_accepts_both_required_components_and_requires_both_for_completeness(): void
    {
        $service = $this->service();
        $offering = CourseOffering::findOrFail(4);

        try {
            $service->replace($this->dean(), $offering, [$this->slot('theoretical')]);
            self::fail('A mixed course must reject a schedule without its practical component.');
        } catch (CourseOfferingScheduleException $exception) {
            self::assertSame(CourseOfferingScheduleException::INCOMPLETE, $exception->errorCode);
            self::assertSame(['practical'], $exception->data['missing_schedule_components']);
        }

        $description = $service->replace($this->dean(), $offering, [
            $this->slot('theoretical', 1, '08:00', '09:00'),
            $this->slot('practical', 2, '10:00', '12:00'),
        ]);
        self::assertTrue($description['complete']);
        self::assertSame(['theoretical', 'practical'], $description['required_components']);
        self::assertCount(2, $description['slots']);
    }

    public function test_incompatible_persisted_component_fails_closed_and_does_not_create_a_conflict(): void
    {
        DB::table('course_offering_schedule_slots')->insert([
            $this->storedSlot(2, 'theoretical', 1, '08:00:00', '09:00:00'),
            $this->storedSlot(2, 'practical', 1, '10:00:00', '11:00:00'),
            $this->storedSlot(5, 'theoretical', 1, '10:00:00', '11:00:00'),
        ]);
        $service = $this->service();
        $target = CourseOffering::query()->with('course')->findOrFail(2);

        $description = $service->describe($target);
        self::assertFalse($description['complete']);
        self::assertSame(['practical'], $description['invalid_components']);

        $evaluation = $service->registrationEvaluations(
            new Student(['student_id' => 1]),
            collect([$target]),
            [5],
            [],
        )[2];
        self::assertSame(CourseOfferingScheduleException::INCOMPLETE, $evaluation['reason']);
        self::assertSame([], $evaluation['conflicts']);
    }

    public function test_conflicts_use_half_open_intervals_in_the_same_actual_term(): void
    {
        DB::table('course_offering_schedule_slots')->insert([
            $this->storedSlot(2, 'theoretical', 1, '08:00:00', '09:00:00'),
            $this->storedSlot(5, 'theoretical', 1, '09:00:00', '10:00:00'),
        ]);
        $service = $this->service();
        $target = CourseOffering::query()->with('course')->findOrFail(2);
        $student = new Student(['student_id' => 1]);

        $touching = $service->registrationEvaluations($student, collect([$target]), [5], [])[2];
        self::assertNull($touching['reason']);
        self::assertSame([], $touching['conflicts']);

        DB::table('course_offering_schedule_slots')->where('course_offering_id', 5)->update(['start_time' => '08:30:00']);
        $overlap = $service->registrationEvaluations($student, collect([$target]), [5], [])[2];
        self::assertSame(CourseOfferingScheduleException::CONFLICT, $overlap['reason']);
        self::assertCount(1, $overlap['conflicts']);
        self::assertSame(5, $overlap['conflicts'][0]['conflicting_with']['course_offering_id']);
    }

    public function test_missing_schema_is_controlled_for_reads_and_registration(): void
    {
        Schema::drop('course_offering_schedule_slots');
        $service = $this->service();
        $offering = CourseOffering::query()->with('course')->findOrFail(2);

        self::assertFalse($service->describe($offering)['schema_ready']);
        $evaluation = $service->registrationEvaluations(new Student(['student_id' => 1]), collect([$offering]), [], [])[2];
        self::assertSame(CourseOfferingScheduleException::SCHEMA_NOT_READY, $evaluation['reason']);

        $this->expectExceptionObject(CourseOfferingScheduleException::schemaNotReady());
        $service->replace($this->dean(), $offering, [$this->slot('theoretical')]);
    }

    public function test_registration_start_locks_replacement_without_changing_existing_slots(): void
    {
        DB::table('course_offering_schedule_slots')->insert($this->storedSlot(2, 'theoretical', 1, '08:00:00', '09:00:00'));
        $service = $this->service(registrationStarted: true);

        try {
            $service->replace($this->dean(), CourseOffering::findOrFail(2), [
                $this->slot('theoretical', 2, '10:00', '11:00'),
            ]);
            self::fail('A timetable relied on by a started registration window must be locked.');
        } catch (CourseOfferingScheduleException $exception) {
            self::assertSame(CourseOfferingScheduleException::LOCKED, $exception->errorCode);
            self::assertSame(CourseOfferingScheduleService::LOCK_REGISTRATION_STARTED, $exception->data['locked_reason']);
        }

        self::assertSame(1, DB::table('course_offering_schedule_slots')->where('course_offering_id', 2)->value('day_of_week'));
    }

    public function test_historical_year_wide_published_registration_window_permanently_marks_each_semester_started(): void
    {
        DB::table('academic_calendar_event_types')->insert([
            'academic_calendar_event_type_id' => 1,
            'event_type_code' => 'course_registration',
            'is_active' => 1,
        ]);
        DB::table('academic_calendar_events')->insert([
            'academic_calendar_event_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => null,
            'academic_calendar_event_type_id' => 1,
            'cancelled_at' => null,
        ]);
        DB::table('academic_calendar_event_versions')->insert([
            'academic_calendar_event_version_id' => 1,
            'academic_calendar_event_id' => 1,
            'starts_at' => '2026-08-25 00:00:00',
            'ends_at' => '2026-08-31 23:59:59',
            'student_registration_ends_at' => '2026-08-30 23:59:59',
            'advisor_approval_ends_at' => '2026-08-31 23:59:59',
            'is_enforcement' => 1,
            'publication_status' => 'superseded',
            'published_at' => '2026-08-24 00:00:00',
            'superseded_at' => '2026-08-28 00:00:00',
        ]);

        self::assertTrue((new AcademicCalendarPolicyService())->courseRegistrationHasEverStarted(
            1,
            1,
            \Carbon\CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        ));
    }

    public function test_batch_description_query_count_does_not_grow_with_visible_offerings(): void
    {
        $service = $this->service();
        $one = CourseOffering::query()->with('course')->whereKey(1)->get();
        $five = CourseOffering::query()->with('course')->orderBy('course_offering_id')->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->describeMany($one);
        $oneCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->describeMany($five);
        $fiveCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame($oneCount, $fiveCount);
        self::assertLessThanOrEqual(4, $fiveCount);
    }

    public function test_dean_role_and_permission_without_actual_program_scope_cannot_write(): void
    {
        try {
            $this->service(scopeAccess: false)->replace(
                $this->dean(),
                CourseOffering::findOrFail(2),
                [$this->slot('theoretical')],
            );
            self::fail('A Dean without actual DataScope must not mutate a timetable.');
        } catch (ModelNotFoundException) {
            self::assertSame(0, CourseOfferingScheduleSlot::query()->count());
        }
    }

    private function service(bool $registrationStarted = false, bool $scopeAccess = true): CourseOfferingScheduleService
    {
        $teaching = Mockery::mock(TeachingAssignmentService::class);
        $teaching->shouldReceive('accessibleCollegeIdList')->andReturn([1])->byDefault();
        $coverage = new CourseOfferingInstructorCoverageService($teaching);

        $calendar = Mockery::mock(AcademicCalendarPolicyService::class);
        $calendar->shouldReceive('courseRegistrationHasEverStarted')->andReturn($registrationStarted)->byDefault();
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canMutateProgram')->andReturn($scopeAccess)->byDefault();
        $scope->shouldReceive('canAccessOffering')->andReturnTrue()->byDefault();

        return new CourseOfferingScheduleService($coverage, $calendar, $scope, $teaching);
    }

    private function dean(): User
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setRawAttributes(['user_id' => 1, 'username' => 'dean']);
        $actor->exists = true;
        $actor->shouldReceive('isDean')->andReturnTrue();
        $actor->shouldReceive('effectivePermissions')->andReturn(collect([SemesterOfferingGovernance::PERMISSION_MANAGE]));

        return $actor;
    }

    private function slot(string $component, int $day = 1, string $start = '08:00', string $end = '09:00'): array
    {
        return [
            'component_type' => $component,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'location_label' => null,
        ];
    }

    private function storedSlot(int $offeringId, string $component, int $day, string $start, string $end): array
    {
        return [
            'course_offering_id' => $offeringId,
            'component_type' => $component,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'location_label' => null,
            'created_by_user_id' => 1,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ];
    }

    private function seedContext(): void
    {
        DB::table('colleges')->insert(['college_id' => 1, 'college_name' => 'Medicine']);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1, 'department_name' => 'General']);
        DB::table('academic_programs')->insert(['academic_program_id' => 1, 'department_id' => 1, 'program_name' => 'Medicine']);
        DB::table('users')->insert(['user_id' => 1, 'username' => 'dean']);

        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'ZERO', 'course_name' => 'Undefined', 'credit_hours' => 0, 'theoretical_hours' => 0, 'practical_hours' => 0],
            ['course_id' => 2, 'course_code' => 'THEORY', 'course_name' => 'Theory', 'credit_hours' => 2, 'theoretical_hours' => 2, 'practical_hours' => 0],
            ['course_id' => 3, 'course_code' => 'PRACT', 'course_name' => 'Practical', 'credit_hours' => 1, 'theoretical_hours' => 0, 'practical_hours' => 2],
            ['course_id' => 4, 'course_code' => 'MIXED', 'course_name' => 'Mixed', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 2],
            ['course_id' => 5, 'course_code' => 'OTHER', 'course_name' => 'Other', 'credit_hours' => 2, 'theoretical_hours' => 2, 'practical_hours' => 0],
        ]);
        foreach (range(1, 5) as $id) {
            DB::table('course_offerings')->insert([
                'course_offering_id' => $id,
                'course_id' => $id,
                'academic_year_id' => 1,
                'semester_id' => 1,
                'department_id' => 1,
                'academic_program_id' => 1,
                'status' => 'open',
            ]);
        }
    }

    private function schema(): void
    {
        Schema::create('colleges', function (Blueprint $table): void {
            $table->increments('college_id');
            $table->string('college_name')->nullable();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id');
            $table->string('department_name')->nullable();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->integer('department_id');
            $table->string('program_name')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('username')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code')->nullable();
            $table->string('course_name')->nullable();
            $table->integer('credit_hours')->default(0);
            $table->integer('theoretical_hours')->default(0);
            $table->integer('practical_hours')->default(0);
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('department_id')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->integer('faculty_member_id')->nullable();
            $table->integer('capacity')->default(0);
            $table->integer('available_seats')->default(0);
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('course_offering_schedule_slots', function (Blueprint $table): void {
            $table->increments('course_offering_schedule_slot_id');
            $table->integer('course_offering_id');
            $table->string('component_type', 16);
            $table->tinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location_label', 150)->nullable();
            $table->integer('created_by_user_id');
            $table->timestamps();
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->increments('student_course_registration_id');
            $table->integer('student_id');
            $table->integer('course_offering_id');
            $table->integer('registration_status_id')->nullable();
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status')->default('draft');
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('expired_at')->nullable();
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
        });
        Schema::create('academic_calendar_event_types', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_type_id');
            $table->string('event_type_code');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('academic_calendar_events', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id')->nullable();
            $table->integer('academic_calendar_event_type_id');
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::create('academic_calendar_event_versions', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_version_id');
            $table->integer('academic_calendar_event_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('student_registration_ends_at')->nullable();
            $table->dateTime('advisor_approval_ends_at')->nullable();
            $table->boolean('is_enforcement')->default(false);
            $table->string('publication_status');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
        });
    }
}
