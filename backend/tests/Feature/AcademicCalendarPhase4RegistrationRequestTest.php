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
        $semester = new Semester;
        $semester->setAttribute('semester_id', 1);
        $semester->setAttribute('semester_order', 1);
        $student = new Student;
        $student->setAttribute('student_id', 1);
        $student->setAttribute('academic_program_id', null);

        return [$year, $semester, $student];
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
}
