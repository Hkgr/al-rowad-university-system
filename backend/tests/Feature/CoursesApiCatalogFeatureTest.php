<?php

namespace Tests\Feature;

use App\Models\AccountStatus;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoursesApiCatalogFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedCatalog();
    }

    public function test_super_admin_receives_paginated_courses_with_batched_requirement_classification(): void
    {
        $response = $this->actingAs($this->actor(1, ['super_admin']), 'sanctum')
            ->getJson('/api/v1/courses?per_page=100&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 101);

        $rows = collect($response->json('data.data'))->keyBy('course_id');
        self::assertCount(100, $rows);
        self::assertSame([], $rows->get(1)['program_requirement_classifications']);
        self::assertSame(
            'requirement_mapping_missing',
            $rows->get(2)['program_requirement_classifications'][0]['requirement_classification']['status'],
        );
        self::assertSame(
            'mapped',
            $rows->get(3)['program_requirement_classifications'][0]['requirement_classification']['status'],
        );
        self::assertSame(
            'mapped',
            $rows->get(4)['program_requirement_classifications'][0]['requirement_classification']['status'],
        );

        $this->actingAs($this->actor(2, ['super_admin']), 'sanctum')
            ->getJson('/api/v1/courses?per_page=100&page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.total', 101);
    }

    public function test_exam_officer_with_courses_view_and_actual_university_scope_receives_catalog(): void
    {
        DB::table('user_access_scopes')->insert([
            'user_id' => 3,
            'scope_type' => 'university',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->actor(3, ['exam_officer']), 'sanctum')
            ->getJson('/api/v1/courses?per_page=100&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 101);

        self::assertIsArray($response->json('data.data'));
        self::assertSame(
            'mapped',
            collect($response->json('data.data'))
                ->firstWhere('course_id', 3)['program_requirement_classifications'][0]['requirement_classification']['status'],
        );
    }

    public function test_requirement_enrichment_query_count_does_not_scale_per_course(): void
    {
        $this->actingAs($this->actor(4, ['super_admin']), 'sanctum');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/courses?per_page=4&page=1')->assertOk();
        $fourCourseQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson('/api/v1/courses?per_page=100&page=1')->assertOk();
        $hundredCourseQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThan(20, $hundredCourseQueries);
        self::assertLessThanOrEqual($fourCourseQueries + 1, $hundredCourseQueries);
    }

    /** @param list<string> $roles */
    private function actor(int $userId, array $roles): User
    {
        $user = $this->getMockBuilder(User::class)
            ->onlyMethods(['effectiveRoles', 'hasPermission'])
            ->getMock();
        $user->setRawAttributes([
            'user_id' => $userId,
            'account_status_id' => 1,
            'employee_id' => null,
            'student_id' => null,
        ]);
        $user->method('effectiveRoles')->willReturn(collect($roles));
        $user->method('hasPermission')->willReturnCallback(
            static fn (string $permission): bool => $permission === 'courses.view',
        );
        $user->setRelation('accountStatus', new AccountStatus([
            'status_code' => 'active',
            'status_name' => 'Active',
            'is_active' => true,
        ]));

        return $user;
    }

    private function seedCatalog(): void
    {
        DB::table('organizational_units')->insert([
            'organizational_unit_id' => 1,
            'unit_code' => 'PRES',
        ]);
        DB::table('academic_programs')->insert([
            'academic_program_id' => 18,
            'program_code' => 'MAN',
            'program_name' => 'Management',
        ]);
        DB::table('courses')->insert(array_map(static fn (int $courseId): array => [
            'course_id' => $courseId,
            'course_code' => 'COURSE-'.$courseId,
            'course_name' => 'Course '.$courseId,
            'credit_hours' => 3,
            'theoretical_hours' => 3,
            'practical_hours' => 0,
            'is_active' => true,
        ], range(1, 101)));
        DB::table('program_courses')->insert([
            [
                'program_course_id' => 1,
                'academic_program_id' => 18,
                'course_id' => 2,
                'course_type' => 'mandatory',
                'is_active' => true,
            ],
            [
                'program_course_id' => 2,
                'academic_program_id' => 18,
                'course_id' => 3,
                'course_type' => 'mandatory',
                'is_active' => true,
            ],
            [
                'program_course_id' => 3,
                'academic_program_id' => 18,
                'course_id' => 4,
                'course_type' => 'mandatory',
                'is_active' => true,
            ],
        ]);
        DB::table('academic_requirement_groups')->insert([
            'requirement_group_id' => 1,
            'academic_program_id' => 18,
            'group_code' => 'MAN-DEPARTMENT-MANDATORY',
            'group_name' => 'Department mandatory',
            'requirement_scope' => 'department',
            'requirement_type' => 'mandatory',
            'required_credit_hours' => 6,
            'is_active' => true,
        ]);
        DB::table('program_course_requirement_groups')->insert([
            [
                'program_course_requirement_group_id' => 1,
                'program_course_id' => 2,
                'requirement_group_id' => 1,
            ],
            [
                'program_course_requirement_group_id' => 2,
                'program_course_id' => 3,
                'requirement_group_id' => 1,
            ],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('organizational_units', function (Blueprint $table): void {
            $table->increments('organizational_unit_id');
            $table->string('unit_code');
        });
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->increments('user_access_scope_id');
            $table->integer('user_id');
            $table->string('scope_type');
            $table->integer('scope_id');
            $table->boolean('is_active');
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->integer('theoretical_hours');
            $table->integer('practical_hours');
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->string('program_code');
            $table->string('program_name');
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->increments('program_course_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->integer('academic_level_id')->nullable();
            $table->integer('recommended_semester_id')->nullable();
            $table->string('course_type');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('academic_requirement_groups', function (Blueprint $table): void {
            $table->increments('requirement_group_id');
            $table->integer('academic_program_id');
            $table->string('group_code');
            $table->string('group_name');
            $table->string('requirement_scope');
            $table->string('requirement_type');
            $table->integer('required_credit_hours');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('program_course_requirement_groups', function (Blueprint $table): void {
            $table->increments('program_course_requirement_group_id');
            $table->integer('program_course_id')->unique();
            $table->integer('requirement_group_id');
            $table->timestamps();
        });
    }
}
