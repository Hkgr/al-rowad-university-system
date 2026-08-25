<?php

namespace Tests\Feature;

use App\Exceptions\MinistryPlacementException;
use App\Models\MinistryPlacementRecord;
use App\Models\User;
use App\Services\MinistryPlacementProgramMatchingService;
use App\Support\MinistryPlacementAccess;
use App\Support\MinistryProgramMatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MinistryPlacementPhase2ServiceTest extends TestCase
{
    private MinistryPlacementProgramMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active', 'is_active' => 1]);
        DB::table('users')->insert(['user_id' => 7, 'username' => 'operator', 'account_status_id' => 1]);
        DB::table('ministry_placement_batches')->insert(['batch_id' => 1, 'batch_name' => 'Batch', 'academic_year_id' => 1]);
        $this->activeProgram(10, 'BUS', 'إدارة الأعمال');
        $this->activeProgram(11, 'LAW', 'الحقوق', departmentId: 11, collegeId: 11);
        $this->service = app(MinistryPlacementProgramMatchingService::class);
    }

    public function test_ministry_p2_01_to_04_authority_requires_assigned_permission_and_actual_scope(): void
    {
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'operator', 'is_active' => 1]);
        DB::table('permissions')->insert([
            ['permission_id' => 1, 'permission_code' => 'admissions.view', 'is_active' => 1],
            ['permission_id' => 2, 'permission_code' => 'admissions.manage', 'is_active' => 1],
        ]);
        DB::table('role_permissions')->insert([
            ['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1],
            ['role_permission_id' => 2, 'role_id' => 1, 'permission_id' => 2],
        ]);
        DB::table('user_roles')->insert(['user_role_id' => 1, 'user_id' => 7, 'role_id' => 1, 'is_active' => 1]);
        $actor = User::query()->findOrFail(7);
        $access = app(MinistryPlacementAccess::class);
        self::assertFalse($access->canView($actor));
        self::assertFalse($access->canManage($actor));

        DB::table('organizational_units')->insert(['organizational_unit_id' => 99, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 99, 'is_active' => 1]);
        self::assertTrue($access->canView($actor));
        self::assertTrue($access->canManage($actor));
    }

    public function test_ministry_p2_05_to_08_options_and_mutations_require_an_active_complete_hierarchy(): void
    {
        DB::table('academic_programs')->where('academic_program_id', 11)->update(['is_active' => 0]);
        $programs = $this->service->programs(['per_page' => 100]);
        self::assertSame([10], collect($programs->items())->pluck('academic_program_id')->all());

        $record = $this->record(1, 'إدارة الأعمال');
        foreach ([
            fn () => DB::table('academic_programs')->where('academic_program_id', 10)->update(['is_active' => 0]),
            fn () => DB::table('departments')->where('department_id', 10)->update(['is_active' => 0]),
            fn () => DB::table('colleges')->where('college_id', 10)->update(['is_active' => 0]),
        ] as $deactivate) {
            DB::table('academic_programs')->where('academic_program_id', 10)->update(['is_active' => 1]);
            DB::table('departments')->where('department_id', 10)->update(['is_active' => 1]);
            DB::table('colleges')->where('college_id', 10)->update(['is_active' => 1]);
            $deactivate();
            try {
                $this->service->match($record, 10, User::query()->findOrFail(7));
                self::fail('Inactive hierarchy must be rejected.');
            } catch (MinistryPlacementException $exception) {
                self::assertSame('ministry_placement_program_unavailable', $exception->errorCode);
            }
        }
    }

    public function test_ministry_p2_09_to_11_suggestions_are_read_only_exact_and_ambiguity_safe(): void
    {
        $matcher = app(MinistryProgramMatcher::class);
        $catalog = [
            ['academic_program_id' => 1, 'program_code' => 'BUS', 'program_name' => 'إدارة الأعمال'],
            ['academic_program_id' => 2, 'program_code' => 'BUS2', 'program_name' => 'إدارة الأعمال'],
        ];
        $before = DB::table('ministry_placement_records')->count();
        $ambiguous = $matcher->suggestions('إدارة الأعمال', $catalog);
        self::assertSame('ambiguous', $ambiguous['suggestion_status']);
        self::assertSame('EXACT', $ambiguous['match_tier']);
        self::assertSame($before, DB::table('ministry_placement_records')->count());

        $unique = $matcher->suggestions('برنامج الإجازة في الحقوق', [$catalog[0], ['academic_program_id' => 3, 'program_code' => 'LAW', 'program_name' => 'الحقوق']]);
        self::assertSame('unique', $unique['suggestion_status']);
        self::assertSame(3, $unique['suggestions'][0]['academic_program_id']);
    }

    public function test_ministry_p2_12_to_18_individual_match_rematch_unmatch_idempotency_and_locking(): void
    {
        $recordId = $this->record(1, 'إدارة الأعمال');
        $actor = User::query()->findOrFail(7);
        $matched = $this->service->match($recordId, 10, $actor);
        self::assertSame(10, (int) $matched->matched_academic_program_id);
        self::assertSame('program_matched', $matched->processing_status);
        self::assertSame(1, DB::table('user_activity_logs')->count());

        $this->service->match($recordId, 10, $actor);
        self::assertSame(1, DB::table('user_activity_logs')->count(), 'Same canonical match must not be re-audited.');

        $rematched = $this->service->match($recordId, 11, $actor);
        self::assertSame(11, (int) $rematched->matched_academic_program_id);
        $audit = json_decode(DB::table('user_activity_logs')->orderByDesc('activity_log_id')->value('description'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, $audit['previous_program_id']);
        self::assertSame(11, $audit['new_program_id']);

        $unmatched = $this->service->unmatch($recordId, $actor);
        self::assertNull($unmatched->matched_academic_program_id);
        self::assertSame('imported', $unmatched->processing_status);
        $auditCount = DB::table('user_activity_logs')->count();
        $this->service->unmatch($recordId, $actor);
        self::assertSame($auditCount, DB::table('user_activity_logs')->count());

        DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->update(['processing_status' => 'accepted']);
        $this->expectExceptionObject(MinistryPlacementException::recordLocked());
        $this->service->match($recordId, 10, $actor);
    }

    public function test_ministry_p2_19_to_23_bulk_updates_only_canonical_unmatched_and_stale_retry_fails(): void
    {
        $preference = 'برنامج الإجازة في إدارة الأعمال';
        $eligibleA = $this->record(1, $preference);
        $eligibleB = $this->record(2, $preference);
        $sameMatch = $this->record(3, $preference, 'program_matched', 10);
        $differentMatch = $this->record(4, $preference, 'program_matched', 11);
        $stale = $this->record(5, $preference, 'imported', 10);
        $locked = $this->record(6, $preference, 'accepted', 11, 55);
        $otherBatch = $this->record(7, $preference, batchId: 2);
        $key = app(MinistryProgramMatcher::class)->preferenceKey($preference);

        $result = $this->service->applyGroup(1, $key, 10, 2, User::query()->findOrFail(7));
        self::assertSame(2, $result['updated_count']);
        self::assertSame(2, $result['already_matched_count']);
        self::assertSame(1, $result['stale_match_count']);
        self::assertSame(1, $result['locked_count']);
        self::assertSame(10, (int) DB::table('ministry_placement_records')->where('placement_record_id', $eligibleA)->value('matched_academic_program_id'));
        self::assertSame(10, (int) DB::table('ministry_placement_records')->where('placement_record_id', $eligibleB)->value('matched_academic_program_id'));
        self::assertSame(10, (int) DB::table('ministry_placement_records')->where('placement_record_id', $sameMatch)->value('matched_academic_program_id'));
        self::assertSame(11, (int) DB::table('ministry_placement_records')->where('placement_record_id', $differentMatch)->value('matched_academic_program_id'));
        self::assertSame('imported', DB::table('ministry_placement_records')->where('placement_record_id', $stale)->value('processing_status'));
        self::assertSame('accepted', DB::table('ministry_placement_records')->where('placement_record_id', $locked)->value('processing_status'));
        self::assertNull(DB::table('ministry_placement_records')->where('placement_record_id', $otherBatch)->value('matched_academic_program_id'));
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'ministry_placement.program_match_bulk')->count());

        try {
            $this->service->applyGroup(1, $key, 10, 2, User::query()->findOrFail(7));
            self::fail('Old expected count must become stale.');
        } catch (MinistryPlacementException $exception) {
            self::assertSame('ministry_placement_group_stale', $exception->errorCode);
        }
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'ministry_placement.program_match_bulk')->count());
    }

    public function test_ministry_p2_24_and_25_audit_failure_rolls_back_individual_and_bulk(): void
    {
        $recordId = $this->record(1, 'إدارة الأعمال');
        Schema::drop('user_activity_logs');
        try {
            $this->service->match($recordId, 10, User::query()->findOrFail(7));
            self::fail('Missing audit storage must fail.');
        } catch (\Throwable) {
            self::assertNull(DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->value('matched_academic_program_id'));
        }

        Schema::create('user_activity_logs', fn (Blueprint $table) => $this->activityLogColumns($table));
        $bulkId = $this->record(2, 'الحقوق');
        Schema::drop('user_activity_logs');
        try {
            $this->service->applyGroup(1, app(MinistryProgramMatcher::class)->preferenceKey('الحقوق'), 11, 1, User::query()->findOrFail(7));
            self::fail('Bulk audit failure must roll back.');
        } catch (\Throwable) {
            self::assertNull(DB::table('ministry_placement_records')->where('placement_record_id', $bulkId)->value('matched_academic_program_id'));
        }
    }

    public function test_ministry_p2_26_state_classification_preserves_inactive_and_inconsistent_matches(): void
    {
        $matchedId = $this->record(1, 'إدارة الأعمال', 'program_matched', 10);
        $inconsistentId = $this->record(2, 'الحقوق', 'imported', 11);
        DB::table('colleges')->where('college_id', 10)->update(['is_active' => 0]);
        $records = MinistryPlacementRecord::query()->with('matchedAcademicProgram.department.college')->orderBy('placement_record_id')->get();
        self::assertSame('stale_match', $records->firstWhere('placement_record_id', $matchedId)->programMatchState());
        self::assertSame('stale_match', $records->firstWhere('placement_record_id', $inconsistentId)->programMatchState());
        self::assertSame(10, (int) $records->firstWhere('placement_record_id', $matchedId)->matched_academic_program_id);
    }

    public function test_suggestion_summary_query_count_is_independent_of_group_count(): void
    {
        $this->record(1, 'إدارة الأعمال');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->summary(1);
        $singleGroupQueries = count(DB::getQueryLog());

        foreach (range(2, 20) as $row) $this->record($row, 'رغبة مختلفة '.$row);
        DB::flushQueryLog();
        $this->service->summary(1);
        $manyGroupQueries = count(DB::getQueryLog());
        self::assertSame($singleGroupQueries, $manyGroupQueries, 'Program suggestions must not query once per group.');
    }

    private function activeProgram(int $id, string $code, string $name, int $departmentId = 10, int $collegeId = 10): void
    {
        DB::table('colleges')->insertOrIgnore(['college_id' => $collegeId, 'college_code' => 'C'.$collegeId, 'college_name' => 'College '.$collegeId, 'is_active' => 1]);
        DB::table('departments')->insertOrIgnore(['department_id' => $departmentId, 'college_id' => $collegeId, 'department_code' => 'D'.$departmentId, 'department_name' => 'Department '.$departmentId, 'is_active' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $departmentId, 'program_code' => $code, 'program_name' => $name, 'degree_level' => 'Bachelor', 'is_active' => 1]);
    }

    private function record(int $row, ?string $preference, string $status = 'imported', ?int $programId = null, ?int $applicantId = null, int $batchId = 1): int
    {
        if ($batchId !== 1) DB::table('ministry_placement_batches')->insertOrIgnore(['batch_id' => $batchId, 'batch_name' => 'Batch '.$batchId, 'academic_year_id' => 1]);
        DB::table('ministry_placement_records')->insert([
            'placement_record_id' => $row,
            'batch_id' => $batchId,
            'row_number' => $row,
            'first_name' => 'First',
            'last_name' => 'Last',
            'accepted_preference_text' => $preference,
            'matched_academic_program_id' => $programId,
            'applicant_id' => $applicantId,
            'processing_status' => $status,
        ]);

        return $row;
    }

    private function createSchema(): void
    {
        Schema::create('account_statuses', function (Blueprint $table): void { $table->increments('account_status_id'); $table->string('status_code'); $table->boolean('is_active'); });
        Schema::create('users', function (Blueprint $table): void { $table->increments('user_id'); $table->string('username'); $table->integer('account_status_id'); });
        Schema::create('roles', function (Blueprint $table): void { $table->increments('role_id'); $table->string('role_code'); $table->boolean('is_active'); });
        Schema::create('permissions', function (Blueprint $table): void { $table->increments('permission_id'); $table->string('permission_code'); $table->boolean('is_active'); });
        Schema::create('role_permissions', function (Blueprint $table): void { $table->increments('role_permission_id'); $table->integer('role_id'); $table->integer('permission_id'); });
        Schema::create('user_roles', function (Blueprint $table): void { $table->increments('user_role_id'); $table->integer('user_id'); $table->integer('role_id'); $table->boolean('is_active'); });
        Schema::create('organizational_units', function (Blueprint $table): void { $table->increments('organizational_unit_id'); $table->string('unit_code'); });
        Schema::create('user_access_scopes', function (Blueprint $table): void { $table->increments('user_access_scope_id'); $table->integer('user_id'); $table->string('scope_type'); $table->integer('scope_id'); $table->boolean('is_active'); });
        Schema::create('colleges', function (Blueprint $table): void { $table->increments('college_id'); $table->string('college_code'); $table->string('college_name'); $table->boolean('is_active'); });
        Schema::create('departments', function (Blueprint $table): void { $table->increments('department_id'); $table->integer('college_id'); $table->string('department_code'); $table->string('department_name'); $table->boolean('is_active'); });
        Schema::create('academic_programs', function (Blueprint $table): void { $table->increments('academic_program_id'); $table->integer('department_id'); $table->string('program_code'); $table->string('program_name'); $table->string('degree_level'); $table->boolean('is_active'); });
        Schema::create('ministry_placement_batches', function (Blueprint $table): void { $table->increments('batch_id'); $table->string('batch_name'); $table->integer('academic_year_id'); $table->timestamps(); });
        Schema::create('ministry_placement_records', function (Blueprint $table): void {
            $table->increments('placement_record_id'); $table->integer('batch_id'); $table->integer('row_number')->nullable();
            $table->string('first_name'); $table->string('last_name'); $table->string('accepted_preference_text')->nullable();
            $table->integer('matched_academic_program_id')->nullable(); $table->integer('applicant_id')->nullable();
            $table->string('processing_status')->default('imported'); $table->timestamps();
        });
        Schema::create('user_activity_logs', fn (Blueprint $table) => $this->activityLogColumns($table));
    }

    private function activityLogColumns(Blueprint $table): void
    {
        $table->bigIncrements('activity_log_id'); $table->integer('user_id'); $table->string('module_code')->nullable();
        $table->string('action_code')->nullable(); $table->text('description')->nullable(); $table->timestamp('created_at')->nullable();
    }
}
