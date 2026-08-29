<?php

namespace Tests\Feature;

use App\Exceptions\MinistryPlacementException;
use App\Models\User;
use App\Services\MinistryPlacementStudentEnrollmentService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MinistryPlacementPhase4StudentEnrollmentTest extends TestCase
{
    private MinistryPlacementStudentEnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active', 'is_active' => 1]);
        DB::table('users')->insert(['user_id' => 7, 'username' => 'operator', 'account_status_id' => 1]);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('ministry_placement_batches')->insert(['batch_id' => 1, 'batch_name' => 'Batch 1', 'academic_year_id' => 1]);
        DB::table('academic_levels')->insert([
            ['academic_level_id' => 1, 'level_code' => 'year_1', 'level_name' => 'Year 1', 'level_order' => 1, 'is_active' => 1],
            ['academic_level_id' => 2, 'level_code' => 'year_2', 'level_name' => 'Year 2', 'level_order' => 2, 'is_active' => 0],
        ]);
        DB::table('student_statuses')->insert(['student_status_id' => 1, 'status_code' => 'active', 'status_name' => 'Active', 'is_active' => 1]);
        $this->activeProgram(10);
        $this->service = app(MinistryPlacementStudentEnrollmentService::class);
    }

    public function test_ministry_p4_01_to_04_authority_and_ministry_level_catalog(): void
    {
        $this->grantPermissions(withScope: false);
        $actor = User::query()->findOrFail(7);
        $access = app(MinistryPlacementAccess::class);
        self::assertFalse($access->canView($actor));
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/student-enrollment')->assertForbidden();

        DB::table('organizational_units')->insert(['organizational_unit_id' => 99, 'unit_code' => 'PRES', 'is_active' => 1]);
        DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 99, 'is_active' => 1]);
        $this->seedReady(1);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placements/1/student-enrollment')->assertOk();
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/ministry-placement-academic-levels')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.academic_level_id', 1);

        DB::table('role_permissions')->where('permission_id', 2)->delete();
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ministry-placement-records/1/enroll-student', $this->validInput())->assertForbidden();
    }

    public function test_ministry_p4_05_and_06_individual_request_is_strict_and_server_derived(): void
    {
        $this->grantPermissions();
        $this->seedReady(1);
        $before = $this->phase4Counts();
        $recordBefore = (array) DB::table('ministry_placement_records')->where('placement_record_id', 1)->first();
        foreach (['applicant_id', 'admission_application_id', 'academic_program_id', 'student_status_id', 'decision_status', 'decided_by_user_id', 'processing_status', 'email', 'password'] as $field) {
            $this->actingAs(User::query()->findOrFail(7), 'sanctum')
                ->postJson('/api/v1/ministry-placement-records/1/enroll-student', [...$this->validInput(), $field => 999])
                ->assertStatus(422)->assertJsonPath('error_code', 'ministry_placement_enrollment_payload_not_allowed');
            self::assertSame($before, $this->phase4Counts());
            self::assertSame($recordBefore, (array) DB::table('ministry_placement_records')->where('placement_record_id', 1)->first());
        }

        $this->actingAs(User::query()->findOrFail(7), 'sanctum')
            ->postJson('/api/v1/ministry-placement-records/1/enroll-student', $this->validInput())
            ->assertOk()->assertJsonPath('data.created', true);
        self::assertSame(1, DB::table('students')->count());
    }

    public function test_ministry_p4_07_to_17_phase3_chain_decisions_hierarchy_and_identity_fail_closed(): void
    {
        $actor = User::query()->findOrFail(7);
        $unmatched = $this->record(1, '00000000001', 'program_matched', 10, null);
        $this->assertEnrollmentError($unmatched, 'ministry_placement_enrollment_not_ready');

        $missingApplicant = $this->record(2, '00000000002', 'applicant_created', 10, 999);
        $this->assertEnrollmentError($missingApplicant, 'ministry_placement_enrollment_inconsistent');

        $zeroApplication = $this->seedReady(3, createApplication: false);
        $this->assertEnrollmentError($zeroApplication, 'ministry_placement_enrollment_inconsistent');

        $multiple = $this->seedReady(4);
        DB::table('admission_applications')->insert($this->applicationRow(204, 4, 10, 'pending'));
        $this->assertEnrollmentError($multiple, 'ministry_placement_enrollment_inconsistent');

        foreach (['rejected', 'unexpected'] as $index => $decision) {
            $id = 10 + $index;
            $this->seedReady($id, decision: $decision);
            if ($decision === 'rejected') {
                DB::table('admission_applications')->where('admission_application_id', 100 + $id)->update(['decision_date' => '2026-08-29', 'decided_by_user_id' => 7]);
            }
            $this->assertEnrollmentError($id, 'ministry_placement_enrollment_inconsistent');
        }

        foreach ([['academic_programs', 'academic_program_id'], ['departments', 'department_id'], ['colleges', 'college_id']] as $offset => [$table, $key]) {
            $id = 20 + $offset;
            $this->seedReady($id);
            DB::table($table)->where($key, 10)->update(['is_active' => 0]);
            $this->assertEnrollmentError($id, 'ministry_placement_enrollment_program_stale');
            DB::table($table)->where($key, 10)->update(['is_active' => 1]);
        }

        $conflict = $this->seedReady(30, nationalId: "\u{0660}\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}");
        DB::table('ministry_placement_batches')->insert(['batch_id' => 2, 'batch_name' => 'Other', 'academic_year_id' => 1]);
        $this->record(31, " 00123456789\u{00A0}", 'program_matched', 10, null, 2);
        $this->assertEnrollmentError($conflict, 'ministry_placement_identity_conflict');
        self::assertSame(0, DB::table('students')->count());
        self::assertSame(0, DB::table('admission_applications')->where('decision_status', 'accepted')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
        self::assertSame(7, (int) $actor->user_id);
    }

    public function test_ministry_p4_18_to_30_success_conflicts_mapping_and_audit(): void
    {
        $actor = User::query()->findOrFail(7);
        $recordId = $this->seedReady(1);
        DB::table('students')->insert($this->studentRow(90, 'EXISTING', 999, 10, 'other@example.test'));
        $this->assertEnrollmentError($recordId, 'ministry_placement_student_number_conflict', ['student_number' => ' EXISTING ']);

        DB::table('students')->where('student_id', 90)->update(['student_number' => 'OTHER', 'email' => 'person1@example.test']);
        $this->assertEnrollmentError($recordId, 'ministry_placement_student_email_conflict');
        DB::table('students')->where('student_id', 90)->delete();

        $this->assertEnrollmentError($recordId, 'ministry_placement_academic_level_unavailable', ['current_academic_level_id' => 2]);
        DB::table('student_statuses')->where('student_status_id', 1)->update(['is_active' => 0]);
        $this->assertEnrollmentError($recordId, 'ministry_placement_student_status_configuration_invalid');
        DB::table('student_statuses')->where('student_status_id', 1)->update(['is_active' => 1]);
        DB::table('student_statuses')->insert(['student_status_id' => 2, 'status_code' => 'active', 'status_name' => 'Duplicate', 'is_active' => 1]);
        $this->assertEnrollmentError($recordId, 'ministry_placement_student_status_configuration_invalid');
        DB::table('student_statuses')->where('student_status_id', 2)->delete();

        $usersBefore = DB::table('users')->count();
        $rolesBefore = DB::table('user_roles')->count();
        $result = $this->service->enroll($recordId, [
            'student_number' => "\u{00A0} R26001001 \u{00A0}",
            'current_academic_level_id' => 1,
            'enrollment_date' => '2026-09-01',
        ], $actor);
        self::assertTrue($result['created']);
        $application = DB::table('admission_applications')->where('admission_application_id', 101)->first();
        $applicant = DB::table('applicants')->where('applicant_id', 1)->first();
        $student = DB::table('students')->where('admission_application_id', 101)->first();
        self::assertSame('accepted', $application->decision_status);
        self::assertSame(now('UTC')->toDateString(), $application->decision_date);
        self::assertSame(7, (int) $application->decided_by_user_id);
        self::assertSame('R26001001', $student->student_number);
        self::assertSame((int) $application->academic_program_id, (int) $student->academic_program_id);
        foreach (['first_name', 'last_name', 'father_name', 'mother_name', 'date_of_birth', 'gender', 'phone_number', 'email', 'address', 'nationality'] as $field) {
            self::assertSame($applicant->{$field}, $student->{$field}, 'Student profile must come from Applicant: '.$field);
        }
        self::assertSame(1, (int) $student->current_academic_level_id);
        self::assertSame(1, (int) $student->student_status_id);
        self::assertSame('2026-09-01', $student->enrollment_date);
        self::assertSame('enrolled', DB::table('ministry_placement_records')->where('placement_record_id', $recordId)->value('processing_status'));
        $audit = json_decode((string) DB::table('user_activity_logs')->value('description'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['record_id', 'batch_id', 'applicant_id', 'admission_application_id', 'student_id', 'academic_program_id', 'academic_level_id'], array_keys($audit));
        self::assertSame($usersBefore, DB::table('users')->count());
        self::assertSame($rolesBefore, DB::table('user_roles')->count());
        self::assertSame(0, DB::table('student_academic_terms')->count());
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public function test_ministry_p4_31_to_37_replay_partial_states_and_atomic_failures(): void
    {
        $actor = User::query()->findOrFail(7);
        $recordId = $this->seedReady(1);
        $created = $this->service->enroll($recordId, $this->validInput(), $actor);
        $auditCount = DB::table('user_activity_logs')->count();
        $replay = $this->service->enroll($recordId, $this->validInput(), $actor);
        self::assertFalse($replay['created']);
        self::assertSame($created['enrollment']['student']['student_id'], $replay['enrollment']['student']['student_id']);
        self::assertSame($auditCount, DB::table('user_activity_logs')->count());

        $acceptedWithoutStudent = $this->seedReady(2, decision: 'accepted');
        DB::table('admission_applications')->where('admission_application_id', 102)->update(['decision_date' => '2026-08-29', 'decided_by_user_id' => 7]);
        $this->assertEnrollmentError($acceptedWithoutStudent, 'ministry_placement_enrollment_inconsistent');

        $pendingWithStudent = $this->seedReady(3);
        DB::table('students')->insert($this->studentRow(93, 'R-PENDING', 103, 10, 'pending@example.test'));
        $this->assertEnrollmentError($pendingWithStudent, 'ministry_placement_enrollment_inconsistent');

        DB::table('admission_applications')->where('admission_application_id', 103)->update(['decision_status' => 'accepted', 'decision_date' => '2026-08-29', 'decided_by_user_id' => 7]);
        DB::table('ministry_placement_records')->where('placement_record_id', 3)->update(['processing_status' => 'enrolled']);
        DB::table('students')->where('student_id', 93)->update(['academic_program_id' => 99]);
        $this->assertEnrollmentError(3, 'ministry_placement_enrollment_inconsistent');

        $insertFailureId = $this->seedReady(4);
        DB::unprepared("CREATE TRIGGER fail_ministry_student BEFORE INSERT ON students BEGIN SELECT RAISE(ABORT, 'forced student failure'); END");
        try {
            $this->service->enroll($insertFailureId, [...$this->validInput(), 'student_number' => 'INSERT-FAIL'], $actor);
            self::fail('Student insert failure must roll back application acceptance.');
        } catch (\Throwable) {
            self::assertSame('pending', DB::table('admission_applications')->where('admission_application_id', 104)->value('decision_status'));
            self::assertSame('applicant_created', DB::table('ministry_placement_records')->where('placement_record_id', 4)->value('processing_status'));
        }
        DB::unprepared('DROP TRIGGER fail_ministry_student');

        $rollbackId = $this->seedReady(5);
        Schema::drop('user_activity_logs');
        try {
            $this->service->enroll($rollbackId, [...$this->validInput(), 'student_number' => 'ROLLBACK'], $actor);
            self::fail('Audit failure must roll back all Phase 4 writes.');
        } catch (\Throwable) {
            self::assertSame('pending', DB::table('admission_applications')->where('admission_application_id', 105)->value('decision_status'));
            self::assertFalse(DB::table('students')->where('admission_application_id', 105)->exists());
            self::assertSame('applicant_created', DB::table('ministry_placement_records')->where('placement_record_id', 5)->value('processing_status'));
        }
    }

    public function test_ministry_p4_38_to_47_bulk_payload_membership_prevalidation_and_audit_are_atomic(): void
    {
        $this->grantPermissions();
        $actor = User::query()->findOrFail(7);
        $this->seedReady(1);
        $this->seedReady(2);
        $summary = $this->service->summary(1);
        $items = [$this->bulkItem(1, 'R-BULK-1'), $this->bulkItem(2, 'R-BULK-2')];
        $legal = ['expected_eligible_count' => 2, 'expected_snapshot' => $summary['eligible_snapshot'], 'items' => $items];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ministry-placements/1/student-enrollment/enroll-all', [...$legal, 'academic_program_id' => 10])
            ->assertStatus(422)->assertJsonPath('error_code', 'ministry_placement_enrollment_batch_payload_not_allowed');
        $nested = $legal;
        $nested['items'][0]['decision_status'] = 'accepted';
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/ministry-placements/1/student-enrollment/enroll-all', $nested)
            ->assertStatus(422)->assertJsonPath('error_code', 'ministry_placement_enrollment_batch_payload_not_allowed');
        self::assertSame(0, DB::table('students')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());

        $this->assertBulkError(1, $summary['eligible_snapshot'], $items, 'ministry_placement_enrollment_batch_stale');
        $this->assertBulkError(2, str_repeat('0', 64), $items, 'ministry_placement_enrollment_batch_stale');
        $this->assertBulkError(2, $summary['eligible_snapshot'], [$items[0], $this->bulkItem(99, 'R-X')], 'ministry_placement_enrollment_batch_stale');
        $this->assertBulkError(2, $summary['eligible_snapshot'], [$this->bulkItem(1, 'SAME'), $this->bulkItem(2, 'SAME')], 'ministry_placement_student_number_conflict');

        DB::table('students')->insert($this->studentRow(90, 'EXISTS', 999, 10, 'exists@example.test'));
        $this->assertBulkError(2, $summary['eligible_snapshot'], [$this->bulkItem(1, 'EXISTS'), $items[1]], 'ministry_placement_student_number_conflict');
        DB::table('students')->where('student_id', 90)->delete();
        DB::table('applicants')->where('applicant_id', 2)->update(['email' => 'person1@example.test']);
        $this->assertBulkError(2, $summary['eligible_snapshot'], $items, 'ministry_placement_student_email_conflict');
        DB::table('applicants')->where('applicant_id', 2)->update(['email' => 'person2@example.test']);
        DB::table('students')->insert($this->studentRow(91, 'OTHER', 998, 10, 'person2@example.test'));
        $this->assertBulkError(2, $summary['eligible_snapshot'], $items, 'ministry_placement_student_email_conflict');
        DB::table('students')->where('student_id', 91)->delete();

        $invalidLevel = $items;
        $invalidLevel[1]['current_academic_level_id'] = 2;
        $this->assertBulkError(2, $summary['eligible_snapshot'], $invalidLevel, 'ministry_placement_academic_level_unavailable');
        self::assertSame(0, DB::table('students')->count());
        self::assertSame(0, DB::table('admission_applications')->where('decision_status', 'accepted')->count());

        $result = $this->service->enrollAll(1, 2, $summary['eligible_snapshot'], $items, $actor);
        self::assertSame(2, $result['enrolled_count']);
        self::assertSame(2, DB::table('students')->count());
        self::assertSame(2, DB::table('admission_applications')->where('decision_status', 'accepted')->count());
        self::assertSame(2, DB::table('ministry_placement_records')->where('processing_status', 'enrolled')->count());
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'ministry_placement.student_enroll_bulk')->count());
        $audit = json_decode((string) DB::table('user_activity_logs')->value('description'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['batch_id', 'academic_year_id', 'enrolled_count'], array_keys($audit));
    }

    public function test_ministry_p4_48_to_53_queries_are_bounded_and_no_later_entities_are_created(): void
    {
        $this->seedReady(1);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->summary(1);
        $singleQueries = count(DB::getQueryLog());
        foreach (range(2, 60) as $id) $this->seedReady($id);
        DB::flushQueryLog();
        $this->service->summary(1);
        self::assertSame($singleQueries, count(DB::getQueryLog()));
        self::assertSame(1, DB::table('users')->count());
        self::assertSame(0, DB::table('user_roles')->count());
        self::assertSame(0, DB::table('student_academic_terms')->count());
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public function test_ministry_p4_47_bulk_audit_failure_rolls_back_every_student_and_decision(): void
    {
        $this->seedReady(1);
        $this->seedReady(2);
        $summary = $this->service->summary(1);
        Schema::drop('user_activity_logs');

        try {
            $this->service->enrollAll(
                1,
                2,
                $summary['eligible_snapshot'],
                [$this->bulkItem(1, 'AUDIT-1'), $this->bulkItem(2, 'AUDIT-2')],
                User::query()->findOrFail(7),
            );
            self::fail('Bulk audit failure must roll back every Phase 4 mutation.');
        } catch (\Throwable) {
            self::assertSame(0, DB::table('students')->count());
            self::assertSame(0, DB::table('admission_applications')->where('decision_status', 'accepted')->count());
            self::assertSame(2, DB::table('ministry_placement_records')->where('processing_status', 'applicant_created')->count());
        }
    }

    private function assertEnrollmentError(int $recordId, string $code, array $overrides = []): void
    {
        try {
            $this->service->enroll($recordId, [...$this->validInput(), ...$overrides], User::query()->findOrFail(7));
            self::fail('Expected enrollment failure: '.$code);
        } catch (MinistryPlacementException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function assertBulkError(int $count, string $snapshot, array $items, string $code): void
    {
        try {
            $this->service->enrollAll(1, $count, $snapshot, $items, User::query()->findOrFail(7));
            self::fail('Expected bulk enrollment failure: '.$code);
        } catch (MinistryPlacementException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
        self::assertSame(0, DB::table('students')->count());
        self::assertSame(0, DB::table('admission_applications')->where('decision_status', 'accepted')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    private function grantPermissions(bool $withScope = true): void
    {
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'admissions_operator', 'is_active' => 1]);
        DB::table('permissions')->insert([
            ['permission_id' => 1, 'permission_code' => 'admissions.view', 'is_active' => 1],
            ['permission_id' => 2, 'permission_code' => 'admissions.manage', 'is_active' => 1],
        ]);
        DB::table('role_permissions')->insert([
            ['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1],
            ['role_permission_id' => 2, 'role_id' => 1, 'permission_id' => 2],
        ]);
        DB::table('user_roles')->insert(['user_role_id' => 1, 'user_id' => 7, 'role_id' => 1, 'is_active' => 1]);
        if ($withScope) {
            DB::table('organizational_units')->insert(['organizational_unit_id' => 99, 'unit_code' => 'PRES', 'is_active' => 1]);
            DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 99, 'is_active' => 1]);
        }
    }

    private function activeProgram(int $id): void
    {
        DB::table('colleges')->insert(['college_id' => $id, 'college_name' => 'College', 'is_active' => 1]);
        DB::table('departments')->insert(['department_id' => $id, 'college_id' => $id, 'department_name' => 'Department', 'is_active' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $id, 'program_code' => 'P'.$id, 'program_name' => 'Program', 'is_active' => 1]);
    }

    private function seedReady(int $id, ?string $nationalId = null, bool $createApplication = true, string $decision = 'pending'): int
    {
        $this->record($id, $nationalId ?? str_pad((string) $id, 11, '0', STR_PAD_LEFT), 'applicant_created', 10, $id);
        DB::table('applicants')->insert([
            'applicant_id' => $id, 'applicant_number' => 'MP-R'.$id, 'first_name' => 'Applicant', 'last_name' => (string) $id,
            'father_name' => 'Father', 'mother_name' => 'Mother', 'date_of_birth' => '2000-01-01', 'gender' => 'male',
            'phone_number' => '0990000000', 'email' => 'person'.$id.'@example.test', 'address' => 'Address', 'nationality' => 'Syrian',
        ]);
        if ($createApplication) DB::table('admission_applications')->insert($this->applicationRow(100 + $id, $id, 10, $decision));

        return $id;
    }

    private function record(int $id, ?string $nationalId, string $status, ?int $programId, ?int $applicantId, int $batchId = 1): int
    {
        DB::table('ministry_placement_records')->insert([
            'placement_record_id' => $id, 'batch_id' => $batchId, 'national_civil_id' => $nationalId,
            'matched_academic_program_id' => $programId, 'applicant_id' => $applicantId, 'processing_status' => $status,
        ]);
        return $id;
    }

    private function applicationRow(int $id, int $applicantId, int $programId, string $decision): array
    {
        return [
            'admission_application_id' => $id, 'applicant_id' => $applicantId, 'academic_program_id' => $programId,
            'academic_year_id' => 1, 'application_date' => '2026-08-29', 'decision_status' => $decision,
            'decision_date' => null, 'decided_by_user_id' => null, 'notes' => null,
        ];
    }

    private function validInput(): array
    {
        return ['student_number' => 'R26001001', 'current_academic_level_id' => 1, 'enrollment_date' => '2026-09-01'];
    }

    private function bulkItem(int $recordId, string $number): array
    {
        return ['placement_record_id' => $recordId, 'student_number' => $number, 'current_academic_level_id' => 1, 'enrollment_date' => '2026-09-01'];
    }

    private function studentRow(int $id, string $number, int $applicationId, int $programId, ?string $email): array
    {
        return [
            'student_id' => $id, 'student_number' => $number, 'admission_application_id' => $applicationId,
            'first_name' => 'Existing', 'last_name' => 'Student', 'email' => $email, 'academic_program_id' => $programId,
            'current_academic_level_id' => 1, 'enrollment_date' => '2026-08-01', 'student_status_id' => 1,
        ];
    }

    private function phase4Counts(): array
    {
        return [DB::table('students')->count(), DB::table('admission_applications')->where('decision_status', 'accepted')->count(), DB::table('user_activity_logs')->count()];
    }

    private function createSchema(): void
    {
        Schema::create('account_statuses', fn (Blueprint $t) => [$t->increments('account_status_id'), $t->string('status_code'), $t->boolean('is_active')]);
        Schema::create('users', fn (Blueprint $t) => [$t->increments('user_id'), $t->string('username'), $t->integer('account_status_id'), $t->string('password_hash')->nullable(), $t->timestamps()]);
        Schema::create('roles', fn (Blueprint $t) => [$t->increments('role_id'), $t->string('role_code'), $t->boolean('is_active')]);
        Schema::create('permissions', fn (Blueprint $t) => [$t->increments('permission_id'), $t->string('permission_code'), $t->boolean('is_active')]);
        Schema::create('role_permissions', fn (Blueprint $t) => [$t->increments('role_permission_id'), $t->integer('role_id'), $t->integer('permission_id')]);
        Schema::create('user_roles', fn (Blueprint $t) => [$t->increments('user_role_id'), $t->integer('user_id'), $t->integer('role_id'), $t->boolean('is_active')]);
        Schema::create('organizational_units', fn (Blueprint $t) => [$t->increments('organizational_unit_id'), $t->string('unit_code'), $t->boolean('is_active')]);
        Schema::create('user_access_scopes', fn (Blueprint $t) => [$t->increments('user_access_scope_id'), $t->integer('user_id'), $t->string('scope_type'), $t->integer('scope_id'), $t->boolean('is_active')]);
        Schema::create('academic_years', fn (Blueprint $t) => [$t->increments('academic_year_id'), $t->string('year_name')]);
        Schema::create('academic_levels', fn (Blueprint $t) => [$t->increments('academic_level_id'), $t->string('level_code'), $t->string('level_name'), $t->integer('level_order'), $t->boolean('is_active')]);
        Schema::create('student_statuses', fn (Blueprint $t) => [$t->increments('student_status_id'), $t->string('status_code'), $t->string('status_name'), $t->boolean('is_active')]);
        Schema::create('colleges', fn (Blueprint $t) => [$t->increments('college_id'), $t->string('college_name'), $t->boolean('is_active')]);
        Schema::create('departments', fn (Blueprint $t) => [$t->increments('department_id'), $t->integer('college_id'), $t->string('department_name'), $t->boolean('is_active')]);
        Schema::create('academic_programs', fn (Blueprint $t) => [$t->increments('academic_program_id'), $t->integer('department_id'), $t->string('program_code'), $t->string('program_name'), $t->boolean('is_active')]);
        Schema::create('ministry_placement_batches', fn (Blueprint $t) => [$t->increments('batch_id'), $t->string('batch_name'), $t->integer('academic_year_id'), $t->timestamps()]);
        Schema::create('ministry_placement_records', fn (Blueprint $t) => [$t->increments('placement_record_id'), $t->integer('batch_id'), $t->string('national_civil_id')->nullable(), $t->integer('matched_academic_program_id')->nullable(), $t->integer('applicant_id')->nullable(), $t->string('processing_status')->default('imported'), $t->timestamps()]);
        Schema::create('applicants', fn (Blueprint $t) => [$t->increments('applicant_id'), $t->string('applicant_number')->unique(), $t->string('first_name'), $t->string('last_name'), $t->string('father_name')->nullable(), $t->string('mother_name')->nullable(), $t->date('date_of_birth')->nullable(), $t->string('gender')->nullable(), $t->string('phone_number')->nullable(), $t->string('email')->nullable(), $t->string('address')->nullable(), $t->string('nationality')->nullable(), $t->timestamps()]);
        Schema::create('admission_applications', fn (Blueprint $t) => [$t->increments('admission_application_id'), $t->integer('applicant_id'), $t->integer('academic_program_id'), $t->integer('academic_year_id'), $t->date('application_date'), $t->string('decision_status')->default('pending'), $t->date('decision_date')->nullable(), $t->integer('decided_by_user_id')->nullable(), $t->text('notes')->nullable(), $t->timestamps()]);
        Schema::create('students', function (Blueprint $t): void { $t->increments('student_id'); $t->string('student_number')->unique(); $t->integer('admission_application_id')->nullable()->unique(); $t->string('first_name'); $t->string('last_name'); $t->string('father_name')->nullable(); $t->string('mother_name')->nullable(); $t->date('date_of_birth')->nullable(); $t->string('gender')->nullable(); $t->string('phone_number')->nullable(); $t->string('email')->nullable()->unique(); $t->string('address')->nullable(); $t->string('nationality')->nullable(); $t->integer('academic_program_id'); $t->integer('current_academic_level_id'); $t->date('enrollment_date'); $t->integer('student_status_id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('user_activity_logs', fn (Blueprint $t) => [$t->bigIncrements('activity_log_id'), $t->integer('user_id'), $t->string('module_code')->nullable(), $t->string('action_code')->nullable(), $t->text('description')->nullable(), $t->timestamp('created_at')->nullable()]);
        Schema::create('student_academic_terms', fn (Blueprint $t) => $t->increments('student_academic_term_id'));
        Schema::create('student_course_registrations', fn (Blueprint $t) => $t->increments('student_course_registration_id'));
    }
}
