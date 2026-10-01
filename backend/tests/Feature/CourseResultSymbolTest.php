<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\GradingPolicy;
use App\Models\RegistrationStatus;
use App\Models\ResultStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentStatus;
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\RegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseResultSymbolTest extends TestCase
{
    private Student $student;

    private CourseOffering $offering;

    private StudentStatus $studentStatus;

    private array $registrationStatuses;

    private GradingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();

        foreach (['registered', 'completed', 'dropped', 'withdrawn'] as $code) {
            $this->registrationStatuses[$code] = RegistrationStatus::query()->create([
                'status_code' => $code, 'status_name' => ucfirst($code), 'is_active' => true,
            ]);
        }
        foreach (['passed', 'failed', 'incomplete', 'deprived'] as $code) {
            ResultStatus::query()->create([
                'status_code' => $code, 'status_name' => ucfirst($code), 'is_active' => true,
            ]);
        }

        $this->policy = GradingPolicy::query()->create([
            'policy_name' => 'Test policy', 'theoretical_max_mark' => 60, 'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 15, 'minimum_practical_mark' => 10,
            'minimum_final_mark' => 50, 'absence_deprivation_percentage' => 15,
            'incomplete_expiry_status_code' => null, 'is_default' => true, 'is_active' => true,
        ]);
        $year = AcademicYear::query()->create(['year_name' => '2025/2026']);
        $semester = Semester::query()->create(['semester_code' => 'S1', 'semester_name' => 'First']);
        $course = Course::query()->create(['course_code' => 'SYM101', 'course_name' => 'Symbols', 'credit_hours' => 3]);
        $this->studentStatus = StudentStatus::query()->create([
            'status_code' => 'active', 'status_name' => 'Active', 'is_active' => true,
        ]);
        $this->student = Student::query()->create([
            'student_number' => 'SYM-001', 'first_name' => 'Test', 'last_name' => 'Student',
            'enrollment_date' => '2025-09-01', 'student_status_id' => $this->studentStatus->student_status_id,
        ]);
        $this->offering = CourseOffering::query()->create([
            'course_id' => $course->course_id, 'academic_year_id' => $year->academic_year_id,
            'semester_id' => $semester->semester_id, 'capacity' => 20, 'available_seats' => 20, 'status' => 'open',
        ]);
    }

    public function test_failing_mark_is_f_and_stays_inside_the_gpa_denominator(): void
    {
        $registration = $this->registration();
        $grades = app(GradeService::class)->createRegistrationGrades($registration->student_course_registration_id, [
            'theoretical_mark' => 10,
            'practical_mark' => 5,
        ]);

        $this->assertSame('F', $grades['letter_grade']);
        $this->assertSame('راسب', $grades['symbol_label']);
        $this->assertSame(0.0, $grades['grade_points']);

        $gpa = app(GradeService::class)->calculateGpa($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(3, $gpa['total_included_credit_hours']);
        $this->assertSame(0.0, $gpa['gpa']);
        $this->assertSame('F', $gpa['included_courses'][0]['letter_grade']);
        $this->assertStudentStatusUnchanged();
    }

    public function test_deprivation_is_z_included_in_gpa_and_grade_entry_is_rejected(): void
    {
        $registration = $this->registration();
        $absent = AttendanceStatus::query()->create([
            'status_code' => 'absent', 'status_name' => 'Absent', 'counts_as_absent' => true, 'is_active' => true,
        ]);
        $session = AttendanceSession::query()->create([
            'course_offering_id' => $this->offering->course_offering_id,
            'session_type' => 'theoretical', 'session_date' => '2025-10-01', 'created_by_user_id' => 1,
        ]);
        StudentAttendance::query()->create([
            'attendance_session_id' => $session->attendance_session_id,
            'student_id' => $this->student->student_id,
            'attendance_status_id' => $absent->attendance_status_id,
        ]);

        app(AttendanceService::class)->applyDeprivation($this->offering->course_offering_id, 1);

        $grades = app(GradeService::class)->getRegistrationGrades($registration->student_course_registration_id);
        $this->assertSame('Z', $grades['letter_grade']);
        $this->assertSame('محروم', $grades['symbol_label']);
        $this->assertSame('deprived', $grades['result_status']['status_code']);
        $this->assertStringContainsString('Deprived due to absence', $grades['notes']);

        try {
            app(GradeService::class)->updateRegistrationGrades($registration->student_course_registration_id, [
                'theoretical_mark' => 40,
                'practical_mark' => 30,
            ]);
            $this->fail('Grade update replaced a deprived result.');
        } catch (GradeException $exception) {
            $this->assertStringContainsString('Deprived', $exception->getMessage());
        }

        $gpa = app(GradeService::class)->calculateGpa($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(3, $gpa['total_included_credit_hours']);
        $this->assertSame(0.0, $gpa['included_courses'][0]['grade_points']);
        $this->assertSame('Z', $gpa['included_courses'][0]['letter_grade']);
        $this->assertStudentStatusUnchanged();
    }

    public function test_withdrawal_stays_out_of_gpa_and_remains_on_the_transcript(): void
    {
        $first = $this->registration();
        $secondOffering = $this->offering->replicate();
        $secondOffering->save();
        $second = $this->registration($secondOffering);

        app(RegistrationService::class)->withdrawRegistration($first);
        app(RegistrationService::class)->withdrawRegistration($second);

        $gpa = app(GradeService::class)->calculateGpa($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(0, $gpa['total_included_credit_hours']);
        $this->assertSame([], $gpa['included_courses']);

        $transcript = app(GradeService::class)->getTranscript($this->student);
        $this->assertCount(1, $transcript['terms']);
        $this->assertSame(
            ['W', 'W'],
            collect($transcript['terms'][0]['courses'])->pluck('letter_grade')->sort()->values()->all()
        );
        $this->assertSame('منسحب', $transcript['terms'][0]['courses'][0]['symbol_label']);
        $this->assertNull($transcript['terms'][0]['courses'][0]['grade_points']);
        $this->assertStudentStatusUnchanged();
    }

    public function test_unresolved_incomplete_is_excluded_and_is_not_shown_as_zero_or_f(): void
    {
        $registration = $this->registration();
        $grades = app(GradeService::class)->grantIncomplete($registration->student_course_registration_id, [
            'reason' => 'Medical report',
            'requirements' => 'Final exam',
            'deadline' => now()->addWeek()->toDateString(),
        ]);

        $this->assertSame('I', $grades['letter_grade']);
        $this->assertSame('غير مكتمل', $grades['symbol_label']);
        $this->assertNull($grades['grade_points']);
        $this->assertNull($grades['theoretical_mark']);
        $this->assertNull($grades['final_mark']);
        $this->assertNotSame('F', $grades['letter_grade']);
        $this->assertSame('Medical report', $grades['reason']);

        $stored = StudentCourseResult::query()->first();
        $this->assertNull($stored->theoretical_total);
        $this->assertNull($stored->practical_total);
        $this->assertNull($stored->final_mark);

        $gpa = app(GradeService::class)->calculateGpa($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(0, $gpa['total_included_credit_hours']);
        $this->assertSame('incomplete', $gpa['excluded_courses'][0]['exclusion_reason']);
        $this->assertStudentStatusUnchanged();
    }

    public function test_resolve_incomplete_stores_the_earned_letter_and_keeps_history(): void
    {
        $registration = $this->registration();
        $service = app(GradeService::class);
        $service->grantIncomplete($registration->student_course_registration_id, [
            'reason' => 'Medical report',
            'requirements' => 'Final exam',
            'deadline' => now()->addWeek()->toDateString(),
        ]);

        $grades = $service->resolveIncomplete($registration->student_course_registration_id, [
            'theoretical_mark' => 48,
            'practical_mark' => 32,
        ]);

        $this->assertSame('B', $grades['letter_grade']);
        $this->assertSame(3.0, $grades['grade_points']);
        $this->assertSame('Medical report', $grades['reason']);
        $this->assertSame('Final exam', $grades['requirements']);
        $this->assertNotNull($grades['granted_at']);
        $this->assertSame(now()->addWeek()->toDateString(), $grades['deadline']);
        $this->assertNotNull(StudentCourseResult::query()->first()->incomplete_resolved_at);
        $this->assertStudentStatusUnchanged();
    }

    public function test_expired_incomplete_follows_the_configured_status_and_ignores_a_null_setting(): void
    {
        $untouched = $this->grantPastIncomplete();
        $this->assertSame(0, app(GradeService::class)->expireIncompleteResults());
        $this->assertSame('I', app(GradeService::class)->getRegistrationGrades($untouched)['letter_grade']);

        $failed = $this->grantPastIncomplete();
        $this->policy->update(['incomplete_expiry_status_code' => 'failed']);
        app(GradeService::class)->expireIncompleteResults();
        $failedGrades = app(GradeService::class)->getRegistrationGrades($failed);
        $this->assertSame('F', $failedGrades['letter_grade']);
        $this->assertSame('راسب', $failedGrades['symbol_label']);

        $deprived = $this->grantPastIncomplete();
        $this->policy->update(['incomplete_expiry_status_code' => 'deprived']);
        app(GradeService::class)->expireIncompleteResults();
        $deprivedGrades = app(GradeService::class)->getRegistrationGrades($deprived);
        $this->assertSame('Z', $deprivedGrades['letter_grade']);
        $this->assertSame('محروم', $deprivedGrades['symbol_label']);
        $this->assertTrue((bool) StudentCourseResult::query()->where('student_course_registration_id', $deprived)->first()->is_deprived);
        $this->assertSame('F', app(GradeService::class)->getRegistrationGrades($failed)['letter_grade']);
        $this->assertStudentStatusUnchanged();
    }

    private function grantPastIncomplete(): int
    {
        $registration = $this->registration();
        app(GradeService::class)->grantIncomplete($registration->student_course_registration_id, [
            'reason' => 'Missing work',
            'requirements' => 'Assignment',
            'deadline' => now()->subDay()->toDateString(),
        ]);

        return $registration->student_course_registration_id;
    }

    private function registration(?CourseOffering $offering = null): StudentCourseRegistration
    {
        return StudentCourseRegistration::query()->create([
            'student_id' => $this->student->student_id,
            'course_offering_id' => ($offering ?? $this->offering)->course_offering_id,
            'registration_date' => '2025-09-01',
            'registered_by_user_id' => 1,
            'registration_status_id' => $this->registrationStatuses['registered']->registration_status_id,
        ]);
    }

    private function assertStudentStatusUnchanged(): void
    {
        $this->assertSame(
            $this->studentStatus->student_status_id,
            $this->student->fresh()->student_status_id
        );
    }

    private function createSchema(): void
    {
        Schema::create('registration_statuses', fn (Blueprint $t) => $this->statusTable($t, 'registration_status_id'));
        Schema::create('result_statuses', fn (Blueprint $t) => $this->statusTable($t, 'result_status_id'));
        Schema::create('grading_policies', function (Blueprint $t): void {
            $t->id('grading_policy_id');
            $t->string('policy_name');
            foreach (['theoretical_max_mark', 'practical_max_mark', 'minimum_theoretical_mark', 'minimum_practical_mark', 'minimum_final_mark', 'absence_deprivation_percentage'] as $column) {
                $t->decimal($column);
            }
            $t->string('incomplete_expiry_status_code')->nullable();
            $t->boolean('is_default');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $t): void {
            $t->id('academic_year_id');
            $t->string('year_name');
            $t->timestamps();
        });
        Schema::create('semesters', function (Blueprint $t): void {
            $t->id('semester_id');
            $t->string('semester_code');
            $t->string('semester_name');
            $t->timestamps();
        });
        Schema::create('courses', function (Blueprint $t): void {
            $t->id('course_id');
            $t->string('course_code');
            $t->string('course_name');
            $t->integer('credit_hours');
            $t->timestamps();
        });
        Schema::create('student_statuses', fn (Blueprint $t) => $this->statusTable($t, 'student_status_id'));
        Schema::create('students', function (Blueprint $t): void {
            $t->id('student_id');
            $t->string('student_number');
            $t->string('first_name');
            $t->string('last_name');
            $t->date('enrollment_date');
            $t->unsignedBigInteger('student_status_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->id('course_offering_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->integer('capacity');
            $t->integer('available_seats');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('student_course_registrations', function (Blueprint $t): void {
            $t->id('student_course_registration_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('course_offering_id');
            $t->date('registration_date');
            $t->unsignedBigInteger('registered_by_user_id');
            $t->unsignedBigInteger('registration_status_id');
            $t->unsignedBigInteger('result_status_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('student_course_results', function (Blueprint $t): void {
            $t->id('student_course_result_id');
            $t->unsignedBigInteger('student_course_registration_id');
            $t->decimal('theoretical_total')->nullable();
            $t->decimal('practical_total')->nullable();
            $t->decimal('coursework_total')->nullable();
            $t->decimal('final_mark')->nullable();
            $t->unsignedBigInteger('result_status_id');
            $t->boolean('is_deprived');
            $t->string('incomplete_reason')->nullable();
            $t->text('incomplete_requirements')->nullable();
            $t->timestamp('incomplete_granted_at')->nullable();
            $t->date('incomplete_deadline')->nullable();
            $t->timestamp('incomplete_resolved_at')->nullable();
            $t->timestamp('calculated_at')->nullable();
            $t->unsignedBigInteger('calculated_by_user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('grade_components', function (Blueprint $t): void {
            $t->id('grade_component_id');
            $t->unsignedBigInteger('course_offering_id');
            $t->string('component_name');
            $t->string('component_type');
            $t->decimal('max_mark');
            $t->timestamps();
        });
        Schema::create('student_grade_components', function (Blueprint $t): void {
            $t->id('student_grade_component_id');
            $t->unsignedBigInteger('student_course_registration_id');
            $t->unsignedBigInteger('grade_component_id');
            $t->decimal('mark');
            $t->string('grade_status');
            $t->unsignedBigInteger('entered_by_user_id')->nullable();
            $t->timestamp('entered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_statuses', function (Blueprint $t): void {
            $this->statusTable($t, 'attendance_status_id');
            $t->boolean('counts_as_absent');
        });
        Schema::create('attendance_sessions', function (Blueprint $t): void {
            $t->id('attendance_session_id');
            $t->unsignedBigInteger('course_offering_id');
            $t->string('session_type');
            $t->date('session_date');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestamps();
        });
        Schema::create('student_attendance', function (Blueprint $t): void {
            $t->id('student_attendance_id');
            $t->unsignedBigInteger('attendance_session_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('attendance_status_id');
            $t->timestamps();
        });
    }

    private function statusTable(Blueprint $table, string $key): void
    {
        $table->id($key);
        $table->string('status_code')->unique();
        $table->string('status_name');
        $table->boolean('is_active');
        $table->timestamps();
    }
}
