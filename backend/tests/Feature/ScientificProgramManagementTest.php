<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ScientificProgramManagementTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/scientific/program-management';

    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
    }

    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function confirm(): array { return ['revision' => $this->revision(), 'confirmed' => true]; }

    public function test_routes_enforce_actual_role_assigned_permissions_and_real_scope(): void
    {
        $this->getJson(self::URL)->assertOk();
        foreach (['super_admin', 'vice_president_administrative', 'dean'] as $role) {
            DB::table('roles')->update(['role_code' => $role]);
            $this->getJson(self::URL)->assertForbidden();
        }
        DB::table('roles')->update(['role_code' => 'vice_president_scientific']);
        $viewId = DB::table('permissions')->where('permission_code', ScientificProgramAccess::VIEW)->value('permission_id');
        DB::table('role_permissions')->where('permission_id', $viewId)->delete();
        $this->getJson(self::URL)->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $viewId]);
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL.'/2')->assertNotFound();
        $this->postJson(self::URL.'/2/initialization', $this->confirm())->assertNotFound();
        DB::table('user_access_scopes')->delete();
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_http_initialization_copy_approval_default_and_text_only_identity(): void
    {
        $revision = $this->revision();
        $this->getJson(self::URL.'/1')->assertOk()->assertJsonPath('data.capabilities.edit_academic', false);
        self::assertSame($revision, $this->revision());
        $this->patchJson(self::URL.'/1', ['revision' => $revision, 'degree_level' => 'changed'])->assertConflict();
        $this->patchJson(self::URL.'/1', ['revision' => $revision, 'program_name' => 'تصحيح اسم'])->assertOk();
        $this->postJson(self::URL.'/1/initialization', $this->confirm())->assertOk()->assertJsonPath('data.program.plan_state', 'preparing');
        $preview = $this->getJson(self::URL.'/1/transition-preview')->assertOk()->json('data');
        $fixed = $this->postJson(self::URL.'/1/transition', ['revision' => $preview['revision'], 'confirmed' => true])->assertOk()->json('data.versions.0.academic_plan_version_id');
        $draft = $this->postJson(self::URL.'/1/versions/'.$fixed.'/copy', ['revision' => $this->revision(), 'label' => 'النسخة الجديدة'])->assertOk()->json('data.version.academic_plan_version_id');
        $this->postJson(self::URL.'/1/versions/'.$draft.'/approve', $this->confirm())->assertOk();
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', 1)->value('default_academic_plan_version_id'));
        $this->postJson(self::URL.'/1/versions/'.$draft.'/default', $this->confirm())->assertOk()->assertJsonPath('data.program.plan_state', 'ready');
        $this->postJson(self::URL.'/1/versions/'.$fixed.'/default', $this->confirm())->assertConflict();
    }

    public function test_unknown_fields_inactive_account_schema_unavailable_and_missing_permission_fail_closed(): void
    {
        $this->getJson(self::URL.'?arbitrary=1')->assertUnprocessable();
        $this->postJson(self::URL.'/1/initialization', $this->confirm() + ['approved' => true])->assertUnprocessable();
        $permission = DB::table('permissions')->where('permission_code', ScientificProgramAccess::PLANS)->value('permission_id');
        DB::table('role_permissions')->where('permission_id', $permission)->delete();
        $this->postJson(self::URL.'/1/initialization', $this->confirm())->assertForbidden();
        DB::table('academic_plan_control')->update(['is_ready' => 0]);
        $this->getJson(self::URL)->assertStatus(503);
        DB::table('account_statuses')->update(['status_code' => 'inactive']);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_never_used_program_can_be_deleted_after_explicit_preview_but_used_program_cannot(): void
    {
        $id = $this->postJson(self::URL, ['revision' => $this->revision(), 'program_name' => 'مسودة برنامج', 'program_code' => 'NEW-P',
            'degree_level' => 'بكالوريوس', 'duration_years' => 4, 'total_credit_hours' => 3, 'department_id' => 1])->assertOk()->json('data.program.academic_program_id');
        $preview = $this->getJson(self::URL.'/'.$id.'/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', true)->json('data');
        $this->deleteJson(self::URL.'/'.$id, ['revision' => $preview['revision'], 'confirmed' => true])->assertOk()->assertJsonPath('data.deleted', true);
        self::assertFalse(DB::table('academic_programs')->where('academic_program_id', $id)->exists());
        self::assertFalse(DB::table('academic_plan_versions')->where('academic_program_id', $id)->exists());
        $this->getJson(self::URL.'/1/deletion-preview')->assertOk()->assertJsonPath('data.can_delete', false);
        $this->deleteJson(self::URL.'/1', $this->confirm())->assertConflict();
    }

    public function test_plan_approver_cannot_assign_default_without_separate_assignment_permission(): void
    {
        $this->postJson(self::URL.'/1/initialization', $this->confirm())->assertOk();
        $source = $this->postJson(self::URL.'/1/transition', $this->confirm())->assertOk()->json('data.versions.0.academic_plan_version_id');
        $id = $this->postJson(self::URL.'/1/versions/'.$source.'/copy', ['revision' => $this->revision(), 'label' => 'خطة'])->assertOk()->json('data.version.academic_plan_version_id');
        $this->postJson(self::URL.'/1/versions/'.$id.'/approve', $this->confirm())->assertOk();
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->where('permission_code', ScientificProgramAccess::ASSIGN)->select('permission_id'))->delete();
        $this->postJson(self::URL.'/1/versions/'.$id.'/default', $this->confirm())->assertForbidden();
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', 1)->value('default_academic_plan_version_id'));
    }

    public function test_course_distribution_requires_explicit_draft_and_never_changes_the_fixed_plan(): void
    {
        $this->postJson(self::URL.'/1/initialization', $this->confirm())->assertOk();
        $fixed = $this->postJson(self::URL.'/1/transition', $this->confirm())->assertOk()->json('data.versions.0.academic_plan_version_id');
        $draft = $this->postJson(self::URL.'/1/versions/'.$fixed.'/copy', ['revision' => $this->revision(), 'label' => 'مسودة توزيع'])->assertOk()->json('data.version.academic_plan_version_id');
        $url = '/api/v1/vice-presidency/scientific/course-management';
        $scope = ['scope' => 'college', 'college_id' => 1, 'course_type' => 'mandatory'];
        $preview = $this->getJson($url.'/distribution-preview?'.http_build_query($scope))->assertOk()->assertJsonPath('data.can_apply', false)->json('data');
        self::assertTrue($preview['targets'][0]['requires_explicit_plan']);
        self::assertSame([$draft], array_column($preview['targets'][0]['draft_options'], 'id'));
        $scope['draft_version_ids'] = [$draft];
        $this->getJson($url.'/distribution-preview?'.http_build_query($scope))->assertOk()->assertJsonPath('data.can_apply', true);
        $id = $this->postJson($url.'/courses', ['revision' => $this->revision(), 'course_code' => 'DRAFT-ONLY', 'course_name' => 'مادة توزيع مسودة',
            'credit_hours' => 3, 'is_active' => true, 'departments' => [['department_id' => 1, 'is_primary' => true]],
            'distribution' => $scope, 'distribution_confirmed' => true, 'academic_level_id' => 1, 'recommended_semester_id' => 1])->assertOk()->json('data.data.course_id');
        self::assertSame([$draft], DB::table('program_courses')->where('course_id', $id)->pluck('academic_plan_version_id')->all());
        self::assertSame(1, DB::table('program_courses')->where('academic_plan_version_id', $fixed)->count());
        $scope['draft_version_ids'] = [$fixed];
        $this->getJson($url.'/distribution-preview?'.http_build_query($scope))->assertUnprocessable();
    }
}
