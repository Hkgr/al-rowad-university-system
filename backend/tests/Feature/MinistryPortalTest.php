<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ministry of Education portal: access, confinement, indicator accuracy and data minimisation.
 * Real HTTP + an isolated SQLite fixture with synthetic data only.
 *
 * Fixture traps (each must NOT inflate a number):
 *  - a soft-deleted student with results and a graduation decision;
 *  - an inactive faculty member, an expired unit assignment, a member affiliated with two colleges;
 *  - program 2 with three plan versions (old approved, default approved, draft);
 *  - an offering whose latest approval is "returned" after an earlier approval, a pending offering,
 *    an offering without approval, a dropped registration with a result;
 *  - a superseded and a merely submitted graduation decision; a non-finalized academic term.
 */
final class MinistryPortalTest extends TestCase
{
    use \Tests\Concerns\MinistryReadFixture;
    private const API = '/api/v1/ministry';

    private const ADMIN = 1;
    private const MINISTRY = 2;
    private const REGISTRAR = 3;
    private const DEAN_A = 4;
    private const DEAN_B = 5;
    private const VP = 6;
    private const MINISTRY_DISABLED = 7;
    private const PLAIN = 8;

    private const ROLE_MINISTRY = 2;
    private const ROLE_DEAN = 3;

    private const PORTAL_PAGES = ['filters', 'dashboard', 'students', 'students/1', 'colleges', 'colleges/1', 'courses', 'courses/4',
        'faculty', 'faculty/2', 'deans', 'deans/employee-1', 'leadership', 'leadership/units/93'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    // ── access ───────────────────────────────────────────────────────────

    public function test_ministry_account_reads_every_portal_page(): void
    {
        $this->actingAsUser(self::MINISTRY);
        foreach (self::PORTAL_PAGES as $page) {
            $this->getJson(self::API.'/'.$page)->assertOk()->assertJsonStructure(['data']);
        }
        $this->getJson(self::API.'/students/999')->assertNotFound()->assertJsonPath('error_code', 'not_found');
        $this->getJson(self::API.'/deans/employee-4')->assertNotFound();
    }

    public function test_every_other_account_is_refused_by_the_server_whatever_its_role(): void
    {
        $this->getJson(self::API.'/dashboard')->assertUnauthorized();
        foreach ([self::ADMIN, self::REGISTRAR, self::DEAN_A, self::VP, self::PLAIN] as $user) {
            $this->actingAsUser($user);
            foreach (self::PORTAL_PAGES as $page) {
                $this->getJson(self::API.'/'.$page)->assertForbidden()->assertJsonPath('error_code', 'ministry_portal_forbidden');
            }
        }
        // super_admin has no bypass: the permission must come from the ministry role itself.
        $this->actingAsUser(self::MINISTRY_DISABLED);
        $this->getJson(self::API.'/dashboard')->assertForbidden();
    }

    public function test_access_fails_closed_on_misconfiguration(): void
    {
        // A ministry permission granted through another role does not open the portal.
        DB::table('role_permissions')->insert(['role_id' => self::ROLE_DEAN, 'permission_id' => 4, 'granted_at' => now()]);
        $this->actingAsUser(self::DEAN_A);
        $this->getJson(self::API.'/students')->assertForbidden();

        // Each page needs its own permission.
        $this->actingAsUser(self::MINISTRY);
        DB::table('role_permissions')->where('role_id', self::ROLE_MINISTRY)->where('permission_id', 4)->delete();
        $this->getJson(self::API.'/students')->assertForbidden();
        $this->getJson(self::API.'/dashboard')->assertOk();

        // A write/other permission mapped to the ministry role closes the whole portal.
        DB::table('role_permissions')->insert(['role_id' => self::ROLE_MINISTRY, 'permission_id' => 9, 'granted_at' => now()]);
        $this->getJson(self::API.'/dashboard')->assertForbidden();
        DB::table('role_permissions')->where('role_id', self::ROLE_MINISTRY)->where('permission_id', 9)->delete();
        $this->getJson(self::API.'/dashboard')->assertOk();

        // Inactive role or revoked assignment.
        DB::table('roles')->where('role_id', self::ROLE_MINISTRY)->update(['is_active' => 0]);
        $this->getJson(self::API.'/dashboard')->assertForbidden();
        DB::table('roles')->where('role_id', self::ROLE_MINISTRY)->update(['is_active' => 1]);
        DB::table('user_roles')->where('user_id', self::MINISTRY)->update(['is_active' => 0]);
        $this->getJson(self::API.'/dashboard')->assertForbidden();
    }

    public function test_ministry_token_is_refused_on_every_other_route_before_any_controller_runs(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $before = $this->dataSnapshot();
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/ministry')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $url = $this->concreteUrl($route, $method);
                if ($url === null) {
                    continue;
                }
                $response = $this->json($method, $url, ['anything' => 1]);
                $this->assertSame(403, $response->status(), "{$method} {$uri} must be forbidden for the ministry account");
                $this->assertSame('ministry_account_confined', $response->json('error_code'), "{$method} {$uri}");
                $checked++;
            }
        }
        $this->assertGreaterThan(500, $checked, 'the sweep covers the whole v1 API');
        $this->assertSame($before, $this->dataSnapshot(), 'no data changed during the sweep');

