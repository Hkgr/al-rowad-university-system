<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\ProgramCourse;
use App\Services\CourseOfferingContextService;
use App\Services\DataScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseOfferingAdvisoryMetadataBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedOperationalContext();
    }

    public function test_offer_adv_03_and_04_actual_semester_may_differ_without_rewriting_advice(): void
    {
        $programCourse = ProgramCourse::query()->create([
            'program_course_id' => 1,
            'academic_program_id' => 1,
            'course_id' => 1,
            'academic_level_id' => 3,
            'recommended_semester_id' => 1,
            'course_type' => 'mandatory',
            'is_active' => true,
        ]);

        $context = $this->service()->resolveFromProgramCourse($programCourse, 1, 2);
        $offering = $this->service()->createOffering($context, [
            'capacity' => 40,
            'available_seats' => 40,
        ]);

        self::assertSame(2, (int) $context->semester->semester_id);
        self::assertSame(2, (int) $offering->semester_id);
        self::assertSame(1, (int) $offering->academic_year_id);
        self::assertSame(1, (int) $offering->academic_program_id);
        self::assertSame('closed', $offering->status);

        $programCourse->refresh();
        self::assertSame(3, (int) $programCourse->academic_level_id);
        self::assertSame(1, (int) $programCourse->recommended_semester_id);
    }

    public function test_offer_adv_13_null_advisory_metadata_does_not_block_actual_offering(): void
    {
        DB::table('courses')->insert([
            'course_id' => 2,
            'course_code' => 'GEN500',
            'course_name' => 'مقرر مرن',
            'credit_hours' => 3,
            'theoretical_hours' => 3,
            'practical_hours' => 0,
            'is_active' => true,
        ]);
        $programCourse = ProgramCourse::query()->create([
            'program_course_id' => 2,
            'academic_program_id' => 1,
            'course_id' => 2,
            'academic_level_id' => null,
            'recommended_semester_id' => null,
            'course_type' => 'elective',
            'is_active' => true,
        ]);

        $context = $this->service()->resolveFromProgramCourse($programCourse, 1, 2);
        $offering = $this->service()->createOffering($context, [
            'capacity' => 25,
            'available_seats' => 25,
        ]);

        self::assertSame(2, (int) $offering->course_id);
        self::assertSame(2, (int) $offering->semester_id);
        self::assertNull($programCourse->fresh()->academic_level_id);
        self::assertNull($programCourse->fresh()->recommended_semester_id);
        self::assertSame(1, CourseOffering::query()->where('course_id', 2)->count());
    }

    private function service(): CourseOfferingContextService
    {
        return new CourseOfferingContextService($this->createMock(DataScopeService::class));
    }

    private function seedOperationalContext(): void
    {
        DB::table('colleges')->insert([
            'college_id' => 1,
            'college_code' => 'BUS',
            'college_name' => 'كلية إدارة الأعمال',
            'is_active' => true,
        ]);
        DB::table('departments')->insert([
            'department_id' => 1,
            'college_id' => 1,
            'department_code' => 'FIN',
            'department_name' => 'قسم المالية',
            'is_active' => true,
        ]);
        DB::table('academic_programs')->insert([
            'academic_program_id' => 1,
            'department_id' => 1,
            'program_code' => 'FIN',
            'program_name' => 'الإدارة المالية',
            'is_active' => true,
        ]);
        DB::table('courses')->insert([
            'course_id' => 1,
            'course_code' => 'FMF321',
            'course_name' => 'محاسبة متقدمة',
            'credit_hours' => 3,
            'theoretical_hours' => 3,
            'practical_hours' => 0,
            'is_active' => true,
        ]);
        DB::table('academic_years')->insert([
            'academic_year_id' => 1,
            'year_name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'is_active' => true,
        ]);
        DB::table('semesters')->insert([
            ['semester_id' => 1, 'semester_code' => 'first', 'semester_name' => 'الفصل الأول', 'semester_order' => 1, 'is_active' => true],
            ['semester_id' => 2, 'semester_code' => 'second', 'semester_name' => 'الفصل الثاني', 'semester_order' => 2, 'is_active' => true],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('colleges', function (Blueprint $table): void {
            $table->increments('college_id');
            $table->string('college_code');
            $table->string('college_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id');
            $table->string('department_code');
            $table->string('department_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->integer('department_id');
            $table->string('program_code');
            $table->string('program_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->integer('theoretical_hours')->default(0);
            $table->integer('practical_hours')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('year_name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_code');
            $table->string('semester_name');
            $table->integer('semester_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->increments('program_course_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->integer('academic_level_id')->nullable();
            $table->integer('recommended_semester_id')->nullable();
            $table->string('course_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('department_id')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->integer('faculty_member_id')->nullable();
            $table->integer('capacity');
            $table->integer('available_seats');
            $table->string('status');
            $table->timestamps();
            $table->unique(
                ['course_id', 'academic_program_id', 'academic_year_id', 'semester_id'],
                CourseOfferingContextService::UNIQUE_INDEX,
            );
        });
    }
}
