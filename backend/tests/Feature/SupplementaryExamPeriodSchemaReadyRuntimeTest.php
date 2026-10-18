<?php

namespace Tests\Feature;

use App\Support\SupplementaryExamPeriodGovernance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runtime proof that schemaReady() inspects the Schema Builder, not Schema::class.
 */
class SupplementaryExamPeriodSchemaReadyRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropGovernanceTables();
    }

    protected function tearDown(): void
    {
        $this->dropGovernanceTables();
        parent::tearDown();
    }

    public function test_facade_class_does_not_declare_builder_inspectors(): void
    {
        $this->assertFalse(method_exists(Schema::class, 'getIndexes'));
        $this->assertFalse(method_exists(Schema::class, 'getForeignKeys'));
        $this->assertFalse(method_exists(Schema::class, 'getColumns'));

        $builder = Schema::connection((string) config('database.default'));
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertTrue(method_exists($builder, 'getIndexes'));
        $this->assertTrue(method_exists($builder, 'getForeignKeys'));
        $this->assertTrue(method_exists($builder, 'getColumns'));
    }

    public function test_schema_ready_returns_true_when_full_contract_exists(): void
    {
        $this->createFullContract();

        $this->assertTrue(SupplementaryExamPeriodGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_identity_unique(): void
    {
        $this->createFullContract();
        $this->assertTrue(SupplementaryExamPeriodGovernance::schemaReady());

        Schema::table('supplementary_exam_periods', function (Blueprint $table): void {
            $table->dropUnique('periods_year_semester_identity');
        });

        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_required_event_foreign_key(): void
    {
        $this->createUsersAndPeriods();
        $this->createEventsTable(withPeriodFk: false, withActorFk: true, withLookupIndex: true, withNotes: true);

        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_required_event_index(): void
    {
        $this->createUsersAndPeriods();
        $this->createEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: false, withNotes: true);

        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
    }

    public function test_schema_ready_is_false_without_required_event_column(): void
    {
        $this->createUsersAndPeriods();
        $this->createEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: true, withNotes: false);

        $this->assertFalse(SupplementaryExamPeriodGovernance::schemaReady());
    }

    private function createFullContract(): void
    {
        $this->createUsersAndPeriods();
        $this->createEventsTable(withPeriodFk: true, withActorFk: true, withLookupIndex: true, withNotes: true);
    }

    private function createUsersAndPeriods(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->integer('user_id')->autoIncrement();
        });

        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->integer('supplementary_exam_period_id')->autoIncrement();
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status', 32);
            $table->integer('opened_by_user_id')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unique(['academic_year_id', 'semester_id'], 'periods_year_semester_identity');
        });
    }

    private function createEventsTable(
        bool $withPeriodFk,
        bool $withActorFk,
        bool $withLookupIndex,
        bool $withNotes,
    ): void {
        Schema::dropIfExists('supplementary_exam_period_events');
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
                $table->foreign('actor_user_id')
                    ->references('user_id')
                    ->on('users');
            }
        });
    }

    private function dropGovernanceTables(): void
    {
        Schema::dropIfExists('supplementary_exam_period_events');
        Schema::dropIfExists('supplementary_exam_periods');
        Schema::dropIfExists('users');
    }
}
