<?php

namespace Tests\Feature;

use App\Exceptions\RegistrationException;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicRequirementService;
use App\Services\AcademicTermResolver;
use App\Services\DataScopeService;
use App\Services\RegistrationRequestService;
use App\Services\RegistrationService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AcademicCalendarPhase4RegistrationRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->integer('submission_version')->default(0);
            $table->text('student_notes')->nullable();
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->integer('current_academic_level_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('academic_year_name')->nullable();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_name')->nullable();
            $table->integer('semester_order')->default(1);
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
            $table->integer('student_course_registration_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_request_events', function (Blueprint $table): void {
            $table->increments('student_registration_request_event_id');
            $table->integer('student_registration_request_id');
            $table->string('event_type');
            $table->integer('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('submission_version')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_workspace_stays_readable_but_registration_open_is_false_when_calendar_is_closed(): void
    {
        [$year, $semester, $student] = $this->contextModels();
        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->once()->andReturn(collect([$semester]));
        $registration->shouldReceive('courseRegistrationWindow')->once()->with(1, 1)->andReturn($this->closedResult());
        $registration->shouldReceive('hoursSnapshot')->once()->andReturn([
            'registered_hours' => 0,
            'max_allowed_hours' => 18,
            'remaining_hours' => 18,
        ]);
        $registration->shouldReceive('currentOfferingIds')->once()->andReturn([]);
        $registration->shouldReceive('getRegistrationSummary')->once()->andReturn([]);
        $registration->shouldNotReceive('getSelfRegistrationOfferings');

        $workspace = $this->requestService($registration, $year)->studentWorkspace($student, 1);

        self::assertFalse($workspace['registration_open']);
        self::assertSame([], $workspace['available_courses']->all());
        self::assertSame(1, (int) $workspace['semester']->semester_id);
    }

    public function test_add_notes_and_submit_are_rejected_before_creating_or_mutating_a_request(): void
    {
        [$year, $semester, $student] = $this->contextModels();
        $actor = new User(['user_id' => 7]);
        $actor->setAttribute('user_id', 7);
        $offering = new CourseOffering([
            'course_offering_id' => 1,
            'course_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_program_id' => null,
            'available_seats' => 2,
            'status' => 'open',
        ]);
        $offering->setAttribute('course_offering_id', 1);
        $offering->setRelation('course', new Course(['course_id' => 1, 'credit_hours' => 3]));

        $addRegistration = $this->closedPreparationRegistration($semester);
        $addRegistration->shouldReceive('assertSelfRegistrationAllowed')->once();
        $this->expectClosed(fn () => $this->requestService($addRegistration, $year)->addItem($student, $offering, $actor));

        $notesRegistration = $this->closedPreparationRegistration($semester);
        $this->expectClosed(fn () => $this->requestService($notesRegistration, $year)->updateNotes($student, 'note', $actor, 1));

        $submitRegistration = $this->closedPreparationRegistration($semester);
        $this->expectClosed(fn () => $this->requestService($submitRegistration, $year)->submit($student, $actor, 1));

        self::assertSame(0, DB::table('student_registration_requests')->count());
    }

    public function test_workspace_prefers_the_calendar_open_semester_but_keeps_a_closed_request_semester_selectable(): void
    {
        [$year, $semesterOne, $student] = $this->contextModels();
        $semesterTwo = $this->semesterModel(2, 2);
        DB::table('students')->insert(['student_id' => 1]);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'academic_year_name' => '2026-2027']);
        DB::table('semesters')->insert([
            ['semester_id' => 1, 'semester_name' => 'First', 'semester_order' => 1],
            ['semester_id' => 2, 'semester_name' => 'Second', 'semester_order' => 2],
        ]);
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'draft',
            'submission_version' => 0,
        ]);

        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->twice()->andReturn(collect([$semesterOne, $semesterTwo]));
        $registration->shouldReceive('courseRegistrationWindow')->twice()->with(1, 1)->andReturn($this->closedResult());
        $registration->shouldReceive('courseRegistrationWindow')->twice()->with(1, 2)->andReturn($this->openResult(2));
        $registration->shouldReceive('hoursSnapshot')->zeroOrMoreTimes()->andReturn([
            'registered_hours' => 0,
            'max_allowed_hours' => 18,
            'remaining_hours' => 18,
        ]);
        $registration->shouldReceive('currentOfferingIds')->zeroOrMoreTimes()->andReturn([]);
        $registration->shouldReceive('getSelfRegistrationOfferings')->once()->with($student, 1, 2, 0, [])->andReturn(collect());
        $registration->shouldReceive('getRegistrationSummary')->twice()->andReturn([]);
        $service = $this->requestService($registration, $year);

        $liveWorkspace = $service->studentWorkspace($student);
        self::assertSame(2, (int) $liveWorkspace['semester']->semester_id);
        self::assertTrue($liveWorkspace['registration_open']);

        $closedWorkspace = $service->studentWorkspace($student, 1);
        self::assertSame(1, (int) $closedWorkspace['semester']->semester_id);
        self::assertFalse($closedWorkspace['registration_open']);
        self::assertSame([1, 2], $closedWorkspace['semesters']->pluck('semester_id')->map(fn ($id): int => (int) $id)->all());
        self::assertNotNull($closedWorkspace['request']);
    }

    private function requestService(RegistrationService $registration, AcademicYear $year): RegistrationRequestService
    {
        $terms = Mockery::mock(AcademicTermResolver::class);
        $terms->shouldReceive('uniqueCurrentAcademicYear')->zeroOrMoreTimes()->andReturn($year);

        return new RegistrationRequestService(
            $registration,
            $terms,
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
    }

    private function closedPreparationRegistration(Semester $semester): RegistrationService
    {
        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->once()->andReturn(collect([$semester]));
        $registration->shouldReceive('assertCourseRegistrationWindowOpen')
            ->once()
            ->with(1, 1)
            ->andThrow(RegistrationException::courseRegistrationWindowClosed());

        return $registration;
    }

    private function expectClosed(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the request preparation calendar gate to close.');
        } catch (RegistrationException $exception) {
            self::assertSame(RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED, $exception->errorCode);
        }
    }

    private function contextModels(): array
    {
        $year = new AcademicYear;
        $year->setAttribute('academic_year_id', 1);
        $semester = $this->semesterModel(1, 1);
        $student = new Student;
        $student->setAttribute('student_id', 1);
        $student->setAttribute('academic_program_id', null);

        return [$year, $semester, $student];
    }

    private function semesterModel(int $semesterId, int $order): Semester
    {
        $semester = new Semester;
        $semester->setAttribute('semester_id', $semesterId);
        $semester->setAttribute('semester_order', $order);

        return $semester;
    }

    private function closedResult(): AcademicCalendarPolicyResult
    {
        return new AcademicCalendarPolicyResult(
            AcademicCalendarPolicyStatus::CLOSED,
            'course_registration',
            1,
            1,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
            0,
            'no_effective_window',
        );
    }

    private function openResult(int $semesterId): AcademicCalendarPolicyResult
    {
        return new AcademicCalendarPolicyResult(
            AcademicCalendarPolicyStatus::OPEN,
            'course_registration',
            1,
            $semesterId,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
            1,
            'effective_window_found',
        );
    }
}
