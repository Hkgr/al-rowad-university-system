<?php

namespace Tests\Feature;

use App\Support\SupplementaryExamOfferingGovernance;
use App\Support\SupplementaryExamPeriodGovernance;
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

    public function test_schema_ready_returns_true_when_full_phase1_and_phase2_exist(): void
    {
        $this->createFullContract();
        $this->assertTrue(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertTrue(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_phase2_identity_unique(): void
    {
        $this->createFullContract();
        Schema::table('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->dropUnique('uq_seo_period_program_course');
        });
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_source_foreign_key(): void
    {
        $this->createPhase1Contract();
        $this->createPhase2Parents();
        $this->createOfferingsTable();
        $this->createSourcesTable(withOfferingFk: false);
        $this->createEventsTable();
        $this->assertTrue(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_when_phase1_period_unique_missing(): void
    {
        $this->createFullContract();
        $this->assertTrue(SupplementaryExamOfferingGovernance::schemaReady());
        Schema::table('supplementary_exam_periods', function (Blueprint $table): void {
            $table->dropUnique('periods_year_semester_identity');
        });
        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_when_phase1_event_fk_missing(): void
    {
        $this->createUsersAndPeriods();
        $this->createPeriodEventsTable(withPeriodFk: false, withActorFk: true, withLookupIndex: true, withNotes: true);
        $this->createPhase2Parents();
        $this->createOfferingsTable();
        $this->createSourcesTable();
        $this->createEventsTable();
        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_when_phase1_event_index_missing(): void
    {
        $this->createUsersAndPeriods();
        $this->createPeriodEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: false, withNotes: true);
        $this->createPhase2Parents();
        $this->createOfferingsTable();
        $this->createSourcesTable();
        $this->createEventsTable();
        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_when_phase1_governance_column_malformed(): void
    {
        $this->createUsersAndPeriods(withDecisionNote: false);
        $this->createPeriodEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: true, withNotes: true);
        $this->createPhase2Parents();
        $this->createOfferingsTable();
        $this->createSourcesTable();
        $this->createEventsTable();
        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
        $this->assertFalse(SupplementaryExamOfferingGovernance::schemaReady());
    }

    private function createFullContract(): void
    {
        $this->createPhase1Contract();
        $this->createPhase2Parents();
        $this->createOfferingsTable();
        $this->createSourcesTable();
        $this->createEventsTable();
    }

    private function createPhase1Contract(): void
    {
        $this->createUsersAndPeriods();
        $this->createPeriodEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: true, withNotes: true);
    }

    private function createUsersAndPeriods(bool $withDecisionNote = true): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->integer('user_id')->autoIncrement();
        });
        Schema::create('supplementary_exam_periods', function (Blueprint $table) use ($withDecisionNote): void {
            $table->integer('supplementary_exam_period_id')->autoIncrement();
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status', 32);
            $table->integer('opened_by_user_id')->nullable();
            $table->dateTime('opened_at')->nullable();
            if ($withDecisionNote) {
                $table->text('decision_note')->nullable();
            }
            $table->unique(['academic_year_id', 'semester_id'], 'periods_year_semester_identity');
        });
    }

    private function createPeriodEventsTable(
        bool $withPeriodFk,
        bool $withActorFk,
        bool $withLookupIndex,
        bool $withNotes,
    ): void {
        Schema::create('supplementary_exam_period_events', function (Blueprint $table) use (
            $withPeriodFk,
            $withActorFk,
            $withLookupIndex,
            $withNotes,
        ): void {
            $table->integer('supplementary_exam_period_event_id')->autoIncrement();
            $table->integer('supplementary_exam_period_id');
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->integer('actor_user_id');
            if ($withNotes) {
                $table->text('notes')->nullable();
            }
            $table->timestamp('created_at');
            $table->index('supplementary_exam_period_id');
            $table->index('actor_user_id');
            if ($withLookupIndex) {
                $table->index(['event_type', 'to_status']);
            }
            if ($withPeriodFk) {
                $table->foreign('supplementary_exam_period_id')
                    ->references('supplementary_exam_period_id')
                    ->on('supplementary_exam_periods');
            }
            if ($withActorFk) {
                $table->foreign('actor_user_id')->references('user_id')->on('users');
            }
        });
    }

    private function createPhase2Parents(): void
    {
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->integer('academic_program_id')->autoIncrement();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->integer('course_id')->autoIncrement();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->integer('course_offering_id')->autoIncrement();
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
        Schema::dropIfExists('supplementary_exam_period_events');
        Schema::dropIfExists('supplementary_exam_periods');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_programs');
        Schema::dropIfExists('users');
    }
}
