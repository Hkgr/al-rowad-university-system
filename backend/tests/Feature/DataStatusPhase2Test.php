<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceException;
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
use App\Models\AccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\ApprovalStatus;
use App\Models\StudentStatus;
use App\Models\FacultyMember;
use App\Services\AcademicAuthorizationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\RegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataStatusPhase2Test extends TestCase
{
    private Student $student;
    private CourseOffering $offering;
    private array $registrationStatuses;
    private ResultStatus $passed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIsolatedSchema();

        foreach (['registered', 'completed', 'dropped', 'withdrawn'] as $code) {
            $this->registrationStatuses[$code] = RegistrationStatus::query()->create([
                'status_code' => $code, 'status_name' => ucfirst($code), 'is_active' => true,
            ]);
        }
        foreach (['passed', 'failed', 'incomplete', 'deprived'] as $code) {
            $status = ResultStatus::query()->create([
                'status_code' => $code, 'status_name' => ucfirst($code), 'is_active' => true,
            ]);
            if ($code === 'passed') {
                $this->passed = $status;
            }
        }

        GradingPolicy::query()->create([
            'policy_name' => 'Test policy', 'theoretical_max_mark' => 60, 'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 15, 'minimum_practical_mark' => 10,
            'minimum_final_mark' => 50, 'absence_deprivation_percentage' => 15,
            'is_default' => true, 'is_active' => true,
        ]);
        $year = AcademicYear::query()->create(['year_name' => '2025/2026']);
        $semester = Semester::query()->create(['semester_code' => 'S1', 'semester_name' => 'First']);
        $course = Course::query()->create(['course_code' => 'TST101', 'course_name' => 'Test Course', 'credit_hours' => 3]);
        $this->student = Student::query()->create([
            'student_number' => 'TEST-001', 'first_name' => 'Test', 'last_name' => 'Student',
            'enrollment_date' => '2025-09-01',
        ]);
        $this->offering = CourseOffering::query()->create([
            'course_id' => $course->course_id, 'academic_year_id' => $year->academic_year_id,
            'semester_id' => $semester->semester_id, 'capacity' => 20, 'available_seats' => 20, 'status' => 'open',
        ]);
    }

    public function test_completed_result_is_visible_and_contributes_to_gpa_and_cgpa_but_is_read_only(): void
    {
        $registration = $this->registration('completed', true);
        $grades = app(GradeService::class);

        $this->assertSame([$registration->student_course_registration_id], collect($grades->getGradeSheet($this->offering->course_offering_id)['students'])->pluck('student_course_registration_id')->all());
        $this->assertFalse($grades->getGradeSheet($this->offering->course_offering_id)['students'][0]['grade_entry_allowed']);
        $this->assertCount(1, $grades->getTranscript($this->student)['terms'][0]['courses']);
        $this->assertSame(3.0, $grades->calculateGpa($this->student, $this->offering->academic_year_id, $this->offering->semester_id)['gpa']);
        $this->assertSame(3.0, $grades->calculateCgpa($this->student)['cgpa']);

        $this->expectException(GradeException::class);
        $grades->updateRegistrationGrades($registration->student_course_registration_id, ['theoretical_mark' => 50, 'practical_mark' => 30]);
    }

    public function test_current_registration_appears_while_excluded_and_missing_results_do_not_affect_summary(): void
    {
        $current = $this->registration('registered', false);
        $this->registration('dropped', true);
        $this->registration('withdrawn', true);
        $summary = app(GradeService::class)->getResultsSummary($this->offering->course_offering_id);

        $this->assertSame(1, $summary['total_registered_students']);
        $this->assertSame(0, $summary['total_students_with_results']);
        $this->assertSame(0, $summary['failed_count']);
        $this->assertSame([$current->student_course_registration_id], collect(app(GradeService::class)->getGradeSheet($this->offering->course_offering_id)['students'])->pluck('student_course_registration_id')->all());
    }

    public function test_only_registered_status_counts_toward_current_credit_hours(): void
    {
        foreach (['completed', 'dropped', 'withdrawn'] as $code) {
            $this->registration($code, true);
        }
        $hours = app(RegistrationService::class)->getRegisteredHours($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(0, $hours['registered_hours']);

        $this->registration('registered', false);
        $hours = app(RegistrationService::class)->getRegisteredHours($this->student, $this->offering->academic_year_id, $this->offering->semester_id);
        $this->assertSame(3, $hours['registered_hours']);
    }

    public function test_attendance_writes_reject_every_non_current_status_but_historical_reads_remain_available(): void
    {
        $attendanceStatus = AttendanceStatus::query()->create(['status_code' => 'present', 'status_name' => 'Present', 'counts_as_absent' => false, 'is_active' => true]);
        $session = AttendanceSession::query()->create(['course_offering_id' => $this->offering->course_offering_id, 'session_type' => 'theoretical', 'session_date' => '2025-10-01', 'created_by_user_id' => 1]);
        $completed = $this->registration('completed', true);
        StudentAttendance::query()->create(['attendance_session_id' => $session->attendance_session_id, 'student_id' => $this->student->student_id, 'attendance_status_id' => $attendanceStatus->attendance_status_id]);

        $history = app(AttendanceService::class)->getStudentAttendance($this->student);
        $this->assertCount(1, $history['courses'][0]['sessions']);

        foreach (['completed', 'dropped', 'withdrawn'] as $code) {
            $registration = $code === 'completed' ? $completed : $this->registration($code, false);
            try {
                app(AttendanceService::class)->recordSessionAttendance($session->attendance_session_id, [[
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'status_code' => 'present',
                ]]);
                $this->fail("{$code} attendance write was accepted");
            } catch (AttendanceException $exception) {
                $this->assertStringContainsString('actively registered', $exception->getMessage());
            }
        }
    }

    public function test_student_cannot_read_another_students_transcript(): void
    {
        $active = AccountStatus::query()->create(['status_code' => 'active', 'status_name' => 'Active', 'is_active' => true]);
        $role = Role::query()->create(['role_code' => 'student', 'role_name' => 'Student', 'is_system_role' => true, 'is_active' => true]);
        $other = Student::query()->create(['student_number' => 'TEST-002', 'first_name' => 'Other', 'last_name' => 'Student', 'enrollment_date' => '2025-09-01']);
        $user = User::query()->create(['username' => 'student', 'email' => 'student@test.invalid', 'password_hash' => 'unused', 'account_status_id' => $active->account_status_id, 'student_id' => $this->student->student_id]);
        UserRole::query()->create(['user_id' => $user->user_id, 'role_id' => $role->role_id, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/students/'.$other->student_id.'/transcript')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'forbidden');
    }

    public function test_graduate_search_and_approval_use_codes_while_graduation_is_safely_blocked(): void
    {
        $activeStudent = StudentStatus::query()->create(['status_code' => 'active', 'status_name' => 'Active', 'is_active' => true]);
        $graduated = StudentStatus::query()->create(['status_code' => 'graduated', 'status_name' => 'Graduated', 'is_active' => true]);
        $this->student->update(['student_status_id' => $graduated->student_status_id]);
        Student::query()->create(['student_number' => 'TEST-003', 'first_name' => 'Active', 'last_name' => 'Student', 'enrollment_date' => '2025-09-01', 'student_status_id' => $activeStudent->student_status_id]);

        $registrar = $this->userWithRole('registration_officer');
        $this->actingAs($registrar, 'sanctum')
            ->getJson('/api/v1/students?student_status_code=graduated')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student_number', 'TEST-001');
        $this->actingAs($registrar, 'sanctum')
            ->putJson('/api/v1/students/'.$this->student->student_id, ['student_status_code' => 'graduated'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'graduation_eligibility_not_implemented');

        $approved = ApprovalStatus::query()->create(['status_code' => 'approved', 'status_name' => 'Approved', 'is_active' => true]);
        $exam = $this->userWithRole('board_member');
        $this->actingAs($exam, 'sanctum')->postJson('/api/v1/grade-approvals', [
            'course_offering_id' => $this->offering->course_offering_id,
            'approval_status_code' => 'approved',
            'submitted_by_user_id' => $exam->user_id,
        ])->assertCreated();
        $this->assertDatabaseHas('grade_approvals', ['approval_status_id' => $approved->approval_status_id]);
        $this->actingAs($exam, 'sanctum')->postJson('/api/v1/grade-approvals', [
            'course_offering_id' => $this->offering->course_offering_id,
            'approval_status_code' => 'missing-code',
            'submitted_by_user_id' => $exam->user_id,
        ])->assertUnprocessable();
    }

    public function test_instructor_is_limited_to_the_assigned_section(): void
    {
        $instructor = $this->userWithRole('doctor_instructor');
        $instructor->update(['employee_id' => 77]);
        $faculty = FacultyMember::query()->create(['employee_id' => 77, 'is_active' => true]);
        $this->offering->update(['faculty_member_id' => $faculty->faculty_member_id]);

        app(AcademicAuthorizationService::class)->assertCanAccessOffering($instructor, $this->offering->course_offering_id);

        $other = $this->offering->replicate();
        $other->faculty_member_id = null;
        $other->save();
        $this->expectException(AccessDeniedHttpException::class);
        app(AcademicAuthorizationService::class)->assertCanAccessOffering($instructor, $other->course_offering_id);
    }

    public function test_instructor_cannot_record_attendance_outside_assigned_section(): void
    {
        $instructor = $this->userWithRole('doctor_instructor');
        $instructor->update(['employee_id' => 88]);
        FacultyMember::query()->create(['employee_id' => 88, 'is_active' => true]);
        $session = AttendanceSession::query()->create([
            'course_offering_id' => $this->offering->course_offering_id,
            'session_type' => 'theoretical',
            'session_date' => '2025-10-02',
            'created_by_user_id' => $instructor->user_id,
        ]);
        $registration = $this->registration('registered', false);
        AttendanceStatus::query()->create(['status_code' => 'present', 'status_name' => 'Present', 'counts_as_absent' => false, 'is_active' => true]);

        $this->actingAs($instructor, 'sanctum')
            ->postJson('/api/v1/attendance-sessions/'.$session->attendance_session_id.'/record', ['records' => [[
                'student_course_registration_id' => $registration->student_course_registration_id,
                'status_code' => 'present',
            ]]])
            ->assertForbidden();
    }

    public function test_raw_result_resource_is_read_only_and_historical_result_remains_authorized_to_read(): void
    {
        $completed = $this->registration('completed', true);
        $result = $completed->studentCourseResult()->firstOrFail();
        $exam = $this->userWithRole('board_member');

        $this->actingAs($exam, 'sanctum')
            ->getJson('/api/v1/student-course-results/'.$result->student_course_result_id)
            ->assertOk()
            ->assertJsonPath('data.student_course_result_id', $result->student_course_result_id);

        $payload = [
            'student_course_registration_id' => $completed->student_course_registration_id,
            'theoretical_total' => 1,
            'practical_total' => 1,
            'result_status_id' => $this->passed->result_status_id,
            'is_deprived' => false,
        ];
        $this->actingAs($exam, 'sanctum')->postJson('/api/v1/student-course-results', $payload)->assertMethodNotAllowed();
        $this->actingAs($exam, 'sanctum')->putJson('/api/v1/student-course-results/'.$result->student_course_result_id, $payload)->assertMethodNotAllowed();
        $this->actingAs($exam, 'sanctum')->patchJson('/api/v1/student-course-results/'.$result->student_course_result_id, $payload)->assertMethodNotAllowed();
        $this->actingAs($exam, 'sanctum')->deleteJson('/api/v1/student-course-results/'.$result->student_course_result_id)->assertMethodNotAllowed();
        $this->actingAs($exam, 'sanctum')->postJson('/api/v1/student-course-results/bulk', ['results' => [$payload]])->assertMethodNotAllowed();
        $this->actingAs($exam, 'sanctum')->patchJson('/api/v1/student-course-registrations/'.$completed->student_course_registration_id, [
            'registration_status_id' => $this->registrationStatuses['registered']->registration_status_id,
        ])->assertMethodNotAllowed();

        $this->assertDatabaseHas('student_course_results', [
            'student_course_result_id' => $result->student_course_result_id,
            'final_mark' => 80,
        ]);
    }

    public function test_grade_endpoint_accepts_current_registration_but_rejects_historical_and_student_writes(): void
    {
        $exam = $this->userWithRole('board_member');
        $current = $this->registration('registered', false);
        $this->actingAs($exam, 'sanctum')->postJson('/api/v1/registrations/'.$current->student_course_registration_id.'/grades', [
            'theoretical_mark' => 48,
            'practical_mark' => 32,
        ])->assertCreated();

        $completed = $this->registration('completed', true);
        $this->actingAs($exam, 'sanctum')->putJson('/api/v1/registrations/'.$completed->student_course_registration_id.'/grades', [
            'theoretical_mark' => 50,
            'practical_mark' => 35,
        ])->assertUnprocessable();

        foreach (['dropped', 'withdrawn'] as $statusCode) {
            $ineligible = $this->registration($statusCode, false);
            $this->actingAs($exam, 'sanctum')->postJson('/api/v1/registrations/'.$ineligible->student_course_registration_id.'/grades', [
                'theoretical_mark' => 48,
                'practical_mark' => 32,
            ])->assertUnprocessable();
        }

        $studentUser = $this->userWithRole('student');
        $studentUser->update(['student_id' => $this->student->student_id]);
        $this->actingAs($studentUser, 'sanctum')->putJson('/api/v1/registrations/'.$current->student_course_registration_id.'/grades', [
            'theoretical_mark' => 50,
            'practical_mark' => 35,
        ])->assertForbidden();
    }

    public function test_student_affairs_cannot_finalize_deprivation_but_exam_committee_can(): void
    {
        $registrar = $this->userWithRole('registration_officer');
        $this->actingAs($registrar, 'sanctum')
            ->postJson('/api/v1/course-offerings/'.$this->offering->course_offering_id.'/apply-deprivation')
            ->assertForbidden();

        $exam = $this->userWithRole('board_member');
        $this->actingAs($exam, 'sanctum')
            ->postJson('/api/v1/course-offerings/'.$this->offering->course_offering_id.'/apply-deprivation')
            ->assertOk();
    }

    public function test_disabled_user_cannot_reuse_authenticated_access(): void
    {
        $disabled = AccountStatus::query()->create(['status_code' => 'disabled', 'status_name' => 'Disabled', 'is_active' => true]);
        $role = Role::query()->create(['role_code' => 'board_member', 'role_name' => 'Exam Officer', 'is_system_role' => true, 'is_active' => true]);
        $user = User::query()->create(['username' => 'disabled', 'email' => 'disabled@test.invalid', 'password_hash' => 'unused', 'account_status_id' => $disabled->account_status_id]);
        UserRole::query()->create(['user_id' => $user->user_id, 'role_id' => $role->role_id, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student-course-results')
            ->assertForbidden();
    }

    private function registration(string $statusCode, bool $withResult): StudentCourseRegistration
    {
        $registration = StudentCourseRegistration::query()->create([
            'student_id' => $this->student->student_id, 'course_offering_id' => $this->offering->course_offering_id,
            'registration_date' => '2025-09-01', 'registered_by_user_id' => 1,
            'registration_status_id' => $this->registrationStatuses[$statusCode]->registration_status_id,
        ]);
        if ($withResult) {
            StudentCourseResult::query()->create([
                'student_course_registration_id' => $registration->student_course_registration_id,
                'theoretical_total' => 48, 'practical_total' => 32, 'coursework_total' => 0,
                'final_mark' => 80, 'result_status_id' => $this->passed->result_status_id, 'is_deprived' => false,
            ]);
        }
        return $registration;
    }

    private function userWithRole(string $roleCode): User
    {
        $active = AccountStatus::query()->firstOrCreate(['status_code' => 'active'], ['status_name' => 'Active', 'is_active' => true]);
        $role = Role::query()->firstOrCreate(['role_code' => $roleCode], ['role_name' => $roleCode, 'is_system_role' => true, 'is_active' => true]);
        $user = User::query()->create(['username' => $roleCode.uniqid(), 'email' => uniqid().('@test.invalid'), 'password_hash' => 'unused', 'account_status_id' => $active->account_status_id]);
        UserRole::query()->create(['user_id' => $user->user_id, 'role_id' => $role->role_id, 'is_active' => true]);
        return $user;
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('registration_statuses', fn (Blueprint $t) => $this->statusTable($t, 'registration_status_id'));
        Schema::create('result_statuses', fn (Blueprint $t) => $this->statusTable($t, 'result_status_id'));
        Schema::create('grading_policies', function (Blueprint $t): void { $t->id('grading_policy_id'); $t->string('policy_name'); foreach (['theoretical_max_mark','practical_max_mark','minimum_theoretical_mark','minimum_practical_mark','minimum_final_mark','absence_deprivation_percentage'] as $c) $t->decimal($c); $t->boolean('is_default'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('academic_years', function (Blueprint $t): void { $t->id('academic_year_id'); $t->string('year_name'); $t->timestamps(); });
        Schema::create('semesters', function (Blueprint $t): void { $t->id('semester_id'); $t->string('semester_code'); $t->string('semester_name'); $t->timestamps(); });
        Schema::create('courses', function (Blueprint $t): void { $t->id('course_id'); $t->string('course_code'); $t->string('course_name'); $t->integer('credit_hours'); $t->timestamps(); });
        Schema::create('student_statuses', fn (Blueprint $t) => $this->statusTable($t, 'student_status_id'));
        Schema::create('students', function (Blueprint $t): void { $t->id('student_id'); $t->string('student_number'); $t->string('first_name'); $t->string('last_name'); $t->date('enrollment_date'); $t->unsignedBigInteger('student_status_id')->nullable(); $t->unsignedBigInteger('academic_program_id')->nullable(); $t->unsignedBigInteger('current_academic_level_id')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('course_offerings', function (Blueprint $t): void { $t->id('course_offering_id'); $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('semester_id'); $t->unsignedBigInteger('faculty_member_id')->nullable(); $t->integer('capacity'); $t->integer('available_seats'); $t->string('status'); $t->timestamps(); });
        Schema::create('student_course_registrations', function (Blueprint $t): void { $t->id('student_course_registration_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('course_offering_id'); $t->date('registration_date'); $t->unsignedBigInteger('registered_by_user_id'); $t->unsignedBigInteger('registration_status_id'); $t->unsignedBigInteger('result_status_id')->nullable(); $t->text('notes')->nullable(); $t->timestamps(); });
        Schema::create('student_course_results', function (Blueprint $t): void { $t->id('student_course_result_id'); $t->unsignedBigInteger('student_course_registration_id'); $t->decimal('theoretical_total')->nullable(); $t->decimal('practical_total')->nullable(); $t->decimal('coursework_total')->nullable(); $t->decimal('final_mark')->nullable(); $t->unsignedBigInteger('result_status_id'); $t->boolean('is_deprived'); $t->timestamp('calculated_at')->nullable(); $t->unsignedBigInteger('calculated_by_user_id')->nullable(); $t->timestamps(); });
        Schema::create('grade_components', function (Blueprint $t): void { $t->id('grade_component_id'); $t->unsignedBigInteger('course_offering_id'); $t->string('component_name'); $t->string('component_type'); $t->decimal('max_mark'); $t->timestamps(); });
        Schema::create('student_grade_components', function (Blueprint $t): void { $t->id('student_grade_component_id'); $t->unsignedBigInteger('student_course_registration_id'); $t->unsignedBigInteger('grade_component_id'); $t->decimal('mark'); $t->string('grade_status'); $t->unsignedBigInteger('entered_by_user_id')->nullable(); $t->timestamp('entered_at')->nullable(); $t->timestamps(); });
        Schema::create('student_credit_limits', function (Blueprint $t): void { $t->id('credit_limit_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('semester_id'); $t->integer('max_credit_hours'); $t->timestamps(); });
        Schema::create('attendance_statuses', fn (Blueprint $t) => $this->attendanceStatusTable($t));
        Schema::create('attendance_sessions', function (Blueprint $t): void { $t->id('attendance_session_id'); $t->unsignedBigInteger('course_offering_id'); $t->string('session_type'); $t->date('session_date'); $t->unsignedBigInteger('created_by_user_id'); $t->timestamps(); });
        Schema::create('student_attendance', function (Blueprint $t): void { $t->id('student_attendance_id'); $t->unsignedBigInteger('attendance_session_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('attendance_status_id'); $t->text('notes')->nullable(); $t->timestamps(); });
        Schema::create('account_statuses', fn (Blueprint $t) => $this->statusTable($t, 'account_status_id'));
        Schema::create('roles', function (Blueprint $t): void { $t->id('role_id'); $t->string('role_code'); $t->string('role_name'); $t->boolean('is_system_role'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t): void { $t->id('user_id'); $t->string('username'); $t->string('email'); $t->string('password_hash'); $t->unsignedBigInteger('account_status_id'); $t->unsignedBigInteger('student_id')->nullable(); $t->unsignedBigInteger('employee_id')->nullable(); $t->timestamps(); });
        Schema::create('user_roles', function (Blueprint $t): void { $t->id('user_role_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('assigned_by_user_id')->nullable(); $t->timestamp('assigned_at')->nullable(); $t->boolean('is_active'); });
        Schema::create('approval_statuses', fn (Blueprint $t) => $this->statusTable($t, 'approval_status_id'));
        Schema::create('grade_approvals', function (Blueprint $t): void { $t->id('grade_approval_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('approval_status_id'); $t->unsignedBigInteger('submitted_by_user_id'); $t->timestamp('submitted_at')->nullable(); $t->timestamps(); });
        Schema::create('faculty_members', function (Blueprint $t): void { $t->id('faculty_member_id'); $t->unsignedBigInteger('employee_id'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('course_offering_instructors', function (Blueprint $t): void { $t->id('course_offering_instructor_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('faculty_member_id'); $t->boolean('is_active'); $t->timestamps(); });
    }

    private function statusTable(Blueprint $table, string $key): void { $table->id($key); $table->string('status_code')->unique(); $table->string('status_name'); $table->boolean('is_active'); $table->timestamps(); }
    private function attendanceStatusTable(Blueprint $table): void { $this->statusTable($table, 'attendance_status_id'); $table->boolean('counts_as_absent'); }
}
