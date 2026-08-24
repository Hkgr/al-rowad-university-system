<?php

namespace Tests\Feature;

use App\Models\AcademicProgram;
use App\Models\Course;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExamBoardCourseCatalogDataScopeTest extends TestCase
{
    private DataScopeService $scope;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedCatalog();
        $this->scope = app(DataScopeService::class);
    }

    public function test_actual_university_scope_can_read_an_unlinked_course(): void
    {
        $user = $this->actor(1, 'university', 1);

        self::assertSame([1, 2, 3, 4, 5], $this->courseIds($user));
        self::assertTrue($this->scope->hasActualUniversityScope($user));
    }

    public function test_college_scope_does_not_gain_unlinked_or_unrelated_courses(): void
    {
        $user = $this->actor(2, 'college', 10);

        self::assertSame([2, 4], $this->courseIds($user));
        self::assertNotContains(1, $this->courseIds($user));
        self::assertNotContains(3, $this->courseIds($user));
    }

    public function test_department_program_and_offering_scope_paths_remain_available(): void
    {
        self::assertSame([2, 4], $this->courseIds($this->actor(3, 'department', 18)));
        self::assertSame([4], $this->courseIds($this->actor(4, 'program', 18)));
        self::assertSame([5], $this->courseIds($this->actor(5, 'section', 50)));
    }

    public function test_role_alone_does_not_create_scope_or_weaken_mutation_checks(): void
    {
        $examOfficerWithoutScope = $this->actor(6, roles: ['exam_officer']);
        $superAdminWithoutScope = $this->actor(7, roles: ['super_admin']);

        self::assertSame([], $this->courseIds($examOfficerWithoutScope));
        self::assertFalse($this->scope->hasActualUniversityScope($examOfficerWithoutScope));
        self::assertFalse($this->scope->canMutateProgram($examOfficerWithoutScope, 18));
        self::assertSame([1, 2, 3, 4, 5], $this->courseIds($superAdminWithoutScope));
        self::assertFalse(
            $this->scope->canMutateProgram($superAdminWithoutScope, AcademicProgram::query()->findOrFail(18)),
            'The existing super-admin read bypass must not become a mutation scope.',
        );
    }

    /** @return list<int> */
    private function courseIds(User $user): array
    {
        return $this->scope->scopeCourses(Course::query(), $user)
            ->orderBy('course_id')
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @param list<string> $roles */
    private function actor(
        int $userId,
        ?string $scopeType = null,
        ?int $scopeId = null,
        array $roles = [],
    ): User {
        if ($scopeType !== null && $scopeId !== null) {
            DB::table('user_access_scopes')->insert([
                'user_id' => $userId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'is_active' => 1,
            ]);
        }

        $user = $this->getMockBuilder(User::class)
            ->onlyMethods(['effectiveRoles'])
            ->getMock();
        $user->setRawAttributes([
            'user_id' => $userId,
            'employee_id' => null,
            'student_id' => null,
        ]);
        $user->method('effectiveRoles')->willReturn(collect($roles));

        return $user;
    }

    private function seedCatalog(): void
    {
        DB::table('organizational_units')->insert([
            'organizational_unit_id' => 1,
            'unit_code' => 'PRES',
        ]);
        DB::table('colleges')->insert([
            ['college_id' => 10],
            ['college_id' => 11],
        ]);
        DB::table('departments')->insert([
            ['department_id' => 18, 'college_id' => 10],
            ['department_id' => 21, 'college_id' => 11],
        ]);
        DB::table('academic_programs')->insert([
            'academic_program_id' => 18,
            'department_id' => 18,
        ]);
        DB::table('courses')->insert(array_map(
            static fn (int $courseId): array => ['course_id' => $courseId],
            range(1, 5),
        ));
        DB::table('course_departments')->insert([
            ['course_department_id' => 1, 'course_id' => 2, 'department_id' => 18],
            ['course_department_id' => 2, 'course_id' => 3, 'department_id' => 21],
        ]);
        DB::table('program_courses')->insert([
            'program_course_id' => 1,
            'course_id' => 4,
            'academic_program_id' => 18,
        ]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 50,
            'course_id' => 5,
            'department_id' => null,
            'academic_program_id' => null,
            'faculty_member_id' => null,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->increments('user_access_scope_id');
            $table->integer('user_id');
            $table->string('scope_type');
            $table->integer('scope_id');
            $table->boolean('is_active');
        });
        Schema::create('organizational_units', function (Blueprint $table): void {
            $table->increments('organizational_unit_id');
            $table->string('unit_code');
        });
        Schema::create('colleges', function (Blueprint $table): void {
            $table->increments('college_id');
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id');
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->integer('department_id');
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
        });
        Schema::create('course_departments', function (Blueprint $table): void {
            $table->increments('course_department_id');
            $table->integer('course_id');
            $table->integer('department_id');
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->increments('program_course_id');
            $table->integer('course_id');
            $table->integer('academic_program_id');
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('department_id')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->integer('faculty_member_id')->nullable();
        });
        Schema::create('faculty_members', function (Blueprint $table): void {
            $table->increments('faculty_member_id');
            $table->integer('employee_id')->nullable();
        });
        Schema::create('course_offering_instructors', function (Blueprint $table): void {
            $table->increments('course_offering_instructor_id');
            $table->integer('course_offering_id');
            $table->integer('faculty_member_id');
            $table->boolean('is_active');
        });
    }
}
