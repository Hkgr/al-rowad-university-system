<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdministrativeGovernanceSchema;
use Tests\TestCase;

/**
 * Administrative VP review of teaching-assignment requests over real HTTP + SQLite:
 * queue filters, viewer_context agreeing with the server decision, submission-version
 * guard, distinct approvers and idempotent repeats. The workflow itself is unchanged.
 *
 * Fixture requests: #1 offering 1 (college 1) pending both reviews, #2 offering 3
 * (college 1) returned by the administrative office, #3 offering 2 (college 2)
 * approved, #4 a superseded earlier cycle of offering 1.
 */
final class TeachingAssignmentAdministrativeReviewTest extends TestCase
{
    use AdministrativeGovernanceSchema;

    private const API = '/api/v1/vice-presidency/teaching-assignments';

    private const SUPER = 1;
    private const VP = 2;
    private const VP_SCI = 10;
    private const BOTH_VPS = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAdministrativeGovernanceSchema();
        $this->seedAdministrativeGovernanceFixture();
        DB::table('teaching_assignment_requests')->where('teaching_assignment_request_id', 4)->update(['superseded_by_request_id' => 1, 'status' => 'superseded']);
        // User 7 holds both VP roles (a multi-role account) to exercise the distinct-approver rule.
        DB::table('user_roles')->insert(['user_id' => self::BOTH_VPS, 'role_id' => 4, 'is_active' => 1]);
    }

    public function test_queue_filters_match_the_dashboard_definitions(): void
    {
        $this->actingAsUser(self::VP);
        $this->assertIds([1], ['queue' => 'pending']);
        $this->assertIds([2], ['queue' => 'returned']);
        $this->assertIds([3], ['queue' => 'approved']);
        $this->assertIds([1, 2, 3], ['queue' => 'all']);
        $this->assertIds([1, 2], ['queue' => 'all', 'college_id' => 1]);
        $this->assertIds([3], ['queue' => 'all', 'college_id' => 2]);
        $this->assertIds([2], ['queue' => 'all', 'search' => 'ACC102']);
        $this->assertIds([1, 2, 3], ['queue' => 'all', 'search' => 'TeacherA']);
        $this->assertIds([1, 2, 3], ['queue' => 'all', 'search' => 'dean.a']);
        $this->assertIds([], ['queue' => 'all', 'search' => '%']);
        $this->assertIds([1, 2, 3], ['queue' => 'all', 'academic_year_id' => 1, 'semester_id' => 1]);
        $this->assertIds([], ['queue' => 'all', 'academic_year_id' => 2]);
        $this->assertIds([1, 2], ['queue' => 'all', 'department_id' => 1]);
        $this->assertIds([2], ['queue' => 'all', 'status' => 'returned']);
        $this->assertIds([], ['queue' => 'all', 'instructor_role' => 'practical']);

        $dashboard = $this->getJson('/api/v1/vice-presidency/administrative/dashboard')->assertOk()->json('data.teaching_assignments.totals');
        self::assertSame(['pending' => 1, 'returned' => 1, 'approved' => 1], $dashboard);
    }

    public function test_viewer_context_matches_the_server_decision_for_every_state(): void
    {
        $this->actingAsUser(self::VP);
        $rows = collect($this->getJson(self::API.'?authority=administrative&queue=all')->assertOk()->json('data.data'))->keyBy('teaching_assignment_request_id');

        self::assertTrue($rows[1]['viewer_context']['can_approve']);
        self::assertSame('awaits_other_office', $rows[1]['viewer_context']['approval_effect']);
        self::assertFalse($rows[2]['viewer_context']['can_approve']);
        self::assertSame('review_locked', $rows[2]['viewer_context']['blocked_reason']);
        self::assertFalse($rows[3]['viewer_context']['can_approve']);
        self::assertSame('already_effective', $rows[3]['viewer_context']['blocked_reason']);

        $this->postJson(self::API.'/2/administrative/approve')->assertStatus(409)->assertJsonPath('error_code', 'teaching_assignment_review_locked');
        $this->postJson(self::API.'/3/administrative/return', ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'teaching_assignment_already_effective');

        $superseded = $this->getJson(self::API.'/4?authority=administrative')->assertOk()->json('data.viewer_context');
        self::assertSame('superseded', $superseded['blocked_reason']);
        $this->postJson(self::API.'/4/administrative/approve')->assertStatus(409)->assertJsonPath('error_code', 'teaching_assignment_superseded');
    }

    public function test_stale_submission_version_is_refused_and_nothing_changes(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/1/administrative/approve', ['expected_submission_version' => 2])
            ->assertStatus(409)->assertJsonPath('error_code', 'teaching_assignment_version_mismatch');
        self::assertSame('pending', $this->reviewStatus(1, 'administrative'));

        $response = $this->postJson(self::API.'/1/administrative/approve', ['expected_submission_version' => 1])->assertOk();
        self::assertSame('approved', $response->json('data.viewer_context.own_review_status'));
        self::assertFalse($response->json('data.viewer_context.can_approve'));
        self::assertSame('approved', $this->reviewStatus(1, 'administrative'));
        self::assertSame(0, DB::table('course_offering_instructors')->count(), 'one approval never makes the assignment effective');

        // Repeating the same approval is idempotent.
        $this->postJson(self::API.'/1/administrative/approve', ['expected_submission_version' => 1])->assertOk();
        self::assertSame(1, DB::table('teaching_assignment_events')->where('teaching_assignment_request_id', 1)->where('event_type', 'administrative_approved')->count());
    }

    public function test_second_approval_makes_it_effective_only_through_the_workflow(): void
    {
        $this->actingAsUser(self::VP_SCI);
        $this->postJson(self::API.'/1/scientific/approve', ['expected_submission_version' => 1])->assertOk();

        $this->actingAsUser(self::VP);
        $context = $this->getJson(self::API.'/1?authority=administrative')->assertOk()->json('data.viewer_context');
        self::assertSame('effective_now', $context['approval_effect']);
        $this->postJson(self::API.'/1/administrative/approve', ['expected_submission_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        self::assertSame(1, DB::table('course_offering_instructors')->where('course_offering_id', 1)->where('faculty_member_id', 1)->where('is_active', 1)->count());
    }

    public function test_the_same_account_cannot_approve_for_both_offices(): void
    {
        DB::table('role_permissions')->insert(['role_id' => 2, 'permission_id' => 11]);
        $this->actingAsUser(self::BOTH_VPS);
        $this->postJson(self::API.'/1/scientific/approve')->assertOk();

        $context = $this->getJson(self::API.'/1?authority=administrative')->assertOk()->json('data.viewer_context');
        self::assertFalse($context['can_approve']);
        self::assertTrue($context['can_return']);
        self::assertSame('same_reviewer', $context['blocked_reason']);
        $this->postJson(self::API.'/1/administrative/approve')->assertStatus(409)->assertJsonPath('error_code', 'teaching_assignment_same_reviewer_forbidden');
        self::assertSame('pending', $this->reviewStatus(1, 'administrative'));
    }

    public function test_return_requires_a_reason_and_super_admin_reads_but_cannot_decide(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/1/administrative/return', ['reason' => ''])->assertStatus(422);
        $this->postJson(self::API.'/1/administrative/return', ['reason' => 'عبء المدرس مرتفع', 'expected_submission_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'returned');

        $this->actingAsUser(self::SUPER);
        $this->getJson(self::API.'?authority=administrative&queue=all')->assertOk()
            ->assertJsonPath('data.data.0.viewer_context.blocked_reason', 'not_reviewer');
        $this->postJson(self::API.'/3/administrative/approve')->assertForbidden()->assertJsonPath('error_code', 'administrative_review_forbidden');
    }

    public function test_detail_exposes_previous_cycle_and_teacher_data(): void
    {
        $this->actingAsUser(self::VP);
        $detail = $this->getJson(self::API.'/1?authority=administrative')->assertOk()->json('data');
        self::assertSame(4, $detail['previous_requests'][0]['teaching_assignment_request_id'] ?? null);
        self::assertArrayHasKey('removal_target', $detail);
        self::assertSame('مدرس', $detail['proposed_faculty_member']['academic_rank'] ?? null);
    }

    public function test_reviewer_without_university_scope_or_permission_cannot_read_the_queue(): void
    {
        $this->actingAsUser(5);
        $this->getJson(self::API.'?authority=administrative')->assertForbidden();
        DB::table('role_permissions')->where('role_id', 2)->where('permission_id', 6)->delete();
        $this->actingAsUser(self::VP);
        $this->getJson(self::API.'?authority=administrative')->assertForbidden();
    }

    /** @param list<int> $ids */
    private function assertIds(array $ids, array $query): void
    {
        $data = $this->getJson(self::API.'?'.http_build_query(['authority' => 'administrative'] + $query))->assertOk()->json('data.data');
        $actual = collect($data)->pluck('teaching_assignment_request_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        self::assertSame($ids, $actual, json_encode($query));
    }

    private function reviewStatus(int $requestId, string $authority): string
    {
        return (string) DB::table('teaching_assignment_reviews')->where('teaching_assignment_request_id', $requestId)->where('review_authority', $authority)->value('status');
    }

    private function actingAsUser(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId));
    }
}
