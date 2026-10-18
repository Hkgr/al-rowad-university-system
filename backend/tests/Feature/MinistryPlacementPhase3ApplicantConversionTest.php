<?php

namespace Tests\Feature;

use App\Exceptions\MinistryPlacementException;
use App\Models\User;
use App\Services\MinistryPlacementApplicantConversionService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MinistryPlacementPhase3ApplicantConversionTest extends TestCase
{
    private MinistryPlacementApplicantConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active', 'is_active' => 1]);
        DB::table('users')->insert(['user_id' => 7, 'username' => 'operator', 'account_status_id' => 1]);
        DB::table('ministry_placement_batches')->insert(['batch_id' => 1, 'batch_name' => 'Batch 1', 'academic_year_id' => 1]);
        $this->activeProgram(10);
        $this->activeProgram(11);
        $this->service = app(MinistryPlacementApplicantConversionService::class);
    }

    public function test_ministry_p3_01_to_04_authority_requires_assigned_permission_and_actual_university_scope(): void
    {
        $this->grantPermissions(withScope: false);
        $actor = User::query()->findOrFail(7);
        $access = app(MinistryPlacementAccess::class);
        self::assertFalse($access->canView($actor));
        self::assertFalse($access->canManage($actor));
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/applicant-conversion')->assertForbidden();

        DB::table('organizational_units')->insert(['organizational_unit_id' => 99, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 99, 'is_active' => 1]);
        self::assertTrue($access->canView($actor));
        self::assertTrue($access->canManage($actor));
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/applicant-conversion')->assertOk();
    }

    public function test_view_only_operator_can_read_but_cannot_convert(): void
    {
        $this->grantPermissions(withManage: false);
        $actor = User::query()->findOrFail(7);
        $recordId = $this->record(1, '00123456789');

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/applicant-conversion')->assertOk();
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/ministry-placement-records/'.$recordId.'/convert-to-applicant')
            ->assertForbidden();
        self::assertSame(0, DB::table('applicants')->count());
    }

    public function test_individual_endpoint_rejects_every_non_empty_payload_before_conversion(): void
    {
        $this->grantPermissions();
        $recordId = $this->record(1, '00123456789');

        $response = $this->actingAs(User::query()->findOrFail(7), 'sanctum')
            ->postJson('/api/v1/ministry-placement-records/'.$recordId.'/convert-to-applicant', [
                'applicant_id' => 900,
                'academic_program_id' => 99,
                'academic_year_id' => 99,
                'applicant_number' => 'CLIENT-VALUE',
                'decision_status' => 'accepted',
                'decided_by_user_id' => 7,
            ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'ministry_placement_conversion_payload_not_allowed');
        self::assertSame(0, DB::table('applicants')->count());
        self::assertSame(0, DB::table('admission_applications')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_bulk_endpoint_accepts_only_its_two_concurrency_fields_before_conversion(): void
    {
        $this->grantPermissions();
        $recordId = $this->record(1, '00123456789');
        $actor = User::query()->findOrFail(7);
        $summary = $this->service->summary(1);
        $legalPayload = [
            'expected_eligible_count' => $summary['eligible_count'],
            'expected_snapshot' => $summary['eligible_snapshot'],
        ];
        $recordBefore = (array) DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->first();

        foreach ([
            'academic_program_id' => 11,
            'applicant_id' => 900,
            'decision_status' => 'accepted',
        ] as $unexpectedField => $unexpectedValue) {
            $this->actingAs($actor, 'sanctum')
                ->postJson('/api/v1/ministry-placements/1/applicant-conversion/convert-all', [
                    ...$legalPayload,
                    $unexpectedField => $unexpectedValue,
                ])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'ministry_placement_conversion_batch_payload_not_allowed');

            self::assertSame(0, DB::table('applicants')->count());
            self::assertSame(0, DB::table('admission_applications')->count());
            self::assertSame(0, DB::table('user_activity_logs')->count());
            self::assertSame(
                $recordBefore,
                (array) DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->first(),
            );
        }

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/ministry-placements/1/applicant-conversion/convert-all', $legalPayload)
            ->assertOk()
            ->assertJsonPath('data.converted_count', 1);

        self::assertSame(1, DB::table('applicants')->count());
        self::assertSame(1, DB::table('admission_applications')->count());
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'ministry_placement.applicant_convert_bulk')->count());
    }

    public function test_ministry_p3_05_to_18_conversion_rechecks_source_and_creates_only_server_derived_pending_rows(): void
    {
        $actor = User::query()->findOrFail(7);
        $unmatched = $this->record(1, '00111111111', status: 'imported', programId: null);
        $this->assertConversionError($unmatched, 'ministry_placement_conversion_not_ready');

        $invalidProfile = $this->record(2, '00111111112');
        DB::table('ministry_placement_records')->where('placement_record_id', $invalidProfile)->update(['first_name' => '']);
        $this->assertConversionError($invalidProfile, 'ministry_placement_conversion_inconsistent');

        foreach ([
            ['academic_programs', 'academic_program_id'],
            ['departments', 'department_id'],
            ['colleges', 'college_id'],
        ] as [$table, $key]) {
            $recordId = $this->record(DB::table('ministry_placement_records')->max('placement_record_id') + 1, '0022222222'.DB::table('ministry_placement_records')->count());
            DB::table($table)->where($key, 10)->update(['is_active' => 0]);
            $this->assertConversionError($recordId, 'ministry_placement_program_match_stale');
            DB::table($table)->where($key, 10)->update(['is_active' => 1]);
        }

        $recordId = $this->record(10, '00987654321');
        $result = $this->service->convert($recordId, $actor);
        self::assertTrue($result['created']);
        self::assertSame(1, DB::table('applicants')->count());
        self::assertSame(1, DB::table('admission_applications')->count());
        $applicant = DB::table('applicants')->first();
        $application = DB::table('admission_applications')->first();
        self::assertSame('MP-R10', $applicant->applicant_number);
        self::assertStringNotContainsString('00987654321', $applicant->applicant_number);
        self::assertSame('First 10', $applicant->first_name);
        self::assertSame('Last 10', $applicant->last_name);
        self::assertNull($applicant->address);
        self::assertSame(10, (int) $application->academic_program_id);
        self::assertSame(1, (int) $application->academic_year_id);
        self::assertSame('pending', $application->decision_status);
        self::assertNull($application->decision_date);
        self::assertNull($application->decided_by_user_id);
        self::assertNull($application->notes);
        self::assertSame('applicant_created', DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->value('processing_status'));
        self::assertSame((int) $applicant->applicant_id, (int) DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->value('applicant_id'));
        $audit = json_decode((string) DB::table('user_activity_logs')->value('description'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['record_id', 'batch_id', 'applicant_id', 'admission_application_id', 'academic_program_id', 'academic_year_id'], array_keys($audit));
        self::assertStringNotContainsString('00987654321', json_encode($audit, JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('students')->count());
        self::assertSame(1, DB::table('users')->count());
        self::assertSame(0, DB::table('user_roles')->count());
    }

    public function test_ministry_p3_19_to_25_replay_is_idempotent_exact_and_ambiguity_safe(): void
    {
        $actor = User::query()->findOrFail(7);
        $recordId = $this->record(1, '00123456789');
        $created = $this->service->convert($recordId, $actor);
        $auditCount = DB::table('user_activity_logs')->count();

        DB::table('admission_applications')->insert($this->applicationRow(100, (int) $created['conversion']['applicant']['applicant_id'], 11, 2));
        $replay = $this->service->convert($recordId, $actor);
        self::assertFalse($replay['created'], 'Applications for another program/year must not invalidate the exact replay triple.');
        self::assertSame(1, DB::table('applicants')->count());
        self::assertSame($auditCount, DB::table('user_activity_logs')->count());

        DB::table('admission_applications')->where('academic_program_id', 10)->delete();
        $this->assertConversionError($recordId, 'ministry_placement_conversion_inconsistent');

        DB::table('admission_applications')->insert($this->applicationRow(101, (int) $created['conversion']['applicant']['applicant_id'], 10, 1));
        DB::table('admission_applications')->insert($this->applicationRow(102, (int) $created['conversion']['applicant']['applicant_id'], 10, 1));
        $this->assertConversionError($recordId, 'ministry_placement_conversion_inconsistent');

        DB::table('admission_applications')->where('admission_application_id', 102)->delete();
        DB::table('admission_applications')->where('admission_application_id', 101)->update(['decision_status' => 'unexpected_future_state']);
        $summary = $this->service->summary(1);
        $row = collect($summary['records'])->firstWhere('placement_record_id', $recordId);
        self::assertSame('inconsistent', $row['conversion_state']);
        self::assertSame('decision_status_unsupported', $row['blocker_code']);

        DB::table('admission_applications')->where('admission_application_id', 101)->update([
            'decision_status' => 'accepted', 'decision_date' => '2026-08-29', 'decided_by_user_id' => 7,
        ]);
        DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->update(['processing_status' => 'accepted']);
        $later = collect($this->service->summary(1)['records'])->firstWhere('placement_record_id', $recordId);
        self::assertSame('later_stage', $later['conversion_state']);
    }

    public function test_ministry_p3_21_to_28_identity_and_applicant_number_conflicts_fail_closed_without_guessing_by_profile(): void
    {
        $actor = User::query()->findOrFail(7);
        $recordId = $this->record(1, "\u{0660}\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}");
        DB::table('ministry_placement_batches')->insert(['batch_id' => 2, 'batch_name' => 'Batch 2', 'academic_year_id' => 1]);
        $otherId = $this->record(2, " 00123456789\u{00A0}", batchId: 2);
        $this->assertConversionError($recordId, 'ministry_placement_identity_conflict');
        self::assertSame(0, DB::table('applicants')->count());
        self::assertSame(0, DB::table('admission_applications')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());

        DB::table('ministry_placement_records')->where('placement_record_id', $otherId)->delete();
        DB::table('applicants')->insert($this->applicantRow(99, 'MP-R1', 'Unrelated', 'Person'));
        $this->assertConversionError($recordId, 'ministry_placement_applicant_number_conflict');

        DB::table('applicants')->where('applicant_id', 99)->delete();
        DB::table('applicants')->insert($this->applicantRow(100, 'MANUAL-100', 'First 1', 'Last 1'));
        $result = $this->service->convert($recordId, $actor);
        self::assertTrue($result['created'], 'Matching names alone must never reuse a manual Applicant.');
        self::assertSame(2, DB::table('applicants')->count());
    }

    public function test_missing_linked_applicant_and_mismatched_application_context_are_inconsistent(): void
    {
        $missingApplicant = $this->record(1, '00000000001');
        DB::table('ministry_placement_records')->where('placement_record_id', $missingApplicant)->update([
            'applicant_id' => 999,
            'processing_status' => 'applicant_created',
        ]);
        $missing = collect($this->service->summary(1)['records'])->firstWhere('placement_record_id', $missingApplicant);
        self::assertSame('inconsistent', $missing['conversion_state']);
        self::assertSame('linked_applicant_missing', $missing['blocker_code']);

        DB::table('applicants')->insert($this->applicantRow(100, 'MP-R2', 'Linked', 'Applicant'));
        $mismatchedApplication = $this->record(2, '00000000002');
        DB::table('ministry_placement_records')->where('placement_record_id', $mismatchedApplication)->update([
            'applicant_id' => 100,
            'processing_status' => 'applicant_created',
        ]);
        DB::table('admission_applications')->insert($this->applicationRow(100, 100, 11, 1));
        $mismatch = collect($this->service->summary(1)['records'])->firstWhere('placement_record_id', $mismatchedApplication);
        self::assertSame('inconsistent', $mismatch['conversion_state']);
        self::assertSame('expected_application_missing', $mismatch['blocker_code']);

        $unknownStatus = $this->record(3, '00000000003', status: 'unrecognized_state');
        $unknown = collect($this->service->summary(1)['records'])->firstWhere('placement_record_id', $unknownStatus);
        self::assertSame('inconsistent', $unknown['conversion_state']);
        self::assertSame('conversion_status_inconsistent', $unknown['blocker_code']);
    }

    public function test_ministry_p3_29_to_34_snapshot_is_sorted_stale_safe_and_bulk_excludes_non_convertible_rows(): void
    {
        $actor = User::query()->findOrFail(7);
        $this->record(30, '00000000030');
        $this->record(10, '00000000010');
        $this->record(20, '00000000020', status: 'imported', programId: null);
        $summary = $this->service->summary(1);
        self::assertSame(2, $summary['eligible_count']);
        self::assertSame(hash('sha256', "10:10:1\n30:10:1"), $summary['eligible_snapshot']);

        $this->record(40, '00000000040');
        $before = DB::table('applicants')->count();
        try {
            $this->service->convertAll(1, $summary['eligible_count'], $summary['eligible_snapshot'], $actor);
            self::fail('Changed count must reject the bulk snapshot.');
        } catch (MinistryPlacementException $exception) {
            self::assertSame('ministry_placement_conversion_batch_stale', $exception->errorCode);
        }
        self::assertSame($before, DB::table('applicants')->count());

        $beforeMembershipChange = $this->service->summary(1);
        DB::table('ministry_placement_records')->where('placement_record_id', 40)->update(['matched_academic_program_id' => 11]);
        try {
            $this->service->convertAll(1, $beforeMembershipChange['eligible_count'], $beforeMembershipChange['eligible_snapshot'], $actor);
            self::fail('Changed membership with the same count must reject the stale snapshot.');
        } catch (MinistryPlacementException $exception) {
            self::assertSame('ministry_placement_conversion_batch_stale', $exception->errorCode);
        }

        $sameCountNewSnapshot = $this->service->summary(1);
        $result = $this->service->convertAll(1, $sameCountNewSnapshot['eligible_count'], $sameCountNewSnapshot['eligible_snapshot'], $actor);
        self::assertSame(3, $result['converted_count']);
        self::assertSame(3, DB::table('applicants')->count());
        self::assertSame(3, DB::table('admission_applications')->count());
        self::assertNull(DB::table('ministry_placement_records')->where('placement_record_id', 20)->value('applicant_id'));
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'ministry_placement.applicant_convert_bulk')->count());
        $bulkAudit = json_decode((string) DB::table('user_activity_logs')->where('action_code', 'ministry_placement.applicant_convert_bulk')->value('description'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['batch_id', 'academic_year_id', 'converted_count'], array_keys($bulkAudit));
    }

    public function test_summary_query_count_does_not_grow_with_record_count(): void
    {
        $this->record(1, '00000000001');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->summary(1);
        $singleRecordQueries = count(DB::getQueryLog());

        foreach (range(2, 80) as $id) {
            $this->record($id, str_pad((string) $id, 11, '0', STR_PAD_LEFT));
        }
        DB::flushQueryLog();
        $this->service->summary(1);
        $manyRecordQueries = count(DB::getQueryLog());

        self::assertSame($singleRecordQueries, $manyRecordQueries, 'Conversion summary validation must remain bounded instead of querying once per record.');
    }

    public function test_ministry_p3_35_and_36_audit_failure_rolls_back_individual_and_bulk(): void
    {
        $actor = User::query()->findOrFail(7);
        $individualId = $this->record(1, '00000000001');
        Schema::drop('user_activity_logs');
        try {
            $this->service->convert($individualId, $actor);
            self::fail('Individual audit failure must roll back conversion.');
        } catch (\Throwable) {
            self::assertSame(0, DB::table('applicants')->count());
            self::assertNull(DB::table('ministry_placement_records')->where('placement_record_id', $individualId)->value('applicant_id'));
        }

        Schema::create('user_activity_logs', fn (Blueprint $table) => $this->activityLogColumns($table));
        $bulkId = $this->record(2, '00000000002');
        $summary = $this->service->summary(1);
        Schema::drop('user_activity_logs');
        try {
            $this->service->convertAll(1, $summary['eligible_count'], $summary['eligible_snapshot'], $actor);
            self::fail('Bulk audit failure must roll back every conversion.');
        } catch (\Throwable) {
            self::assertSame(0, DB::table('applicants')->count());
            self::assertNull(DB::table('ministry_placement_records')->whereIn('placement_record_id', [$individualId, $bulkId])->whereNotNull('applicant_id')->first());
        }
    }

    private function assertConversionError(int $recordId, string $errorCode): void
    {
        try {
            $this->service->convert($recordId, User::query()->findOrFail(7));
            self::fail('Expected conversion failure: '.$errorCode);
        } catch (MinistryPlacementException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    private function grantPermissions(bool $withScope = true, bool $withManage = true): void
    {
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'admissions_operator', 'is_active' => 1]);
        DB::table('permissions')->insert([
            ['permission_id' => 1, 'permission_code' => 'admissions.view', 'is_active' => 1],
            ['permission_id' => 2, 'permission_code' => 'admissions.manage', 'is_active' => 1],
        ]);
        DB::table('role_permissions')->insert(['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1]);
        if ($withManage) DB::table('role_permissions')->insert(['role_permission_id' => 2, 'role_id' => 1, 'permission_id' => 2]);
        DB::table('user_roles')->insert(['user_role_id' => 1, 'user_id' => 7, 'role_id' => 1, 'is_active' => 1]);
        if ($withScope) {
            DB::table('organizational_units')->insert(['organizational_unit_id' => 99, 'unit_code' => 'PRES']);
            DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 99, 'is_active' => 1]);
        }
    }

    private function activeProgram(int $id): void
    {
        DB::table('colleges')->insertOrIgnore(['college_id' => $id, 'college_name' => 'College '.$id, 'is_active' => 1]);
        DB::table('departments')->insertOrIgnore(['department_id' => $id, 'college_id' => $id, 'department_name' => 'Department '.$id, 'is_active' => 1]);
        DB::table('academic_programs')->insertOrIgnore(['academic_program_id' => $id, 'department_id' => $id, 'program_code' => 'P'.$id, 'program_name' => 'Program '.$id, 'is_active' => 1]);
    }

    private function record(int $id, ?string $nationalId, string $status = 'program_matched', ?int $programId = 10, int $batchId = 1): int
    {
        DB::table('ministry_placement_records')->insert([
            'placement_record_id' => $id,
            'batch_id' => $batchId,
            'row_number' => $id,
            'national_civil_id' => $nationalId,
            'first_name' => 'First '.$id,
            'last_name' => 'Last '.$id,
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'date_of_birth' => '2000-01-01',
            'gender' => 'male',
            'phone_number' => '0990000000',
            'email' => 'person'.$id.'@example.test',
            'nationality' => 'Syrian',
            'matched_academic_program_id' => $programId,
            'processing_status' => $status,
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function applicantRow(int $id, string $number, string $first, string $last): array
    {
        return [
            'applicant_id' => $id, 'applicant_number' => $number, 'first_name' => $first, 'last_name' => $last,
            'father_name' => null, 'mother_name' => null, 'date_of_birth' => null, 'gender' => null,
            'phone_number' => null, 'email' => null, 'address' => null, 'nationality' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function applicationRow(int $id, int $applicantId, int $programId, int $yearId): array
    {
        return [
            'admission_application_id' => $id, 'applicant_id' => $applicantId,
            'academic_program_id' => $programId, 'academic_year_id' => $yearId,
            'application_date' => '2026-08-29', 'decision_status' => 'pending',
            'decision_date' => null, 'decided_by_user_id' => null, 'notes' => null,
        ];
    }

    private function createSchema(): void
    {
        Schema::create('account_statuses', function (Blueprint $table): void { $table->increments('account_status_id'); $table->string('status_code'); $table->boolean('is_active'); });
        Schema::create('users', function (Blueprint $table): void { $table->increments('user_id'); $table->string('username'); $table->integer('account_status_id'); $table->string('password_hash')->nullable(); $table->timestamps(); });
        Schema::create('roles', function (Blueprint $table): void { $table->increments('role_id'); $table->string('role_code'); $table->boolean('is_active'); });
        Schema::create('permissions', function (Blueprint $table): void { $table->increments('permission_id'); $table->string('permission_code'); $table->boolean('is_active'); });
        Schema::create('role_permissions', function (Blueprint $table): void { $table->increments('role_permission_id'); $table->integer('role_id'); $table->integer('permission_id'); });
        Schema::create('user_roles', function (Blueprint $table): void { $table->increments('user_role_id'); $table->integer('user_id'); $table->integer('role_id'); $table->boolean('is_active'); });
        Schema::create('organizational_units', function (Blueprint $table): void { $table->increments('organizational_unit_id'); $table->string('unit_code'); });
        Schema::create('user_access_scopes', function (Blueprint $table): void { $table->increments('user_access_scope_id'); $table->integer('user_id'); $table->string('scope_type'); $table->integer('scope_id'); $table->boolean('is_active'); });
        Schema::create('academic_years', function (Blueprint $table): void { $table->increments('academic_year_id'); $table->string('year_name'); });
        Schema::create('colleges', function (Blueprint $table): void { $table->increments('college_id'); $table->string('college_name'); $table->boolean('is_active'); });
        Schema::create('departments', function (Blueprint $table): void { $table->increments('department_id'); $table->integer('college_id'); $table->string('department_name'); $table->boolean('is_active'); });
        Schema::create('academic_programs', function (Blueprint $table): void { $table->increments('academic_program_id'); $table->integer('department_id'); $table->string('program_code'); $table->string('program_name'); $table->boolean('is_active'); });
        Schema::create('ministry_placement_batches', function (Blueprint $table): void { $table->increments('batch_id'); $table->string('batch_name'); $table->integer('academic_year_id'); $table->timestamps(); });
        Schema::create('ministry_placement_records', function (Blueprint $table): void {
            $table->increments('placement_record_id'); $table->integer('batch_id'); $table->integer('row_number')->nullable();
            $table->string('national_civil_id')->nullable(); $table->string('first_name'); $table->string('last_name');
            $table->string('father_name')->nullable(); $table->string('mother_name')->nullable(); $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable(); $table->string('phone_number')->nullable(); $table->string('email')->nullable(); $table->string('nationality')->nullable();
            $table->integer('matched_academic_program_id')->nullable(); $table->integer('applicant_id')->nullable();
            $table->string('processing_status')->default('imported'); $table->timestamps();
        });
        Schema::create('applicants', function (Blueprint $table): void {
            $table->increments('applicant_id'); $table->string('applicant_number', 50)->unique();
            $table->string('first_name'); $table->string('last_name'); $table->string('father_name')->nullable(); $table->string('mother_name')->nullable();
            $table->date('date_of_birth')->nullable(); $table->string('gender')->nullable(); $table->string('phone_number')->nullable();
            $table->string('email')->nullable(); $table->string('address')->nullable(); $table->string('nationality')->nullable(); $table->timestamps();
        });
        Schema::create('admission_applications', function (Blueprint $table): void {
            $table->increments('admission_application_id'); $table->integer('applicant_id'); $table->integer('academic_program_id');
            $table->integer('academic_year_id'); $table->date('application_date'); $table->string('decision_status')->default('pending');
            $table->date('decision_date')->nullable(); $table->integer('decided_by_user_id')->nullable(); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void { $table->increments('student_id'); });
        Schema::create('user_activity_logs', fn (Blueprint $table) => $this->activityLogColumns($table));
    }

    private function activityLogColumns(Blueprint $table): void
    {
        $table->bigIncrements('activity_log_id'); $table->integer('user_id'); $table->string('module_code')->nullable();
        $table->string('action_code')->nullable(); $table->text('description')->nullable(); $table->timestamp('created_at')->nullable();
    }
}