        // Ministry routes are GET-only.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, self::API.'/students/1')->assertStatus(405);
            $this->json($method, self::API.'/dashboard')->assertStatus(405);
        }
    }

    // ── indicators vs independent queries ─────────────────────────────────

    public function test_dashboard_counts_match_independent_queries_and_ignore_the_traps(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $d = $this->getJson(self::API.'/dashboard')->assertOk()->json('data');

        $independent = [
            'colleges' => $this->scalar('SELECT COUNT(*) FROM colleges WHERE is_active = 1'),
            'students' => $this->scalar('SELECT COUNT(*) FROM students WHERE deleted_at IS NULL'),
            'active_students' => $this->scalar("SELECT COUNT(*) FROM students s JOIN student_statuses ss ON ss.student_status_id = s.student_status_id WHERE s.deleted_at IS NULL AND ss.status_code = 'active'"),
            'faculty' => $this->scalar('SELECT COUNT(*) FROM faculty_members WHERE is_active = 1'),
            'courses' => $this->scalar('SELECT COUNT(*) FROM courses WHERE is_active = 1'),
            'programs' => $this->scalar('SELECT COUNT(*) FROM academic_programs WHERE is_active = 1 AND archived_at IS NULL'),
            'vice_presidents' => $this->scalar("SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id JOIN users u ON u.user_id = ur.user_id JOIN account_statuses a ON a.account_status_id = u.account_status_id WHERE ur.is_active = 1 AND r.is_active = 1 AND a.status_code = 'active' AND r.role_code IN ('vice_president_scientific','vice_president_administrative','vice_president')"),
        ];
        foreach ($independent as $key => $value) {
            $this->assertSame($value, $d['counts'][$key]['value'], $key);
        }
        // Hand-checked expectations of the synthetic scenario (documents the traps).
        $this->assertSame(['colleges' => 3, 'students' => 7, 'active_students' => 4, 'faculty' => 4, 'courses' => 7, 'programs' => 2, 'vice_presidents' => 1], $independent);
        $this->assertSame(1, $d['counts']['colleges']['inactive']);
        $this->assertSame(2, $d['counts']['deans']['value'], 'current deans: role+scope (college 1) and account-only (college 2); the ended position is historical');
        $this->assertSame(1, $d['counts']['deans']['colleges_without_dean']);

        // Period = current academic year (11) by default.
        $this->assertSame('current', $d['period']['source']);
        $this->assertSame(11, $d['period']['academic_year']['id']);
        $pm = $d['period_metrics'];
        $this->assertSame($this->scalar("SELECT COUNT(DISTINCT scr.student_id) FROM student_course_registrations scr JOIN registration_statuses rs ON rs.registration_status_id = scr.registration_status_id JOIN course_offerings co ON co.course_offering_id = scr.course_offering_id JOIN students s ON s.student_id = scr.student_id WHERE s.deleted_at IS NULL AND rs.status_code IN ('registered','completed') AND co.academic_year_id = 11"), $pm['registered_students']['value']);
        $this->assertSame(3, $pm['registered_students']['value']);
        $official = $this->independentOfficial('co.academic_year_id = 11');
        $this->assertSame($official['total'], $pm['official_results']['value']);
        $this->assertSame(['total' => 2, 'passed' => 1], $official, 'pending and unapproved offerings are excluded');
        $this->assertSame(1, $pm['official_results']['deprived']);
        $this->assertSame(50.0, (float) $pm['official_results']['pass_rate']);
        $this->assertSame(1, $pm['graduates']['value'], 'superseded, submitted and soft-deleted decisions are excluded');
        $this->assertSame(3, $pm['offerings']['value']);

        // Year 10: the offering returned after approval is not official any more.
        $y10 = $this->getJson(self::API.'/dashboard?academic_year_id=10')->json('data.period_metrics');
        $this->assertSame($this->independentOfficial('co.academic_year_id = 10'), ['total' => $y10['official_results']['value'], 'passed' => $y10['official_results']['passed']]);
        $this->assertSame(['total' => 2, 'passed' => 1], $this->independentOfficial('co.academic_year_id = 10'));
        $this->assertSame(2, $y10['registered_students']['value'], 'deleted student excluded');

        // Semester filter: graduates are yearly, shown as unavailable rather than 0.
        $sem = $this->getJson(self::API.'/dashboard?academic_year_id=11&semester_id=1')->json('data.period_metrics');
        $this->assertNull($sem['graduates']['value']);
        $this->assertNotEmpty($sem['graduates']['reason']);

        // Distributions add up to the population and use Arabic labels.
        foreach (['by_college', 'by_program', 'by_status', 'by_level'] as $dist) {
            $this->assertSame(7, array_sum(array_column($d['distributions'][$dist], 'total')), $dist);
        }
        $this->assertContains('نشط', array_column($d['distributions']['by_status'], 'label'));

        // Trends: official results by term never include draft/pending grades.
        $terms = collect($d['trends']['official_results_by_term'])->keyBy(fn ($t) => $t['year_id'].'-'.$t['semester_id']);
        $this->assertSame(2, $terms['10-1']['total']);
        $this->assertFalse($terms->has('10-2'), 'returned-after-approval offering');
        $this->assertSame(2, $terms['11-1']['total']);
        $this->assertSame([10 => 4, 11 => 3], collect($d['trends']['intake_by_year'])->mapWithKeys(fn ($r) => [(int) $r['key'] => $r['total']])->all());
        $this->assertSame([10 => 0, 11 => 1], collect($d['trends']['graduates_by_year'])->mapWithKeys(fn ($r) => [(int) $r['key'] => $r['total']])->all());
    }

    public function test_college_and_program_filters_and_plan_versions_do_not_double_count(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $college = $this->getJson(self::API.'/dashboard?college_id=1')->assertOk()->json('data.counts');
        $this->assertSame(3, $college['students']['value']);
        $this->assertSame(2, $college['faculty']['value'], 'home unit + open assignment; inactive member excluded');
        $this->assertSame(3, $college['courses']['value'], 'active courses owned by the college departments');
        $this->assertSame(1, $college['deans']['value']);

        $program = $this->getJson(self::API.'/dashboard?program_id=2')->assertOk()->json('data');
        $this->assertSame(2, $program['counts']['courses']['value'], 'only the default plan version (not the old approved or draft versions)');
        $this->assertSame($this->scalar('SELECT COUNT(DISTINCT pc.course_id) FROM program_courses pc JOIN academic_programs ap ON ap.academic_program_id = pc.academic_program_id WHERE pc.academic_program_id = 2 AND pc.is_active = 1 AND pc.academic_plan_version_id = ap.default_academic_plan_version_id'), $program['counts']['courses']['value']);
        $this->assertNotNull($program['counts']['faculty']['note']);

        $this->getJson(self::API.'/dashboard?college_id=1&program_id=2')->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->getJson(self::API.'/dashboard?college_id=99')->assertUnprocessable();

        $multi = $this->getJson(self::API.'/faculty?college_id=2&active=1')->json();
        $this->assertSame(1, $multi['meta']['total']);
        $this->assertSame(['كلية ألف', 'كلية باء'], collect($multi['data'][0]['colleges'])->pluck('name')->sort()->values()->all());
        $expired = $this->getJson(self::API.'/faculty?search='.urlencode('خالد'))->json();
        $this->assertSame(1, $expired['meta']['total']);
        $this->assertSame([], $expired['data'][0]['colleges'], 'an expired unit assignment gives no college');
    }

    public function test_every_dashboard_number_equals_the_total_of_the_list_it_links_to(): void
    {
        $this->actingAsUser(self::MINISTRY);
        foreach (['', '?academic_year_id=10', '?academic_year_id=11&semester_id=1', '?college_id=1', '?program_id=2', '?college_id=2&academic_year_id=11'] as $query) {
            $d = $this->getJson(self::API.'/dashboard'.$query)->assertOk()->json('data');
            $items = [];
            foreach (array_merge($d['counts'], $d['period_metrics'] ?? []) as $key => $item) {
                $items[$key] = $item;
            }
            foreach ($d['distributions'] as $name => $rows) {
                foreach ($rows as $row) {
                    $items[$name.':'.$row['key']] = ['value' => $row['total'], 'link' => $row['link'] ?? null];
                }
            }
            $linked = 0;
            foreach ($items as $key => $item) {
                $link = $item['link'] ?? null;
                if ($link === null || ($item['value'] ?? null) === null) {
                    continue;
                }
                $this->assertMatchesRegularExpression('#^/ministry/(colleges|students|faculty|courses|deans)(\?.*)?$#', $link, 'Every linked number must have an exact, testable list');
                preg_match('#^/ministry/(colleges|students|faculty|courses|deans)(\?.*)?$#', $link, $m);
                $total = $this->getJson(self::API.'/'.$m[1].($m[2] ?? '?').(isset($m[2]) ? '&' : '').'per_page=1')->assertOk()->json('meta.total');
                $this->assertSame($item['value'], $total, "{$query} {$key} → {$link}");
                $linked++;
            }
            $this->assertGreaterThan(5, $linked);
        }
    }

    // ── lists: search, filters, pagination ────────────────────────────────

    public function test_college_card_matches_filtered_directory_including_inactive_college_selection(): void
    {
        $this->actingAsUser(self::MINISTRY);
        foreach (['' => 3, '?college_id=1' => 1, '?college_id=4' => 0, '?program_id=2' => 1] as $query => $expected) {
            $counts = $this->getJson(self::API.'/dashboard'.$query)->assertOk()->json('data.counts');
            $link = $counts['colleges']['link'];
            $this->assertStringContainsString('active=1', $link);
            if ($query === '?college_id=1') $this->assertStringContainsString('college_id=1', $link);
            if ($query === '?program_id=2') $this->assertStringContainsString('college_id=2', $link);
            $list = $this->getJson('/api/v1'.$link)->assertOk();
            $this->assertSame($expected, $counts['colleges']['value']);
            $list->assertJsonPath('meta.total', $expected)->assertJsonCount($expected, 'data');
            foreach ($list->json('data') as $row) $this->assertTrue($row['is_active']);
            foreach (['departments', 'programs', 'vice_presidents'] as $key) $this->assertNull($counts[$key]['link']);
        }
        $this->getJson(self::API.'/colleges')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson(self::API.'/colleges?active=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.college_id', 4);
        $this->getJson(self::API.'/colleges?college_id=4')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::API.'/colleges?active=wrong')->assertUnprocessable();
    }

    public function test_dean_dates_and_independent_evidence_agree_in_list_detail_and_dashboard(): void
    {
        $this->actingAsUser(self::MINISTRY);
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => '2025-08-31']);
        $assertRow = function (bool $role, bool $scope, bool $account, string $position, string $state, ?string $end, int $total) {
            $list = $this->getJson(self::API.'/deans?college_id=1')->assertOk()->json('data');
            $row = collect($list)->firstWhere('person', 'employee-1');
            $detail = $this->getJson(self::API.'/deans/employee-1')->assertOk()->json('data');
            $this->assertSame($row, $detail['dean_assignments'][0]);
            $this->assertSame([$role, $scope, $account, $position, $state, '2024-09-01', $end], [
                $row['role_active'], $row['college_scope_active'], $row['account_current'], $row['position_state'], $row['state'], $row['start_date'], $row['end_date'],
            ]);
            $this->assertSame($end, $detail['positions'][0]['end_date']);
            $this->getJson(self::API.'/dashboard?college_id=1')->assertOk()->assertJsonPath('data.counts.deans.value', $total);
            $this->getJson(self::API.'/deans?college_id=1&state=current')->assertOk()->assertJsonPath('meta.total', $total);
            return $row;
        };
        // Account still authorized, but actual position ended: retain the date and show conflict.
        $row = $assertRow(true, true, true, 'ended', 'current', '2025-08-31', 1);
        $this->assertTrue($row['has_conflict']);
        $this->assertStringContainsString('تعارض', implode(' ', $row['notes']));
        // Open position, no active dean role: current via position only, not account authority.
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => null]);
        DB::table('user_roles')->where('user_id', self::DEAN_A)->update(['is_active' => 0]);
        $row = $assertRow(false, true, false, 'active', 'current', null, 1);
        $this->assertNotEmpty($row['notes']);
        // Both ended; an unrelated still-active scope does not make the dean current.
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => '2025-08-31', 'is_active' => 0]);
        $assertRow(false, true, false, 'ended', 'historical', '2025-08-31', 0);
        // Active role alone cannot hide a missing/revoked college scope or inactive account.
        DB::table('user_roles')->where('user_id', self::DEAN_A)->update(['is_active' => 1]);
        DB::table('user_access_scopes')->where('user_id', self::DEAN_A)->update(['is_active' => 0]);
        $assertRow(true, false, false, 'ended', 'historical', '2025-08-31', 0);
        DB::table('user_access_scopes')->where('user_id', self::DEAN_A)->update(['is_active' => 1]);
        DB::table('users')->where('user_id', self::DEAN_A)->update(['account_status_id' => 2]);
        $assertRow(true, true, false, 'ended', 'historical', '2025-08-31', 0);
    }

    public function test_active_position_keeps_its_recorded_future_end_and_account_only_has_no_invented_dates(): void
    {
        $this->actingAsUser(self::MINISTRY);
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => '2030-08-31']);
        $this->getJson(self::API.'/deans/employee-1')->assertOk()
            ->assertJsonPath('data.dean_assignments.0.position_state', 'active')
            ->assertJsonPath('data.dean_assignments.0.end_date', '2030-08-31');
        $this->getJson(self::API.'/deans/account-5')->assertOk()
            ->assertJsonPath('data.dean_assignments.0.position_state', 'not_recorded')
            ->assertJsonPath('data.dean_assignments.0.start_date', null)->assertJsonPath('data.dean_assignments.0.end_date', null);
        DB::table('employee_positions')->where('employee_id', 1)->update(['start_date' => '2029-09-01']);
        DB::table('user_roles')->where('user_id', self::DEAN_A)->update(['is_active' => 0]);
        $this->getJson(self::API.'/deans/employee-1')->assertOk()
            ->assertJsonPath('data.dean_assignments.0.position_state', 'scheduled')
            ->assertJsonPath('data.dean_assignments.0.state', 'historical')
            ->assertJsonPath('data.positions.0.state_label', 'لم يبدأ بعد');
    }

    public function test_dean_currentness_never_combines_role_and_scope_from_different_accounts(): void
    {
        $this->actingAsUser(self::MINISTRY);
        DB::table('employee_positions')->where('employee_id', 1)->update(['end_date' => '2025-08-31']);
        DB::table('user_access_scopes')->where('user_id', self::DEAN_A)->update(['is_active' => 0]);
        DB::table('users')->where('user_id', self::DEAN_B)->update(['employee_id' => 1]);
        DB::table('user_roles')->where('user_id', self::DEAN_B)->update(['is_active' => 0]);
        DB::table('user_access_scopes')->where('user_id', self::DEAN_B)->update(['scope_id' => 1]);
        $this->getJson(self::API.'/deans/employee-1')->assertOk()
            ->assertJsonPath('data.dean_assignments.0.role_active', true)
            ->assertJsonPath('data.dean_assignments.0.college_scope_active', true)
            ->assertJsonPath('data.dean_assignments.0.account_current', false)
            ->assertJsonPath('data.dean_assignments.0.state', 'historical');
        $this->getJson(self::API.'/dashboard?college_id=1')->assertOk()->assertJsonPath('data.counts.deans.value', 0);
    }

    public function test_lists_search_filter_and_paginate(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $page1 = $this->getJson(self::API.'/students?per_page=3')->assertOk()->json();
        $page3 = $this->getJson(self::API.'/students?per_page=3&page=3')->json();
        $this->assertSame(['current_page' => 1, 'per_page' => 3, 'total' => 7, 'last_page' => 3], $page1['meta']);
        $this->assertCount(1, $page3['data']);
        $all = collect($this->getJson(self::API.'/students?per_page=100')->json('data'))->pluck('student_id');
        $this->assertSame($all->count(), $all->unique()->count());
        $this->assertNotContains(7, $all->all(), 'soft-deleted student hidden');

        $this->assertSame(1, $this->getJson(self::API.'/students?search=S-001')->json('meta.total'));
        $this->assertSame(1, $this->getJson(self::API.'/students?search='.urlencode('سامر نبيل'))->json('meta.total'));
        $this->assertSame(0, $this->getJson(self::API.'/students?search=student1@example.invalid')->json('meta.total'), 'contact data is not searchable');
        $this->assertSame(1, $this->getJson(self::API.'/students?status=frozen')->json('meta.total'));
        $this->assertSame(4, $this->getJson(self::API.'/students?enrollment_year_id=10')->json('meta.total'));
        $this->assertSame(1, $this->getJson(self::API.'/students?graduated_year_id=11')->json('meta.total'));
        $this->getJson(self::API.'/students?registered_semester_id=1')->assertUnprocessable();
        $this->getJson(self::API.'/students?status=unknown')->assertUnprocessable();

        $this->assertSame(2, $this->getJson(self::API.'/courses?program_id=2')->json('meta.total'));
        $this->assertSame(1, $this->getJson(self::API.'/courses?search=CRS4')->json('meta.total'));
        $this->assertSame(2, $this->getJson(self::API.'/courses?offered_year_id=10')->json('meta.total'));
        $this->assertSame(1, $this->getJson(self::API.'/deans?state=historical')->json('meta.total'));
        $this->assertSame(2, $this->getJson(self::API.'/deans?state=current')->json('meta.total'));
        $this->assertSame(2, $this->getJson(self::API.'/deans?college_id=1')->json('meta.total'));
    }

    // ── details and data minimisation ─────────────────────────────────────

    public function test_student_detail_shows_only_published_record_and_no_personal_contact_data(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $s = $this->getJson(self::API.'/students/1')->assertOk()->json('data');
        $this->assertSame('S-001', $s['student_number']);
        $this->assertSame(['CRS1'], array_column($s['official_results'], 'course_code'), 'CRS2 was approved then returned; it is hidden');
        $this->assertSame('ناجح', $s['official_results'][0]['result']);
        $this->assertCount(1, $s['terms'], 'non-finalized term hidden');
        $this->assertNull($s['graduation'], 'superseded decision is not a graduation');

        $four = $this->getJson(self::API.'/students/4')->json('data');
        $this->assertSame(['CRS6'], array_column($four['official_results'], 'course_code'), 'pending offering CRS4 hidden');
        $this->assertSame('خطة 2 (النسخة 2)', $four['plan']['label'].' (النسخة '.$four['plan']['version_number'].')');
        $this->assertSame('2026-02-10', $this->getJson(self::API.'/students/5')->json('data.graduation.approved_at'));
        $this->getJson(self::API.'/students/7')->assertNotFound();

        $everything = '';
        $pages = array_merge(self::PORTAL_PAGES, ['students/2', 'students/4', 'students/5', 'students?per_page=100', 'faculty/1', 'faculty/5', 'deans/account-5', 'deans/employee-9',
            'colleges/2', 'courses/1', 'courses?per_page=100', 'faculty?per_page=100', 'leadership/units/92', 'leadership/units/91', 'dashboard?academic_year_id=10']);
        foreach ($pages as $page) {
            $everything .= json_encode($this->getJson(self::API.'/'.$page)->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        }
        $decoded = $everything;
        foreach (['example.invalid', '0999', 'password', 'token', 'date_of_birth', '2003-05-05', 'عنوان سري', 'mother', 'الأم السرية', 'nationality',
            'E-100', 'employee_number', 'username', 'ministry.demo', 'ملاحظة داخلية', 'deregistration', 'approval_notes', 'سبب داخلي'] as $secret) {
            $this->assertStringNotContainsString($secret, $decoded, $secret);
        }
    }

    public function test_course_detail_separates_definition_plan_inclusion_and_offerings(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $c = $this->getJson(self::API.'/courses/4')->assertOk()->json('data');
        $this->assertSame('CRS4', $c['course_code']);
        $this->assertCount(3, $c['plan_inclusions'], 'every recorded plan version is listed');
        $this->assertSame([false, true, false], array_column($c['plan_inclusions'], 'in_effective_plan'), 'only the default version is the effective plan');
        $this->assertCount(1, $c['offerings']);
        $this->assertFalse($c['offerings'][0]['grades_officially_approved']);
        $this->assertSame(1, $c['offerings'][0]['registered_students']);
        $this->assertSame([], $c['offerings'][0]['instructors'], 'inactive teaching assignment hidden');

        $one = $this->getJson(self::API.'/courses/1')->json('data');
        $this->assertCount(2, $one['departments'], 'shared course lists both owning departments');
        $this->assertSame(['2025-2026 — الفصل الأول', '2024-2025 — الفصل الأول'], array_column($one['offerings'], 'term'), 'newest first');
        $this->assertSame([1, 2], array_column($one['offerings'], 'registered_students'), 'dropped and soft-deleted students are not counted');
    }

    public function test_deans_faculty_and_leadership_come_from_recorded_data_only(): void
    {
        $this->actingAsUser(self::MINISTRY);
        $dean = $this->getJson(self::API.'/deans/employee-1')->assertOk()->json('data');
        $this->assertSame('current', $dean['dean_assignments'][0]['state']);
        $this->assertSame('2024-09-01', $dean['dean_assignments'][0]['start_date']);
        $old = $this->getJson(self::API.'/deans/employee-9')->json('data');
        $this->assertSame(['historical', '2024-08-31'], [$old['dean_assignments'][0]['state'], $old['dean_assignments'][0]['end_date']]);
        $accountOnly = $this->getJson(self::API.'/deans/account-5')->json('data');
        $this->assertNull($accountOnly['dean_assignments'][0]['start_date']);
        $this->assertNotEmpty($accountOnly['dean_assignments'][0]['notes']);

        $f = $this->getJson(self::API.'/faculty/2')->json('data');
        $this->assertCount(2, $f['colleges']);
        $this->assertSame(['CRS6'], array_column($f['teaching'], 'course_code'), 'inactive assignment excluded');

        $l = $this->getJson(self::API.'/leadership')->assertOk()->json('data');
        $this->assertSame([], $l['presidency']['holders'], 'no president recorded: shown as none, not invented');
        $this->assertFalse($l['presidency']['role']['exists']);
        $vps = collect($l['vice_presidencies'])->keyBy('unit_id');
        $this->assertSame([94, 93, 92], $vps->keys()->all(), 'recorded unit order (unit_code 7, 8, 9)');
        $this->assertSame('vice_president_scientific', $vps[93]['role']['code']);
        $this->assertCount(1, $vps[93]['role']['holders']);
        $this->assertSame(['current', 'historical'], array_column($vps[93]['holders'], 'state'));
        $this->assertNull($vps[92]['role']['code'], 'community affairs: no role in the system');
        $this->assertFalse($vps[92]['role']['exists']);
        $this->assertSame([], $vps[92]['holders']);
        $this->assertSame([], $vps[94]['role']['holders'], 'administrative VP role exists but nobody holds it');

        $unit = $this->getJson(self::API.'/leadership/units/93')->json('data');
        $this->assertSame('رئيس الجامعة', $unit['ancestors'][0]['name']);
        $this->assertSame(1, $unit['tree'][0]['children'][0]['college_id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function independentOfficial(string $where): array
    {
        $row = DB::selectOne("SELECT COUNT(*) AS total, SUM(CASE WHEN rst.status_code = 'passed' THEN 1 ELSE 0 END) AS passed
            FROM student_course_results r
            JOIN student_course_registrations scr ON scr.student_course_registration_id = r.student_course_registration_id
            JOIN registration_statuses rs ON rs.registration_status_id = scr.registration_status_id
            JOIN course_offerings co ON co.course_offering_id = scr.course_offering_id
            JOIN students s ON s.student_id = scr.student_id
            JOIN result_statuses rst ON rst.result_status_id = r.result_status_id
            WHERE s.deleted_at IS NULL AND rs.status_code IN ('registered','completed') AND {$where}
              AND 'approved' = (SELECT ast.status_code FROM grade_approvals ga JOIN approval_statuses ast ON ast.approval_status_id = ga.approval_status_id
                                WHERE ga.course_offering_id = co.course_offering_id ORDER BY ga.grade_approval_id DESC LIMIT 1)");

        return ['total' => (int) $row->total, 'passed' => (int) $row->passed];
    }

    private function scalar(string $sql): int
    {
        return (int) array_values((array) DB::selectOne($sql))[0];
    }

    private function concreteUrl($route, string $method): ?string
    {
        $candidates = ['1', 'activity', 'employee-1'];
        $uri = '/'.$route->uri();
        foreach ($candidates as $value) {
            $url = preg_replace('/\{[^}]+\}/', $value, $uri);
            try {
                $matched = Route::getRoutes()->match(Request::create($url, $method));
                if ($matched->uri() === $route->uri()) {
                    return $url;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function dataSnapshot(): array
    {
        return collect(['students', 'users', 'user_roles', 'role_permissions', 'courses', 'colleges', 'student_course_results', 'grade_approvals', 'employees'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    private function actingAsUser(int $userId): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::findOrFail($userId));
    }

}
