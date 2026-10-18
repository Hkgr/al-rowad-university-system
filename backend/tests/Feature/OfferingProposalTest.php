<?php

namespace Tests\Feature;

use App\Exceptions\OfferingProposalException;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FacultyMember;
use App\Models\ProgramCourse;
use App\Models\Semester;
use App\Models\User;
use App\Services\OfferingProposalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfferingProposalTest extends TestCase
{
    private AcademicYear $year;

    private Semester $semester;

    private Department $department;

    private AcademicProgram $program;

    private Course $mandatory;

    private Course $elective;

    private FacultyMember $faculty;

    private User $dean;

    private User $vicePresident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();

        $this->year = AcademicYear::query()->create(['year_name' => '2026/2027']);
        $this->semester = Semester::query()->create(['semester_code' => 'S1', 'semester_name' => 'الفصل الأول']);
        $this->department = Department::query()->create([
            'department_code' => 'CS',
            'department_name' => 'هندسة المعلوماتية',
            'is_active' => true,
        ]);
        $this->program = AcademicProgram::query()->create([
            'department_id' => $this->department->department_id,
            'program_code' => 'CS-BSC',
            'program_name' => 'هندسة البرمجيات',
            'is_active' => true,
        ]);
        $this->mandatory = Course::query()->create(['course_code' => 'CS101', 'course_name' => 'برمجة 1', 'credit_hours' => 3]);
        $this->elective = Course::query()->create(['course_code' => 'CS210', 'course_name' => 'مادة اختيارية', 'credit_hours' => 2]);
        ProgramCourse::query()->create([
            'academic_program_id' => $this->program->academic_program_id,
            'course_id' => $this->mandatory->course_id,
            'course_type' => 'mandatory',
            'is_active' => true,
        ]);
        ProgramCourse::query()->create([
            'academic_program_id' => $this->program->academic_program_id,
            'course_id' => $this->elective->course_id,
            'course_type' => 'elective',
            'is_active' => true,
        ]);
        $this->faculty = FacultyMember::query()->create(['employee_id' => 1, 'is_active' => true]);
        $this->dean = $this->user(11);
        $this->vicePresident = $this->user(12);
    }

    public function test_mandatory_course_without_instructor_opens_only_after_vice_president_approval(): void
    {
        $service = app(OfferingProposalService::class);
        $proposal = $service->submit($this->dean, $this->term());

        $this->assertSame('submitted', $proposal['status']);
        $this->assertCount(1, $proposal['items']);
        $this->assertTrue($proposal['items'][0]['instructor_exception']);
        $this->assertNull($proposal['items'][0]['faculty_member_id']);
        $this->assertSame(0, CourseOffering::query()->count());

        $approved = $service->approve($this->vicePresident, $proposal['offering_proposal_id']);

        $this->assertSame('approved', $approved['status']);
        $offering = CourseOffering::query()->first();
        $this->assertNotNull($offering);
        $this->assertSame('open', $offering->status);
        $this->assertNull($offering->faculty_member_id);
        $this->assertSame($this->mandatory->course_id, $offering->course_id);
    }

    public function test_unselected_elective_is_omitted_and_elective_without_instructor_is_rejected(): void
    {
        $service = app(OfferingProposalService::class);

        try {
            $service->submit($this->dean, $this->term([
                'electives' => [[
                    'course_id' => $this->elective->course_id,
                    'academic_program_id' => $this->program->academic_program_id,
                ]],
            ]));
            $this->fail('Elective without an instructor was accepted.');
        } catch (OfferingProposalException $exception) {
            $this->assertStringContainsString('اختيارية', $exception->getMessage());
        }

        $proposal = $service->submit($this->dean, $this->term([
            'electives' => [[
                'course_id' => $this->elective->course_id,
                'academic_program_id' => $this->program->academic_program_id,
                'faculty_member_id' => $this->faculty->faculty_member_id,
            ]],
        ]));

        $this->assertCount(2, $proposal['items']);
        $elective = collect($proposal['items'])->firstWhere('course_type', 'elective');
        $this->assertFalse($elective['instructor_exception']);
        $this->assertSame($this->faculty->faculty_member_id, $elective['faculty_member_id']);
    }

    public function test_rejection_creates_no_offering_and_a_pending_proposal_blocks_another_submit(): void
    {
        $service = app(OfferingProposalService::class);
        $proposal = $service->submit($this->dean, $this->term());

        try {
            $service->submit($this->dean, $this->term());
            $this->fail('A second pending proposal was accepted.');
        } catch (OfferingProposalException $exception) {
            $this->assertStringContainsString('بانتظار', $exception->getMessage());
        }

        $rejected = $service->reject($this->vicePresident, $proposal['offering_proposal_id'], 'غير مكتمل');

        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame(0, CourseOffering::query()->count());

        $resubmitted = $service->submit($this->dean, $this->term());
        $this->assertSame('submitted', $resubmitted['status']);
    }

    private function term(array $extra = []): array
    {
        return array_merge([
            'academic_year_id' => $this->year->academic_year_id,
            'semester_id' => $this->semester->semester_id,
            'department_id' => $this->department->department_id,
        ], $extra);
    }

    private function user(int $id): User
    {
        $user = new User;
        $user->user_id = $id;
        $user->exists = true;

        return $user;
    }

    private function createSchema(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id('academic_year_id');
            $table->string('year_name');
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id('semester_id');
            $table->string('semester_code');
            $table->string('semester_name');
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code');
            $table->string('department_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->id('academic_program_id');
            $table->unsignedBigInteger('department_id');
            $table->string('program_code');
            $table->string('program_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->timestamps();
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->id('program_course_id');
            $table->unsignedBigInteger('academic_program_id');
            $table->unsignedBigInteger('course_id');
            $table->string('course_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('faculty_members', function (Blueprint $table): void {
            $table->id('faculty_member_id');
            $table->unsignedBigInteger('employee_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('username')->nullable();
            $table->timestamps();
        });
        Schema::create('offering_proposals', function (Blueprint $table): void {
            $table->id('offering_proposal_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->string('status');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
        Schema::create('offering_proposal_items', function (Blueprint $table): void {
            $table->id('offering_proposal_item_id');
            $table->unsignedBigInteger('offering_proposal_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->string('course_type');
            $table->unsignedBigInteger('faculty_member_id')->nullable();
            $table->boolean('instructor_exception')->default(false);
            $table->string('exception_reason')->nullable();
            $table->unsignedInteger('capacity')->default(40);
            $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id('course_offering_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('faculty_member_id')->nullable();
            $table->integer('capacity');
            $table->integer('available_seats');
            $table->string('status');
            $table->timestamps();
        });
    }
}
