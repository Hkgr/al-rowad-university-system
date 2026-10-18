<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseResultSymbolsFixture;
use Tests\TestCase;

/** Actual HTTP middleware/services/SQLite SQL. Does not claim browser or MariaDB lock coverage. */
class IncompleteResultReadBehaviorTest extends TestCase
{
    private const BASE = '/api/v1/exams/incomplete-results';
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::getDriverName());
        Schema::dropAllTables(); CourseResultSymbolsFixture::installLegacy(); CourseResultSymbolsFixture::installLifecycle();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
        Sanctum::actingAs(User::findOrFail(1));
    }
    private function grant(int $registration = 1): array
    {
        return $this->postJson(self::BASE.'/registrations/'.$registration, ['request_id'=>(string) Str::uuid(), 'expected_revision'=>0,
            'reason'=>'سبب داخلي لا يظهر في كشف الطالب', 'requirements'=>'متطلبات داخلية', 'expiry_outcome'=>'F', 'deadline_at'=>'2026-10-20'])
            ->assertCreated()->json('data');
    }
    public function test_registration_read_returns_actual_identity_components_and_does_not_create_records(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $data = $this->getJson(self::BASE.'/registrations/1')->assertOk()->json('data');
        self::assertSame('S1', $data['student']['number']); self::assertSame('C1', $data['course']['code']);
        self::assertSame('2026', $data['academic_year']['name']); self::assertSame('First', $data['semester']['name']);
        self::assertNull($data['case']); self::assertNull($data['current_result']); self::assertTrue($data['allowed_operations']['grant']);
        self::assertCount(2, $data['components']); self::assertNull($data['components'][0]['mark']);
        self::assertFalse($data['components'][0]['editable']);
        foreach (DB::getQueryLog() as $query) self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        DB::disableQueryLog(); self::assertSame(0, DB::table('student_course_results')->count());
    }
    public function test_active_detail_preserves_zero_locks_completed_work_and_enriches_history(): void
    {
        DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>2,'mark'=>0,'grade_status'=>'approved']);
        $case = $this->grant();
        $data = $this->getJson(self::BASE.'/'.$case['case']['incomplete_case_id'])->assertOk()->json('data');
        self::assertTrue($data['components'][0]['editable']); self::assertEquals(0, $data['components'][1]['mark']);
        self::assertFalse($data['components'][1]['editable']); self::assertSame('completed_component_locked', $data['components'][1]['blocking_code']);
        self::assertTrue($data['resolution']['available']); self::assertSame('I', $data['current_result']['letter_grade']);
        self::assertNull($data['current_result']['final_mark']); self::assertFalse($data['current_result']['is_official']);
        self::assertSame('officer', $data['events'][0]['actor_label']);
    }
    public function test_list_is_paginated_enriched_and_keeps_existing_envelope_and_scoped_term_options(): void
    {
        $this->grant(); $this->grant(2);
        $data = $this->getJson(self::BASE.'?per_page=1&page=2')->assertOk()->json('data');
        self::assertSame(2, $data['total']); self::assertCount(1, $data['data']); self::assertSame(2, $data['current_page']);
        self::assertSame('S2', $data['data'][0]['student']['number']); self::assertSame('C1', $data['data'][0]['course']['code']);
        self::assertArrayNotHasKey('registration', $data['data'][0]);
        self::assertSame('2026', $data['filter_options']['terms'][0]['year_name']);
        $this->getJson(self::BASE.'?state=expired')->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(1, 'data.filter_options.terms');
    }
    public function test_query_count_does_not_grow_per_case(): void
    {
        $this->grant();
        $count = function (): int { DB::enableQueryLog(); DB::flushQueryLog(); $this->getJson(self::BASE)->assertOk(); $queries = count(DB::getQueryLog()); DB::disableQueryLog(); return $queries; };
        $one = $count(); $this->grant(2); $two = $count(); self::assertSame($one, $two); self::assertLessThan(65, $two);
    }
    public function test_scope_loss_denies_detail_registration_and_list_contains_no_inaccessible_options(): void
    {
        $case = $this->grant(); DB::table('user_access_scopes')->delete();
        $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
        $this->getJson(self::BASE.'/'.$case['case']['incomplete_case_id'])->assertForbidden();
        $response = $this->getJson(self::BASE);
        if ($response->status() === 200) $response->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.filter_options.terms');
        else $response->assertForbidden();
    }
    public function test_components_configuration_is_not_invented_and_read_remains_available(): void
    {
        $this->grant(); DB::table('grade_components')->delete();
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.resolution.available', false)
            ->assertJsonPath('data.resolution.blocking_code', 'grade_components_not_configured')->assertJsonCount(0, 'data.components');
    }
    public function test_expired_result_read_is_terminal_and_student_transcript_does_not_expose_internal_case(): void
    {
        $case = $this->grant(); $this->travelTo(CarbonImmutable::parse('2026-10-22', 'UTC'));
        app(\App\Services\IncompleteResultService::class)->expire($case['case']['incomplete_case_id']);
        $this->getJson(self::BASE.'/'.$case['case']['incomplete_case_id'])->assertOk()->assertJsonPath('data.state', 'incomplete_expired')
            ->assertJsonPath('data.current_result.letter_grade', 'F')->assertJsonPath('data.allowed_operations.resolve', false)
            ->assertJsonPath('data.components.0.editable', false);
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $transcript = app(\App\Services\GradeService::class)->getTranscript(\App\Models\Student::findOrFail(1));
        self::assertStringNotContainsString('سبب داخلي', json_encode($transcript)); self::assertStringNotContainsString('متطلبات داخلية', json_encode($transcript));
        self::assertArrayNotHasKey('reason', $transcript['terms'][0]['courses'][0]['result_lifecycle']);
    }
    public function test_completed_registration_can_be_resolved_without_ordinary_editor(): void
    {
        DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['registration_status_id'=>4]);
        $case = $this->grant(); $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.resolution.available',true);
        $this->postJson(self::BASE.'/'.$case['case']['incomplete_case_id'].'/resolve', ['request_id'=>(string)Str::uuid(),'expected_revision'=>1,
            'components'=>[['grade_component_id'=>1,'mark'=>'50'],['grade_component_id'=>2,'mark'=>'30']]])->assertOk();
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.state','incomplete_resolved')->assertJsonPath('data.current_result.final_mark',80);
    }
    public function test_strict_filters_and_read_authorization_are_unchanged(): void
    {
        $this->getJson(self::BASE.'?q=all')->assertUnprocessable();
        $this->getJson(self::BASE.'?per_page=101')->assertUnprocessable();
        DB::table('role_permissions')->delete();
        $this->getJson(self::BASE)->assertForbidden(); $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
    }
    public function test_patch_deadline_returns_fresh_read_and_history_without_get_writes(): void
    {
        $case = $this->grant(); $id = $case['case']['incomplete_case_id'];
        $this->patchJson(self::BASE.'/'.$id.'/deadline', ['request_id'=>(string)Str::uuid(), 'expected_revision'=>1, 'deadline_at'=>'2026-11-01'])->assertOk();
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.case.revision', 2)->assertJsonPath('data.case.deadline_source','manual')
            ->assertJsonPath('data.events.1.action','deadline_changed')->assertJsonPath('data.events.1.after_values.deadline_at','2026-11-01T20:59:59.000000Z');
        $this->getJson(self::BASE)->assertOk();
        foreach (DB::getQueryLog() as $query) self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        DB::disableQueryLog(); self::assertSame(2, DB::table('student_course_incomplete_events')->count());
    }
}
