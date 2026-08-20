<?php

namespace Tests\Feature;

use App\Support\SupplementaryExamOfferingGovernance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplementaryExamOfferingSchemaReadyRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_facade_class_does_not_declare_builder_inspectors(): void
    {
        $this->assertFalse(method_exists(Schema::class, 'getIndexes'));
        $builder = Schema::connection((string) config('database.default'));
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertTrue(method_exists($builder, 'getIndexes'));
    }

    public function test_schema_ready_returns_true_when_full_contract_exists(): void
    {
        $this->createFullContract();
        $this->assertTrue(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_identity_unique(): void
    {
        $this->createFullContract();
        Schema::table('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->dropUnique('uq_seo_period_program_course');
        });
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_source_foreign_key(): void
    {
        $this->createParents();
        $this->createOfferingsTable();
        $this->createSourcesTable(withOfferingFk: false);
        $this->createEventsTable();
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    private function createFullContract(): void
    {
        $this->createParents();
        $this->createOfferingsTable();
        $this->createSourcesTable();
        $this->createEventsTable();
    }

    private function createParents(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->integer('user_id')->autoIncrement();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->integer('academic_program_id')->autoIncrement();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->integer('course_id')->autoIncrement();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->integer('course_offering_id')->autoIncrement();
        });
        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->integer('supplementary_exam_period_id')->autoIncrement();
        });
    }

    private function createOfferingsTable(): void
    {
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->integer('supplementary_exam_offering_id')->autoIncrement();
            $table->integer('supplementary_exam_period_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->string('status', 16);
            $table->integer('opened_by_user_id');
            $table->dateTime('opened_at');
            $table->integer('closed_by_user_id')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->unique(['supplementary_exam_period_id', 'academic_program_id', 'course_id'], 'uq_seo_period_program_course');
            $table->index('supplementary_exam_period_id');
            $table->foreign('supplementary_exam_period_id')->references('supplementary_exam_period_id')->on('supplementary_exam_periods');
            $table->foreign('academic_program_id')->references('academic_program_id')->on('academic_programs');
            $table->foreign('course_id')->references('course_id')->on('courses');
            $table->foreign('opened_by_user_id')->references('user_id')->on('users');
            $table->foreign('closed_by_user_id')->references('user_id')->on('users');
        });
    }

    private function createSourcesTable(bool $withOfferingFk = true): void
    {
        Schema::create('supplementary_exam_offering_sources', function (Blueprint $table) use ($withOfferingFk): void {
            $table->integer('supplementary_exam_offering_source_id')->autoIncrement();
            $table->integer('supplementary_exam_offering_id');
            $table->integer('course_offering_id');
            $table->timestamp('created_at');
            $table->unique(['supplementary_exam_offering_id', 'course_offering_id'], 'uq_seos_offering_course_offering');
            if ($withOfferingFk) {
                $table->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings');
            }
            $table->foreign('course_offering_id')->references('course_offering_id')->on('course_offerings');
        });
    }

    private function createEventsTable(): void
    {
        Schema::create('supplementary_exam_offering_events', function (Blueprint $table): void {
            $table->integer('supplementary_exam_offering_event_id')->autoIncrement();
            $table->integer('supplementary_exam_offering_id');
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->integer('actor_user_id');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->index('supplementary_exam_offering_id');
            $table->index('actor_user_id');
            $table->index(['event_type', 'to_status']);
            $table->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings');
            $table->foreign('actor_user_id')->references('user_id')->on('users');
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('supplementary_exam_offering_events');
        Schema::dropIfExists('supplementary_exam_offering_sources');
        Schema::dropIfExists('supplementary_exam_offerings');
        Schema::dropIfExists('supplementary_exam_periods');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_programs');
        Schema::dropIfExists('users');
    }
}
