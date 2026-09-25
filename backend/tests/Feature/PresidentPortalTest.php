<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PresidentPortal;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;

/** Shared synthetic fixture, with independent president authorization expectations. */
final class PresidentPortalTest extends \Tests\TestCase
{
    use \Tests\Concerns\MinistryReadFixture;
    private const ROLE_MINISTRY = 2;
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        DB::table('roles')->insert(['role_id' => 20, 'role_code' => 'university_president', 'role_name' => 'Synthetic president', 'is_active' => 1]);
        DB::table('users')->insert(['user_id' => 20, 'username' => 'president.synthetic', 'email' => 'president@example.invalid', 'password_hash' => 'x', 'account_status_id' => 1]);
        DB::table('user_roles')->insert(['user_id' => 20, 'role_id' => 20, 'is_active' => 1]);
        DB::table('user_access_scopes')->insert(['user_id' => 20, 'scope_type' => 'university', 'scope_id' => 91, 'is_active' => 1]);
        foreach (PresidentPortal::permissions() as $code) {
            $id = DB::table('permissions')->insertGetId(['permission_code' => $code, 'permission_name' => $code, 'is_active' => 1], 'permission_id');
            DB::table('role_permissions')->insert(['role_id' => 20, 'permission_id' => $id]);
        }
        Schema::create('grade_components', function (Blueprint $t) { $t->increments('grade_component_id'); $t->integer('course_offering_id'); $t->string('component_type'); $t->boolean('is_required'); });
        Schema::create('grade_part_approvals', function (Blueprint $t) { $t->increments('grade_part_approval_id'); $t->integer('course_offering_id'); $t->string('component_type'); $t->string('status'); });
    }

    private function president(int $id = 20): void { $this->app['auth']->forgetGuards(); Sanctum::actingAs(User::findOrFail($id)); }

    public function test_president_access_is_assigned_scoped_and_read_only(): void
    {
        $paths = ['dashboard', 'reports', 'filters', 'students', 'colleges', 'programs', 'courses', 'faculty', 'deans', 'leadership', 'exams', 'results', 'followup'];
        $this->getJson('/api/v1/president/dashboard')->assertUnauthorized();
        foreach ([1, 2, 3, 4, 6, 8] as $id) {
            $this->president($id);
            foreach ($paths as $p) $this->getJson('/api/v1/president/'.$p)->assertForbidden();
        }
        $this->president();
        DB::enableQueryLog(); DB::flushQueryLog();
        foreach ($paths as $p) $this->getJson('/api/v1/president/'.$p)->assertOk();
        foreach (DB::getQueryLog() as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter)\b/i', $query['query']);
        $this->postJson('/api/v1/president/dashboard')->assertStatus(405);
        DB::table('user_access_scopes')->where('user_id', 20)->update(['is_active' => 0]);
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
        DB::table('user_access_scopes')->where('user_id', 20)->update(['is_active' => 1, 'scope_id' => 177]);
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
        DB::table('user_access_scopes')->where('user_id', 20)->update(['scope_id' => 91]);
        DB::table('users')->where('user_id', 20)->update(['account_status_id' => 2]);
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
    }

    public function test_president_cards_match_exact_lists_under_scope_and_period_filters(): void
    {
        $this->president();
        foreach (['', '?college_id=1', '?college_id=4', '?program_id=2', '?academic_year_id=10&semester_id=1', '?college_id=2&academic_year_id=10'] as $query) {
            $d = $this->getJson('/api/v1/president/dashboard'.$query)->assertOk()->json('data');
            foreach (array_merge($d['counts'], $d['period_metrics'] ?? []) as $card) {
                if (empty($card['link'])) continue;
                $this->assertStringStartsWith('/president/', $card['link']);
                $response = $this->getJson('/api/v1'. $card['link'])->assertOk();
                $this->assertSame($card['value'], $response->json('meta.total'), $card['link']);
            }
        }
        DB::table('academic_years')->update(['is_current' => 0]);
        $this->getJson('/api/v1/president/dashboard')->assertOk()->assertJsonPath('data.period.available', false)->assertJsonPath('data.period_metrics', null);
    }

    public function test_president_uses_official_results_and_keeps_part_states_separate(): void
    {
        $this->president();
        $d = $this->getJson('/api/v1/president/results')->assertOk()->json('data');
        $expected = \App\Services\Ministry\MinistryQueries::officialResults()->count();
        $this->assertCount($expected, $d);
        $offer = DB::table('course_offerings')->orderBy('course_offering_id')->value('course_offering_id');
        DB::table('grade_components')->insert([['course_offering_id' => $offer, 'component_type' => 'theoretical', 'is_required' => 1], ['course_offering_id' => $offer, 'component_type' => 'practical', 'is_required' => 1]]);
        $this->getJson('/api/v1/president/exams/'.$offer)->assertOk()->assertJsonCount(2, 'data.parts')->assertJsonPath('data.parts.0.status', 'draft');
        $this->getJson('/api/v1/president/results?secret=1')->assertUnprocessable();
        $this->getJson('/api/v1/president/exams?semester_id=1')->assertUnprocessable();
        $this->getJson('/api/v1/president/students?per_page=101')->assertUnprocessable();
        foreach (['students/1', 'colleges/1', 'programs/2', 'faculty/2', 'deans/employee-1', 'leadership/93', 'courses/4'] as $path) $this->getJson('/api/v1/president/'.$path)->assertOk();
    }

    public function test_president_section_permission_and_followup_do_not_grant_decision_authority(): void
    {
        $this->president();
        $this->getJson('/api/v1/president/followup')->assertOk()->assertJsonPath('data.inbox.available', false)->assertJsonCount(0, 'data.inbox.items');
        $this->postJson('/api/v1/president/followup/grades/1/approve')->assertNotFound();
        $permission = DB::table('permissions')->where('permission_code', 'president_portal.students.view')->value('permission_id');
        DB::table('role_permissions')->where('role_id', 20)->where('permission_id', $permission)->delete();
        $this->president(); $this->getJson('/api/v1/president/students')->assertForbidden();
        $this->getJson('/api/v1/president/dashboard')->assertOk();
        // Neither super-admin's virtual grants nor role alone restore the missing permission.
        DB::table('user_roles')->insert(['user_id' => 20, 'role_id' => 1, 'is_active' => 1]);
        $this->president(); $this->getJson('/api/v1/president/students')->assertForbidden();
    }

    public function test_assigned_permissions_without_actual_president_role_and_role_without_access_are_denied(): void
    {
        // Same active scoped identity with every assigned permission, but not the president role.
        DB::table('roles')->where('role_id', 20)->update(['role_code' => 'synthetic_reader']);
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
        DB::table('roles')->where('role_id', 20)->update(['role_code' => 'university_president', 'is_active' => 0]);
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
        DB::table('roles')->where('role_id', 20)->update(['is_active' => 1]);
        $permission = DB::table('permissions')->where('permission_code', 'president_portal.access')->value('permission_id');
        DB::table('role_permissions')->where('role_id', 20)->where('permission_id', $permission)->delete();
        $this->president(); $this->getJson('/api/v1/president/dashboard')->assertForbidden();
    }

    public function test_returned_latest_approval_removes_official_results_and_progression_is_materialized_only(): void
    {
        $this->president();
        $official = \App\Services\Ministry\MinistryQueries::officialResults()->first(['r.student_course_result_id', 'co.course_offering_id']);
        $this->assertNotNull($official);
        $this->getJson('/api/v1/president/results/'.$official->student_course_result_id)->assertOk();
        DB::table('grade_approvals')->insert(['grade_approval_id' => 1000, 'course_offering_id' => $official->course_offering_id, 'approval_status_id' => 4]);
        $this->getJson('/api/v1/president/results/'.$official->student_course_result_id)->assertNotFound();
        $this->getJson('/api/v1/president/exams/'.$official->course_offering_id)->assertOk()->assertJsonPath('data.final_approval_status', 'returned_for_correction');
        Schema::create('student_progression_decisions', function (Blueprint $t) {
            $t->increments('student_progression_decision_id'); $t->integer('student_id'); $t->string('status');
            $t->string('decision_result'); $t->timestamp('approved_at')->nullable(); $t->timestamp('materialized_at')->nullable(); $t->timestamp('superseded_at')->nullable();
        });
        foreach ([['approved', '2026-01-01', null], ['returned', null, null], ['approved', '2026-01-01', '2026-01-02']] as [$status, $materialized, $superseded]) {
            DB::table('student_progression_decisions')->insert(['student_id' => 1, 'status' => $status, 'decision_result' => 'promoted', 'approved_at' => $materialized, 'materialized_at' => $materialized, 'superseded_at' => $superseded]);
        }
        $this->getJson('/api/v1/president/students/1')->assertOk()->assertJsonCount(1, 'data.progression')->assertJsonPath('data.progression.0.decision_result', 'promoted');
        $this->getJson('/api/v1/president/followup/progression')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/president/followup/progression/1')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_all_detail_projections_and_synthetic_browser_fixture(): void
    {
        $this->president();
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => '2025-08-31']);
        $responses = [];
        foreach (['dashboard','reports','filters','followup'] as $path) $responses[$path] = $this->getJson('/api/v1/president/'.$path)->assertOk()->json();
        foreach (\App\Http\Controllers\Api\PresidentPortalController::RESOURCES as $resource => $section) {
            $response = $this->getJson('/api/v1/president/'.$resource)->assertOk()->json();
            $responses[$resource] = $response;
            foreach ($response['data'] as $row) {
                $id = match ($resource) { 'students' => $row['student_id'], 'faculty' => $row['faculty_member_id'], 'deans' => $row['person'], 'courses' => $row['course_id'], 'colleges' => $row['college_id'], default => $row['id'] };
                $responses[$resource.'/'.$id] = $this->getJson('/api/v1/president/'.$resource.'/'.$id)->assertOk()->json();
            }
        }
        foreach (\App\Services\President\PresidentFollowupService::WORKFLOWS as $source => $_) $responses['followup/'.$source] = $this->getJson('/api/v1/president/followup/'.$source)->assertOk()->json();
        $this->assertTrue($responses['deans/employee-1']['data']['dean_assignments'][0]['has_conflict']);
        $this->assertSame('2025-08-31', $responses['deans/employee-1']['data']['dean_assignments'][0]['end_date']);
        $this->assertFalse($responses['leadership/92']['data']['role']['exists']);
        $this->assertSame([], $responses['leadership/92']['data']['holders']);
        $json = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/"(email|phone_number|password_hash|username|national_civil_id|review_notes)"/', $json);
        // Optional browser artifact, created ONLY by this isolated synthetic SQLite test.
        if ($path = getenv('PRESIDENT_BROWSER_FIXTURE')) file_put_contents($path, $json);
    }
}
