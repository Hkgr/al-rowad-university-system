<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MinistryPlacementReconciliationService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MinistryPlacementPhase5ReconciliationTest extends TestCase
{
    private MinistryPlacementReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedReferenceData();
        $this->service = app(MinistryPlacementReconciliationService::class);
    }

    public function test_ministry_p5_01_to_05_authorization_get_only_and_strict_queries(): void
    {
        $actor = User::query()->findOrFail(7);
        self::assertFalse(app(MinistryPlacementAccess::class)->canView($actor));
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-reconciliation')->assertForbidden();

        $this->grantViewAccess();
        self::assertTrue(app(MinistryPlacementAccess::class)->canView($actor));
        $this->record(1, 1, '00000000001', 'imported');
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-reconciliation?per_page=1')
            ->assertOk()->assertJsonPath('data.production_gate', 'READY');
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/reconciliation')
            ->assertOk()->assertJsonPath('data.batch_id', 1);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-reconciliation?sort=name')->assertStatus(422);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/reconciliation?batch_id=1')->assertStatus(422);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-reconciliation?per_page=101')->assertStatus(422);
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ministry-placement-reconciliation')->assertStatus(405);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-academic-years')->assertForbidden();
        $this->grantManageAccess();
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-academic-years')
            ->assertOk()->assertJsonPath('data.0.academic_year_id', 1);
    }

    public function test_ministry_p5_06_to_16_clean_states_and_noncanonical_accepted_fail_closed(): void
    {
        $this->record(1, 1, '00000000001', 'imported');
        $this->record(2, 1, '00000000002', 'program_matched', 10);
        $this->pendingChain(3, 'applicant_created');
        $this->pendingChain(4, 'documents_pending');
        $this->terminalChain(5, 1, '00000000005', 'enrolled');
        $this->terminalChain(6, 1, '00000000006', 'rejected');
        $this->terminalChain(7, 1, '00000000007', 'enrolled', processingStatus: 'accepted');

        $payload = $this->service->globalSummary(['per_page' => 100]);
        $states = collect($payload['records'])->keyBy('placement_record_id');
        self::assertSame('imported', $states[1]['pipeline_state']);
        self::assertSame('matched', $states[2]['pipeline_state']);
        self::assertSame('applicant_pending', $states[3]['pipeline_state']);
        self::assertSame('documents_pending', $states[4]['pipeline_state']);
        self::assertSame('enrolled', $states[5]['pipeline_state']);
        self::assertSame('rejected', $states[6]['pipeline_state']);
        self::assertSame('inconsistent', $states[7]['pipeline_state']);
        self::assertSame('blocked', $states[7]['reconciliation_severity']);
        self::assertContains('ministry_state_chain_mismatch', array_column($states[7]['issues'], 'code'));
        self::assertSame('BLOCKED', $payload['production_gate']);
    }

    public function test_ministry_p5_17_to_25_exact_application_provenance_student_and_history_rules(): void
    {
        $this->pendingChain(1, 'applicant_created');
        DB::table('admission_applications')->where('admission_application_id', 2001)->update(['decision_status' => 'invalid']);
        $this->pendingChain(2, 'documents_pending');
        DB::table('admission_applications')->where('admission_application_id', 2002)->update(['decision_date' => '2026-08-29']);
        $this->terminalChain(3, 1, '00000000003', 'enrolled');
        DB::table('students')->where('student_id', 3003)->update(['deleted_at' => '2026-08-29 00:00:00']);
        $this->terminalChain(4, 1, '00000000004', 'enrolled');
        DB::table('admission_applications')->insert($this->applicationRow(9004, 1004, 10, 1, 'accepted'));
        $this->terminalChain(5, 1, '00000000005', 'rejected');
        $this->terminalChain(6, 1, '00000000006', 'enrolled', academicLevelId: 2, studentStatusId: 2);
        $this->terminalChain(7, 1, '00000000007', 'enrolled', academicLevelId: 999, studentStatusId: 999);
        $this->terminalChain(8, 1, '00000000008', 'enrolled');
        DB::table('applicants')->where('applicant_id', 1008)->update(['first_name' => 'Applicant profile']);
        DB::table('students')->where('student_id', 3008)->update(['first_name' => 'Different Student profile']);
        DB::table('academic_programs')->where('academic_program_id', 10)->update(['is_active' => 0]);

        $records = collect($this->service->globalSummary(['per_page' => 100])['records'])->keyBy('placement_record_id');
        self::assertContains('admission_decision_status_unsupported', array_column($records[1]['issues'], 'code'));
        self::assertContains('pending_decision_has_provenance', array_column($records[2]['issues'], 'code'));
        self::assertContains('student_soft_deleted', array_column($records[3]['issues'], 'code'));
        self::assertContains('expected_application_ambiguous', array_column($records[4]['issues'], 'code'));
        self::assertSame('rejected', $records[5]['pipeline_state']);
        self::assertSame('warning', $records[5]['reconciliation_severity']);
        self::assertContains('historical_program_hierarchy_inactive', array_column($records[5]['issues'], 'code'));
        self::assertSame('enrolled', $records[6]['pipeline_state']);
        self::assertContains('student_academic_level_inactive', array_column($records[6]['issues'], 'code'));
        self::assertContains('student_status_inactive', array_column($records[6]['issues'], 'code'));
        self::assertSame('blocked', $records[7]['reconciliation_severity']);
        self::assertContains('student_academic_level_missing', array_column($records[7]['issues'], 'code'));
        self::assertContains('student_status_missing', array_column($records[7]['issues'], 'code'));
        self::assertSame('enrolled', $records[8]['pipeline_state'], 'Applicant/Student profile differences are not reconciliation blockers.');
    }

    public function test_ministry_p5_26_to_31_terminal_and_nonterminal_identity_conflict_semantics(): void
    {
        $this->terminalChain(1, 1, '٠٠١٢٣٤٥٦٧٨٩', 'enrolled');
        $this->record(2, 2, "\u{00A0}00123456789 ", 'program_matched', 10);

        $global = $this->service->globalSummary(['per_page' => 100]);
        $records = collect($global['records'])->keyBy('placement_record_id');
        self::assertSame('warning', $records[1]['reconciliation_severity']);
        self::assertContains('identity_conflict_terminal_record', array_column($records[1]['issues'], 'code'));
        self::assertSame('blocked', $records[2]['reconciliation_severity']);
        self::assertContains('identity_conflict', array_column($records[2]['issues'], 'code'));
        self::assertSame('BLOCKED', $global['production_gate']);
        self::assertSame('BLOCKED', $this->service->batchSummary(2, [])['production_gate']);
    }

    public function test_ministry_p5_32_to_39_every_multiple_terminal_identity_variant_blocks_all_terminals(): void
    {
        $this->terminalChain(1, 1, '00000000111', 'enrolled');
        $this->terminalChain(2, 2, '٠٠٠٠٠٠٠٠١١١', 'enrolled');
        $this->terminalChain(3, 1, '00000000222', 'enrolled');
        $this->terminalChain(4, 2, '٠٠٠٠٠٠٠٠٢٢٢', 'rejected');
        $this->terminalChain(5, 1, '00000000333', 'rejected');
        $this->terminalChain(6, 2, '٠٠٠٠٠٠٠٠٣٣٣', 'rejected');

        $global = $this->service->globalSummary(['per_page' => 100]);
        foreach ($global['records'] as $record) {
            self::assertSame('blocked', $record['reconciliation_severity']);
            self::assertSame('inconsistent', $record['pipeline_state']);
            self::assertContains('identity_conflict_multiple_terminal_records', array_column($record['issues'], 'code'));
        }
        self::assertSame('BLOCKED', $global['production_gate']);
        self::assertSame('BLOCKED', $this->service->batchSummary(1, [])['production_gate']);
        self::assertSame('BLOCKED', $this->service->batchSummary(2, [])['production_gate']);
    }

    public function test_ministry_p5_40_to_46_filters_do_not_change_metrics_gate_or_checksum_and_projection_is_safe(): void
    {
        $this->record(1, 1, '00000000001', 'imported');
        $this->record(2, 1, null, 'program_matched', 10);
        $this->terminalChain(3, 1, " \u{00A0} ", 'rejected');
        $complete = $this->service->globalSummary(['per_page' => 100]);
        $filtered = $this->service->globalSummary(['severity' => 'blocked', 'page' => 1, 'per_page' => 1]);

        self::assertSame($complete['metrics'], $filtered['metrics']);
        self::assertSame($complete['production_gate'], $filtered['production_gate']);
        self::assertSame($complete['reconciliation_checksum'], $filtered['reconciliation_checksum']);
        self::assertSame(1, count($filtered['records']));
        $terminalMissingIdentity = collect($complete['records'])->firstWhere('placement_record_id', 3);
        self::assertSame('warning', $terminalMissingIdentity['reconciliation_severity']);
        self::assertContains('identity_missing_terminal_record', array_column($terminalMissingIdentity['issues'], 'code'));
        $json = json_encode($filtered, JSON_THROW_ON_ERROR);
        foreach (['first_name', 'last_name', 'phone_number', 'email', 'date_of_birth', 'national_civil_id'] as $pii) {
            self::assertStringNotContainsString($pii, $json);
        }
        self::assertSame([], array_diff(array_keys($filtered['records'][0]), [
            'placement_record_id', 'batch_id', 'academic_year_id', 'row_number', 'processing_status',
            'matched_academic_program_id', 'issues', 'applicant', 'admission_application', 'student',
            'pipeline_state', 'reconciliation_severity',
        ]));
    }

    public function test_ministry_p5_47_to_50_queries_are_bounded_audit_is_informational_and_service_writes_nothing(): void
    {
        $this->orphanChain(1, true);
        DB::table('user_activity_logs')->insert(['activity_log_id' => 1, 'user_id' => 7, 'module_code' => 'admissions', 'action_code' => 'ministry_placement.program_match_bulk', 'description' => 'sensitive-not-returned']);
        $smallBefore = $this->tableCounts();
        $smallQueries = $this->queryCount(fn () => $this->service->globalSummary([]));
        self::assertSame($smallBefore, $this->tableCounts());
        foreach (range(2, 31) as $id) $this->orphanChain($id, true, $id % 2 + 1);
        $largeBefore = $this->tableCounts();
        $largeQueries = $this->queryCount(fn () => $this->service->globalSummary([]));
        $payload = $this->service->globalSummary([]);

        self::assertSame($smallQueries, $largeQueries, 'Query count must not grow with record/group count.');
        self::assertSame($largeBefore, $this->tableCounts());
        self::assertSame(1, $payload['audit_coverage']['ministry_placement.program_match_bulk']);
        self::assertStringNotContainsString('sensitive-not-returned', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_ministry_p5_51_to_56_orphan_expected_chains_are_reported_but_never_adopted_or_written(): void
    {
        $this->orphanChain(50, false);
        $this->orphanChain(51, true);
        $before = $this->tableCounts();

        $payload = $this->service->globalSummary(['per_page' => 100]);
        $records = collect($payload['records'])->keyBy('placement_record_id');
        self::assertSame($before, $this->tableCounts());

        foreach ([50, 51] as $id) {
            self::assertSame('blocked', $records[$id]['reconciliation_severity']);
            self::assertSame('inconsistent', $records[$id]['pipeline_state']);
            self::assertNull($records[$id]['applicant']);
            self::assertNull($records[$id]['admission_application']);
            self::assertNull($records[$id]['student']);
            self::assertContains('orphan_expected_applicant', array_column($records[$id]['issues'], 'code'));
            self::assertContains('orphan_expected_application', array_column($records[$id]['issues'], 'code'));
        }
        self::assertNotContains('orphan_expected_student', array_column($records[50]['issues'], 'code'));
        self::assertContains('orphan_expected_student', array_column($records[51]['issues'], 'code'));
        $studentIssue = collect($records[51]['issues'])->firstWhere('code', 'orphan_expected_student');
        self::assertSame(4051, $studentIssue['related_applicant_id']);
        self::assertSame(5051, $studentIssue['related_application_id']);
        self::assertSame(6051, $studentIssue['related_student_id']);
    }

    public function test_ministry_p5_57_to_58_checksum_tracks_safe_issue_relationship_ids(): void
    {
        $this->record(60, 1, '00000000060', 'program_matched', 10);
        DB::table('applicants')->insert(['applicant_id' => 900, 'applicant_number' => 'MP-R60']);
        $before = $this->service->globalSummary(['per_page' => 100]);
        $beforeCodes = collect($before['records'])->firstWhere('placement_record_id', 60)['issues'];

        DB::table('applicants')->where('applicant_id', 900)->update(['applicant_id' => 901]);
        $after = $this->service->globalSummary(['per_page' => 100]);
        $afterCodes = collect($after['records'])->firstWhere('placement_record_id', 60)['issues'];

        self::assertSame(array_column($beforeCodes, 'code'), array_column($afterCodes, 'code'));
        self::assertNotSame($before['reconciliation_checksum'], $after['reconciliation_checksum']);
    }

    private function orphanChain(int $id, bool $withStudent, int $batchId = 1): void
    {
        $applicantId = 4000 + $id;
        $applicationId = 5000 + $id;
        $this->record($id, $batchId, str_pad((string) $id, 11, '0', STR_PAD_LEFT), 'program_matched', 10);
        DB::table('applicants')->insert(['applicant_id' => $applicantId, 'applicant_number' => 'MP-R'.$id]);
        DB::table('admission_applications')->insert($this->applicationRow($applicationId, $applicantId, 10, 1, 'pending'));
        if ($withStudent) {
            DB::table('students')->insert([
                'student_id' => 6000 + $id,
                'student_number' => 'ORPHAN-'.$id,
                'admission_application_id' => $applicationId,
                'academic_program_id' => 10,
                'current_academic_level_id' => 1,
                'student_status_id' => 1,
            ]);
        }
    }

    private function pendingChain(int $id, string $processing): void
    {
        $this->record($id, 1, str_pad((string) $id, 11, '0', STR_PAD_LEFT), $processing, 10, 1000 + $id);
        DB::table('applicants')->insert(['applicant_id' => 1000 + $id, 'applicant_number' => 'MP-R'.$id]);
        DB::table('admission_applications')->insert($this->applicationRow(2000 + $id, 1000 + $id, 10, 1, 'pending'));
    }

    private function terminalChain(int $id, int $batchId, string $identity, string $terminal, ?string $processingStatus = null, int $academicLevelId = 1, int $studentStatusId = 1): void
    {
        $applicantId = 1000 + $id;
        $applicationId = 2000 + $id;
        $this->record($id, $batchId, $identity, $processingStatus ?? $terminal, 10, $applicantId);
        DB::table('applicants')->insert(['applicant_id' => $applicantId, 'applicant_number' => 'MP-R'.$id]);
        DB::table('admission_applications')->insert($this->applicationRow($applicationId, $applicantId, 10, 1, $terminal === 'enrolled' ? 'accepted' : 'rejected'));
        if ($terminal === 'enrolled') {
            DB::table('students')->insert([
                'student_id' => 3000 + $id, 'student_number' => 'STU-'.$id,
                'admission_application_id' => $applicationId, 'academic_program_id' => 10,
                'current_academic_level_id' => $academicLevelId, 'student_status_id' => $studentStatusId,
            ]);
        }
    }

    private function applicationRow(int $id, int $applicantId, int $programId, int $yearId, string $decision): array
    {
        return [
            'admission_application_id' => $id, 'applicant_id' => $applicantId,
            'academic_program_id' => $programId, 'academic_year_id' => $yearId,
            'decision_status' => $decision,
            'decision_date' => $decision === 'pending' ? null : '2026-08-29',
            'decided_by_user_id' => $decision === 'pending' ? null : 7,
        ];
    }

    private function record(int $id, int $batchId, ?string $identity, string $status, ?int $programId = null, ?int $applicantId = null): void
    {
        DB::table('ministry_placement_records')->insert([
            'placement_record_id' => $id, 'batch_id' => $batchId, 'row_number' => $id + 2,
            'national_civil_id' => $identity, 'processing_status' => $status,
            'matched_academic_program_id' => $programId, 'applicant_id' => $applicantId,
        ]);
    }

    private function grantViewAccess(): void
    {
        DB::table('permissions')->insert(['permission_id' => 1, 'permission_code' => 'admissions.view', 'is_active' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'admissions_operator', 'is_active' => 1]);
        DB::table('role_permissions')->insert(['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1]);
        DB::table('user_roles')->insert(['user_role_id' => 1, 'user_id' => 7, 'role_id' => 1, 'is_active' => 1]);
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES', 'is_active' => 1]);
        DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => 1]);
    }

    private function grantManageAccess(): void
    {
        DB::table('permissions')->insert(['permission_id' => 2, 'permission_code' => 'admissions.manage', 'is_active' => 1]);
        DB::table('role_permissions')->insert(['role_permission_id' => 2, 'role_id' => 1, 'permission_id' => 2]);
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function tableCounts(): array
    {
        return collect(['ministry_placement_records', 'ministry_placement_batches', 'applicants', 'admission_applications', 'students', 'user_activity_logs'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function seedReferenceData(): void
    {
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active', 'is_active' => 1]);
        DB::table('users')->insert(['user_id' => 7, 'username' => 'operator', 'account_status_id' => 1]);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('ministry_placement_batches')->insert([
            ['batch_id' => 1, 'batch_name' => 'Batch 1', 'academic_year_id' => 1],
            ['batch_id' => 2, 'batch_name' => 'Batch 2', 'academic_year_id' => 1],
        ]);
        DB::table('colleges')->insert(['college_id' => 10, 'is_active' => 1]);
        DB::table('departments')->insert(['department_id' => 10, 'college_id' => 10, 'is_active' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => 10, 'department_id' => 10, 'is_active' => 1]);
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'is_active' => 1]);
        DB::table('academic_levels')->insert(['academic_level_id' => 2, 'is_active' => 0]);
        DB::table('student_statuses')->insert(['student_status_id' => 1, 'is_active' => 1]);
        DB::table('student_statuses')->insert(['student_status_id' => 2, 'is_active' => 0]);
    }

    private function createSchema(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void { $table->integer('academic_year_id')->primary(); $table->string('year_name')->nullable(); });
        Schema::create('ministry_placement_batches', function (Blueprint $table): void { $table->integer('batch_id')->primary(); $table->string('batch_name'); $table->integer('academic_year_id'); $table->timestamps(); });
        Schema::create('ministry_placement_records', function (Blueprint $table): void { $table->integer('placement_record_id')->primary(); $table->integer('batch_id'); $table->integer('row_number')->nullable(); $table->string('national_civil_id')->nullable(); $table->string('processing_status'); $table->integer('matched_academic_program_id')->nullable(); $table->integer('applicant_id')->nullable(); $table->timestamps(); });
        Schema::create('applicants', function (Blueprint $table): void { $table->integer('applicant_id')->primary(); $table->string('applicant_number'); $table->string('first_name')->nullable(); $table->timestamps(); });
        Schema::create('admission_applications', function (Blueprint $table): void { $table->integer('admission_application_id')->primary(); $table->integer('applicant_id'); $table->integer('academic_program_id'); $table->integer('academic_year_id'); $table->string('decision_status'); $table->date('decision_date')->nullable(); $table->integer('decided_by_user_id')->nullable(); $table->timestamps(); });
        Schema::create('students', function (Blueprint $table): void { $table->integer('student_id')->primary(); $table->string('student_number'); $table->string('first_name')->nullable(); $table->integer('admission_application_id')->nullable(); $table->integer('academic_program_id'); $table->integer('current_academic_level_id'); $table->integer('student_status_id'); $table->timestamp('deleted_at')->nullable(); $table->timestamps(); });
        Schema::create('colleges', function (Blueprint $table): void { $table->integer('college_id')->primary(); $table->boolean('is_active'); });
        Schema::create('departments', function (Blueprint $table): void { $table->integer('department_id')->primary(); $table->integer('college_id'); $table->boolean('is_active'); });
        Schema::create('academic_programs', function (Blueprint $table): void { $table->integer('academic_program_id')->primary(); $table->integer('department_id'); $table->boolean('is_active'); });
        Schema::create('academic_levels', function (Blueprint $table): void { $table->integer('academic_level_id')->primary(); $table->boolean('is_active'); });
        Schema::create('student_statuses', function (Blueprint $table): void { $table->integer('student_status_id')->primary(); $table->boolean('is_active'); });
        Schema::create('account_statuses', function (Blueprint $table): void { $table->integer('account_status_id')->primary(); $table->string('status_code'); $table->boolean('is_active'); });
        Schema::create('users', function (Blueprint $table): void { $table->integer('user_id')->primary(); $table->string('username'); $table->integer('account_status_id')->nullable(); $table->string('password_hash')->nullable(); $table->integer('student_id')->nullable(); $table->integer('employee_id')->nullable(); $table->timestamps(); });
        Schema::create('permissions', function (Blueprint $table): void { $table->integer('permission_id')->primary(); $table->string('permission_code'); $table->boolean('is_active'); });
        Schema::create('roles', function (Blueprint $table): void { $table->integer('role_id')->primary(); $table->string('role_code'); $table->boolean('is_active'); });
        Schema::create('role_permissions', function (Blueprint $table): void { $table->integer('role_permission_id')->primary(); $table->integer('role_id'); $table->integer('permission_id'); });
        Schema::create('user_roles', function (Blueprint $table): void { $table->integer('user_role_id')->primary(); $table->integer('user_id'); $table->integer('role_id'); $table->boolean('is_active'); });
        Schema::create('organizational_units', function (Blueprint $table): void { $table->integer('organizational_unit_id')->primary(); $table->string('unit_code'); $table->boolean('is_active'); });
        Schema::create('user_access_scopes', function (Blueprint $table): void { $table->integer('user_access_scope_id')->primary(); $table->integer('user_id'); $table->string('scope_type'); $table->integer('scope_id'); $table->boolean('is_active'); });
        Schema::create('user_activity_logs', function (Blueprint $table): void { $table->integer('activity_log_id')->primary(); $table->integer('user_id')->nullable(); $table->string('module_code'); $table->string('action_code'); $table->text('description')->nullable(); $table->timestamp('created_at')->nullable(); });
    }
}
