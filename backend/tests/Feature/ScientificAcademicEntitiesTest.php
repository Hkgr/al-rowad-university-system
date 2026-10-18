<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ScientificAcademicEntitiesTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/scientific/program-management/entities';
    private const PROGRAMS = '/api/v1/vice-presidency/scientific/program-management';

    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
        foreach ([1, 2] as $id) {
            DB::table('colleges')->where('college_id', $id)->update(['college_code' => 'COL'.$id, 'organizational_unit_id' => $id + 1]);
            DB::table('departments')->where('department_id', $id)->update(['department_code' => 'DEP'.$id]);
            DB::table('organizational_units')->insert(['organizational_unit_id' => $id + 1, 'unit_code' => 'OU'.$id, 'unit_name' => 'وحدة توضيحية '.$id]);
        }
    }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function payload(array $fields = []): array { return ['revision' => $this->revision()] + $fields; }
    private function scope(string $type, int $id): void { DB::table('user_access_scopes')->update(['scope_type' => $type, 'scope_id' => $id]); }

    public function test_unauthenticated_inactive_and_missing_scope_fail_before_disclosing_entities(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson(self::URL.'/colleges/1')->assertUnauthorized();
        Sanctum::actingAs(User::findOrFail(1));
        DB::table('user_access_scopes')->delete(); $this->getJson(self::URL.'/colleges/1')->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'university', 'scope_id' => 1]);
        DB::table('account_statuses')->update(['status_code' => 'inactive']); $this->getJson(self::URL.'/colleges/1')->assertForbidden();
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_lists_details_and_child_lists_are_real_scoped_read_only_and_paginated(): void
    {
        DB::table('colleges')->where('college_id', 2)->update(['is_active' => false, 'college_name' => 'معهد توضيحي']);
        $revision = $this->revision(); $audit = DB::table('user_activity_logs')->count();
        $writes = []; DB::listen(function ($query) use (&$writes) { if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) $writes[] = $query->sql; });
        $this->getJson(self::URL.'/colleges?per_page=1')->assertOk()->assertJsonPath('data.meta.total', 2)->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL.'/colleges?status=inactive&q=معهد')->assertOk()->assertJsonPath('data.data.0.college_name', 'معهد توضيحي')->assertJsonPath('data.meta.total', 1);
        $this->getJson(self::URL.'/colleges/1')->assertOk()->assertJsonPath('data.entity.college_code', 'COL1')->assertJsonPath('data.entity.organizational_unit.unit_name', 'وحدة توضيحية 1');
        $this->getJson(self::URL.'/departments?college_id=1')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.department_id', 1);
        $this->getJson(self::URL.'/departments/1')->assertOk()->assertJsonPath('data.entity.college.college_id', 1);
        $this->getJson(self::PROGRAMS.'?department_id=1')->assertOk()->assertJsonPath('data.meta.total', 1);
        self::assertSame([], $writes); self::assertSame($revision, $this->revision()); self::assertSame($audit, DB::table('user_activity_logs')->count());
    }

    public function test_actual_scientific_role_permission_and_scope_are_required_and_super_admin_keeps_existing_bypass(): void
    {
        foreach (['vice_president_administrative', 'dean', 'student', 'ministry_observer'] as $role) {
            DB::table('roles')->update(['role_code' => $role]); $this->getJson(self::URL.'/colleges')->assertForbidden();
        }
        DB::table('roles')->update(['role_code' => 'super_admin']); DB::table('role_permissions')->delete(); DB::table('user_access_scopes')->delete();
        $this->getJson(self::URL.'/colleges/1')->assertOk()->assertJsonPath('data.capabilities.edit', true);
        $this->patchJson(self::URL.'/colleges/1', $this->payload(['college_name' => 'تصحيح إداري']))->assertOk();
        DB::table('roles')->update(['role_code' => 'vice_president_scientific']); $this->getJson(self::URL.'/colleges')->assertForbidden();
    }

    public function test_missing_manage_keeps_reads_and_denies_all_mutations(): void
    {
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->where('permission_code', ScientificProgramAccess::MANAGE)->select('permission_id'))->delete();
        $this->getJson(self::URL.'/departments/1')->assertOk()->assertJsonPath('data.capabilities.edit', false)->assertJsonPath('data.capabilities.create_child', false);
        $this->patchJson(self::URL.'/departments/1', $this->payload(['department_name' => 'غير مسموح']))->assertForbidden();
        $this->postJson(self::URL.'/colleges', $this->payload(['college_name' => 'X', 'college_code' => 'X', 'is_active' => true]))->assertForbidden();
        $this->deleteJson(self::URL.'/colleges/1', $this->payload(['confirmed' => true]))->assertForbidden();
    }

    public function test_department_scope_can_read_parent_but_cannot_edit_it_or_create_siblings(): void
    {
        $this->scope('department', 1);
        $this->getJson(self::URL.'/colleges')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.capabilities.create', false);
        $this->getJson(self::URL.'/colleges/1')->assertOk()->assertJsonPath('data.capabilities.edit', false)->assertJsonPath('data.capabilities.create_child', false);
        $this->getJson(self::URL.'/departments')->assertOk()->assertJsonPath('data.capabilities.create', false);
        $this->patchJson(self::URL.'/colleges/1', $this->payload(['college_name' => 'غير مسموح']))->assertForbidden();
        $this->postJson(self::URL.'/departments', $this->payload(['college_id' => 1, 'department_name' => 'قسم شقيق', 'department_code' => 'SIBLING', 'is_active' => true]))->assertForbidden();
        $this->getJson(self::URL.'/departments/1')->assertOk()->assertJsonPath('data.capabilities.edit', true);
        $this->patchJson(self::URL.'/departments/1', $this->payload(['department_name' => 'تصحيح القسم']))->assertOk();
        $this->getJson(self::URL.'/colleges/2')->assertNotFound(); $this->getJson(self::URL.'/departments?college_id=2')->assertNotFound();
        $this->getJson(self::URL.'/organizational-units')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_program_scope_cannot_modify_ancestors_or_create_new_programs(): void
    {
        $this->scope('program', 1);
        $this->getJson(self::URL.'/departments/1')->assertOk()->assertJsonPath('data.capabilities.edit', false)->assertJsonPath('data.capabilities.create_child', false);
        $this->getJson(self::PROGRAMS)->assertOk()->assertJsonPath('data.capabilities.create', false);
        $this->patchJson(self::URL.'/departments/1', $this->payload(['department_name' => 'غير مسموح']))->assertForbidden();
        $this->postJson(self::PROGRAMS, $this->payload(['department_id' => 1, 'program_name' => 'Sibling', 'program_code' => 'PNEW', 'degree_level' => 'B', 'duration_years' => 4, 'total_credit_hours' => 3]))->assertForbidden();
        self::assertSame(2, DB::table('academic_programs')->count());
    }

    public function test_local_college_department_program_creation_uses_current_models_and_explicit_draft(): void
    {
        $college = $this->postJson(self::URL.'/colleges', $this->payload(['college_name' => 'معهد اصطناعي', 'college_code' => 'DEMO-I', 'description' => 'Test only', 'is_active' => true]))->assertOk()->json('data.entity.college_id');
        $department = $this->postJson(self::URL.'/departments', $this->payload(['college_id' => $college, 'department_name' => 'قسم اصطناعي', 'department_code' => 'DEMO-D', 'is_active' => true]))->assertOk()->json('data.entity.department_id');
        $this->getJson(self::URL.'/departments/'.$department)->assertOk()->assertJsonPath('data.entity.college.college_id', $college)->assertJsonPath('data.capabilities.create_child', true);
        $program = $this->postJson(self::PROGRAMS, $this->payload(['department_id' => $department, 'program_name' => 'برنامج اصطناعي', 'program_code' => 'DEMO-P', 'degree_level' => 'دبلوم', 'duration_years' => 2, 'total_credit_hours' => 3]))->assertOk()->json('data.program.academic_program_id');
        $this->getJson(self::PROGRAMS.'/'.$program)->assertOk()->assertJsonPath('data.program.department.college.college_id', $college)->assertJsonPath('data.versions.0.status', 'draft');
        $this->getJson(self::PROGRAMS.'/workspace?academic_program_id='.$program)->assertOk()->assertJsonPath('data.requires_source_selection', true);
        $this->getJson(self::PROGRAMS.'?department_id='.$department)->assertOk()->assertJsonPath('data.meta.total', 1);
        self::assertSame(1, DB::table('academic_plan_versions')->where('academic_program_id', $program)->count());
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', $program)->value('default_academic_plan_version_id'));
    }

    public function test_all_programs_of_a_department_are_returned_without_arbitrary_choice(): void
    {
        DB::table('academic_programs')->insert(['department_id' => 1, 'program_name' => 'برنامج ثانٍ', 'program_code' => 'SECOND', 'total_credit_hours' => 3]);
        $this->getJson(self::PROGRAMS.'?department_id=1')->assertOk()->assertJsonPath('data.meta.total', 2)->assertJsonCount(2, 'data.data');
        $this->getJson(self::PROGRAMS.'/options?resource=programs&department_id=1')->assertOk()->assertJsonPath('data.unambiguous', false);
    }

    public function test_selected_relations_are_scoped_and_used_relationships_are_not_moved(): void
    {
        DB::table('organizational_units')->insert(['organizational_unit_id' => 4, 'unit_code' => 'FREE', 'unit_name' => 'وحدة غير مرتبطة']);
        $this->scope('college', 1);
        $this->postJson(self::URL.'/departments', $this->payload(['college_id' => 2, 'department_name' => 'X', 'department_code' => 'X', 'is_active' => true]))->assertNotFound();
        $this->patchJson(self::URL.'/colleges/1', $this->payload(['organizational_unit_id' => 3]))->assertNotFound();
        $this->scope('university', 1);
        $this->patchJson(self::URL.'/departments/1', $this->payload(['college_id' => 2]))->assertConflict();
        $this->patchJson(self::URL.'/colleges/1', $this->payload(['organizational_unit_id' => 4]))->assertConflict();
        self::assertSame(1, DB::table('departments')->where('department_id', 1)->value('college_id'));
        self::assertSame(2, DB::table('colleges')->where('college_id', 1)->value('organizational_unit_id'));
    }

    public function test_generic_writers_advance_the_shared_epoch_and_detect_change_then_reversion(): void
    {
        $preview = $this->getJson(self::URL.'/departments/1')->assertOk()->json('data.revision');
        $this->patchJson('/api/v1/departments/1', ['department_name' => 'تغيير خارجي'])->assertOk();
        $this->patchJson('/api/v1/departments/1', ['department_name' => 'قسم 1'])->assertOk();
        self::assertNotSame($preview, $this->revision());
        $this->patchJson(self::URL.'/departments/1', ['revision' => $preview, 'department_name' => 'معاينة قديمة'])->assertConflict();
        self::assertSame('قسم 1', DB::table('departments')->where('department_id', 1)->value('department_name'));
        $old = $this->revision(); $this->patchJson('/api/v1/colleges/1', ['college_name' => 'تصحيح'])->assertOk(); self::assertNotSame($old, $this->revision());
    }

    public function test_no_op_is_not_a_revision_or_audit_change_and_validation_remains_strict(): void
    {
        $revision = $this->revision(); $audit = DB::table('user_activity_logs')->count();
        $this->patchJson(self::URL.'/departments/1', ['revision' => $revision, 'department_name' => 'قسم 1'])->assertOk();
        self::assertSame($revision, $this->revision()); self::assertSame($audit, DB::table('user_activity_logs')->count());
        $this->patchJson(self::URL.'/departments/1', $this->payload(['department_name' => '   ']))->assertUnprocessable();
        $this->patchJson(self::URL.'/departments/1', $this->payload(['course_id' => 1]))->assertUnprocessable();
        $this->getJson(self::URL.'/colleges?q[]=x')->assertUnprocessable(); $this->getJson(self::URL.'/colleges?per_page=101')->assertUnprocessable();
        $this->getJson(self::URL.'/colleges?q=')->assertOk();
    }

    public function test_used_elements_cannot_be_deleted_and_empty_elements_can(): void
    {
        $this->deleteJson(self::URL.'/colleges/1', $this->payload(['confirmed' => true]))->assertConflict();
        $this->deleteJson('/api/v1/departments/1')->assertConflict();
        $id = $this->postJson(self::URL.'/departments', $this->payload(['college_id' => 1, 'department_name' => 'قسم فارغ', 'department_code' => 'EMPTY', 'is_active' => true]))->assertOk()->json('data.entity.department_id');
        $this->deleteJson(self::URL.'/departments/'.$id, $this->payload(['confirmed' => true]))->assertOk()->assertJsonPath('data.deleted', true);
        self::assertFalse(DB::table('departments')->where('department_id', $id)->exists());
    }

    public function test_inactive_parent_and_schema_readiness_have_controlled_capabilities_and_errors(): void
    {
        DB::table('colleges')->where('college_id', 1)->update(['is_active' => false]);
        $this->getJson(self::URL.'/colleges/1')->assertOk()->assertJsonPath('data.capabilities.create_child', false);
        $this->postJson(self::URL.'/departments', $this->payload(['college_id' => 1, 'department_name' => 'X', 'department_code' => 'X', 'is_active' => true]))->assertUnprocessable();
        DB::table('academic_catalog_control')->update(['is_ready' => 0]); $this->getJson(self::URL.'/colleges')->assertStatus(503);
        $this->patchJson(self::URL.'/colleges/1', ['revision' => '1', 'college_name' => 'X'])->assertStatus(503);
    }

    public function test_list_query_count_is_bounded_as_distinct_entities_increase(): void
    {
        $count = function () { DB::flushQueryLog(); DB::enableQueryLog(); $this->getJson(self::URL.'/departments?per_page=100')->assertOk(); $queries = count(DB::getQueryLog()); DB::disableQueryLog(); return $queries; };
        $small = $count();
        foreach (range(3, 52) as $id) DB::table('departments')->insert(['department_id' => $id, 'college_id' => 1, 'department_name' => 'قسم '.$id, 'department_code' => 'D'.$id, 'organizational_unit_id' => 2]);
        $large = $count(); self::assertLessThanOrEqual($small + 2, $large);
        $this->getJson(self::URL.'/departments?per_page=20&page=2')->assertOk()->assertJsonPath('data.meta.total', 52)->assertJsonCount(20, 'data.data');
    }

    public function test_existing_non_scientific_crud_authority_is_preserved_without_granting_the_new_portal(): void
    {
        DB::table('roles')->update(['role_code' => 'dean']); $this->scope('college', 1);
        $this->patchJson('/api/v1/departments/1', ['department_name' => 'تصحيح مصرح ضمن CRUD القديم'])->assertOk();
        $this->getJson(self::URL.'/departments/1')->assertForbidden();
        $this->patchJson('/api/v1/departments/2', ['department_name' => 'خارج النطاق'])->assertNotFound();
        self::assertSame('قسم 2', DB::table('departments')->where('department_id', 2)->value('department_name'));
    }

    public function test_academic_unit_lookup_does_not_grant_general_organizational_structure_read(): void
    {
        DB::table('organizational_units')->insert(['organizational_unit_id' => 9, 'unit_code' => 'PRIVATE-HR', 'unit_name' => 'وحدة غير أكاديمية اصطناعية']);
        $this->getJson(self::URL.'/organizational-units')->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson(self::URL.'/organizational-units?q=PRIVATE')->assertOk()->assertJsonPath('data.meta.total', 0);
        DB::table('roles')->update(['role_code' => 'super_admin']);
        $this->getJson(self::URL.'/organizational-units?q=PRIVATE')->assertOk()->assertJsonPath('data.meta.total', 1);
    }
}
