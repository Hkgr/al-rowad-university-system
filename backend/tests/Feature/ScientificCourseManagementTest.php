<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Real HTTP/SQLite persistence; trigger analogues below do NOT establish MariaDB lock safety. */
final class ScientificCourseManagementTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/scientific/course-management';

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\ScientificCatalogFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
    }

    public function test_authentication_actual_role_assigned_permissions_and_scope_are_required(): void
    {
        $this->getJson(self::URL.'/courses')->assertOk();
        foreach (['super_admin', 'vice_president_administrative', 'dean'] as $role) {
            DB::table('roles')->update(['role_code' => $role]);
            $this->getJson(self::URL.'/courses')->assertForbidden();
        }
        DB::table('roles')->update(['role_code' => 'vice_president_scientific']);
        DB::table('role_permissions')->where('permission_id', 2)->delete();
        $this->getJson(self::URL.'/courses')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => 2]);
        DB::table('roles')->update(['is_active' => 0]);
        $this->getJson(self::URL.'/courses')->assertForbidden();
        DB::table('roles')->update(['is_active' => 1]);
        DB::table('user_access_scopes')->delete();
        $this->getJson(self::URL.'/courses')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL.'/courses')->assertUnauthorized();
    }

    public function test_scoped_options_hierarchy_and_shared_origin_are_not_ownership(): void
    {
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::URL.'/options?resource=colleges')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL.'/courses/2')->assertNotFound();
        $this->postJson(self::URL.'/courses', $this->newCourse(['departments' => [['department_id' => 2, 'is_primary' => true]]]))->assertForbidden();
        $this->link(1, 2);
        $detail = $this->getJson(self::URL.'/courses/2')->assertOk()->assertJsonPath('data.capabilities.edit_text', false)->json('data');
        $this->putJson(self::URL.'/courses/2', ['revision' => $detail['revision'], 'course_name' => 'خطأ'])->assertForbidden();
        $this->getJson(self::URL.'/courses?college_id=1&academic_program_id=2')->assertForbidden();
    }

    public function test_course_creation_is_independent_of_programs_and_unique_code_is_preserved(): void
    {
        $r = $this->postJson(self::URL.'/courses', $this->newCourse())->assertOk();
        self::assertSame(0, DB::table('program_courses')->count());
        self::assertSame(1, DB::table('user_activity_logs')->count());
        $id = $r->json('data.data.course_id');
        $this->putJson(self::URL.'/courses/'.$id, ['revision' => $this->revision(), 'course_code' => 'NEW'])->assertOk();
        $this->postJson(self::URL.'/courses', $this->newCourse(['course_code' => 'C1']))->assertUnprocessable()->assertJsonValidationErrors('course_code');
        $this->putJson(self::URL.'/courses/'.$id, ['revision' => $this->revision(), 'course_code' => 'C2'])->assertUnprocessable();
        self::assertSame('NEW', DB::table('courses')->where('course_id', $id)->value('course_code'));
    }

    public function test_aba_change_through_legacy_crud_invalidates_new_editor_revision(): void
    {
        $revision = $this->getJson(self::URL.'/courses/1')->assertOk()->json('data.revision');
        $this->putJson('/api/v1/courses/1', ['course_name' => 'Changed'])->assertOk();
        $this->putJson('/api/v1/courses/1', ['course_name' => 'مادة 1'])->assertOk();
        $this->putJson(self::URL.'/courses/1', ['revision' => $revision, 'description' => 'stale'])->assertConflict()->assertJsonPath('error_code', 'academic_catalog_stale');
        self::assertNull(DB::table('courses')->where('course_id', 1)->value('description'));
    }

    public function test_history_locks_credits_and_classification_but_allows_text_correction(): void
    {
        $this->link(1, 1);
        DB::table('students')->insert(['student_id' => 1, 'academic_program_id' => 1, 'deleted_at' => '2020-01-01']);
        $this->getJson(self::URL.'/courses/1')->assertOk()->assertJsonPath('data.capabilities.edit_text', true)->assertJsonPath('data.capabilities.edit_academic', false);
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'credit_hours' => 4])->assertConflict();
        $this->putJson('/api/v1/courses/1', ['credit_hours' => 4])->assertConflict();
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'course_name' => 'تصحيح نصي', 'description' => 'تصحيح'])->assertOk();
        $this->putJson(self::URL.'/programs/1/courses/1', $this->membership())->assertConflict();
        $this->deleteJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'confirmed' => true])->assertConflict();
        self::assertSame(3, DB::table('courses')->where('course_id', 1)->value('credit_hours'));
    }

    public function test_all_six_classifications_and_budget_are_separate_from_available_pool(): void
    {
        $groups = []; $i = 0;
        foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            $groups[] = ['group_code' => 'G'.++$i, 'group_name' => $scope.' '.$type, 'requirement_scope' => $scope, 'requirement_type' => $type, 'required_credit_hours' => 3, 'is_active' => true];
        }
        $this->putJson(self::URL.'/programs/1/requirement-groups', ['revision' => $this->revision(), 'confirmed' => true, 'total_credit_hours' => 18, 'groups' => $groups])->assertOk()->assertJsonCount(6, 'data.groups');
        $this->putJson(self::URL.'/programs/1/courses/1', $this->membership(['course_type' => 'elective', 'requirement_group_id' => 2]))->assertOk();
        $this->putJson(self::URL.'/programs/1/courses/2', $this->membership(['course_type' => 'elective', 'requirement_group_id' => 2]))->assertOk();
        $result = $this->getJson(self::URL.'/programs/1')->assertOk()->json('data.groups');
        $elective = collect($result)->firstWhere('requirement_group_id', 2);
        self::assertSame(6, $elective['available_credit_hours']); self::assertSame(3, $elective['required_credit_hours']);
        self::assertSame(3, $elective['available_minus_required_hours']);
        self::assertSame(3, DB::table('courses')->where('course_id', 1)->value('credit_hours'));
        $summary = $this->getJson(self::URL.'/courses?academic_program_id=1&per_page=1')->assertOk()->assertJsonPath('data.meta.total', 2)->json('data.summary.groups');
        self::assertSame(2, (int) $summary[0]['membership_count']); self::assertSame(6, (int) $summary[0]['available_credit_hours']);
    }

    public function test_prerequisite_cycle_rolls_back_origin_relations_and_audit(): void
    {
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'prerequisites' => [['prerequisite_course_id' => 2, 'minimum_result_status_id' => 1]]])->assertOk();
        $before = DB::table('user_activity_logs')->count();
        $this->putJson(self::URL.'/courses/2', ['revision' => $this->revision(), 'course_name' => 'لا يحفظ', 'prerequisites' => [['prerequisite_course_id' => 1]]])->assertUnprocessable();
        self::assertSame('مادة 2', DB::table('courses')->where('course_id', 2)->value('course_name'));
        self::assertSame(1, DB::table('course_prerequisites')->count());
        self::assertSame($before, DB::table('user_activity_logs')->count());
        $this->postJson('/api/v1/course-prerequisites', ['course_id' => 2, 'prerequisite_course_id' => 1])->assertUnprocessable();
        self::assertSame(1, DB::table('course_prerequisites')->count());
    }

    public function test_legacy_program_course_and_program_writers_cannot_reinterpret_used_program(): void
    {
        $this->link(1, 1);
        DB::table('course_offerings')->insert(['course_id' => 1, 'academic_program_id' => 1]);
        $this->putJson('/api/v1/program-courses/1', ['course_type' => 'elective'])->assertConflict()->assertJsonPath('error_code', 'academic_catalog_history_locked');
        $this->putJson('/api/v1/academic-programs/1', ['total_credit_hours' => 6])->assertConflict();
        $this->putJson('/api/v1/academic-programs/1', ['program_name' => 'تصحيح اسم البرنامج'])->assertOk();
        self::assertSame('mandatory', DB::table('program_courses')->value('course_type'));
        self::assertSame(3, DB::table('academic_programs')->where('academic_program_id', 1)->value('total_credit_hours'));
    }

    public function test_offering_first_use_changes_epoch_and_rejects_previously_read_context(): void
    {
        $this->link(1, 1);
        $revision = $this->revision();
        $context = new \App\Support\CourseOfferingContext(
            \App\Models\Course::find(1), \App\Models\ProgramCourse::find(1), \App\Models\AcademicProgram::find(1),
            \App\Models\Department::find(1), \App\Models\College::find(1), new \App\Models\AcademicYear(['academic_year_id' => 1]),
            \App\Models\Semester::find(1), $revision,
        );
        $this->putJson(self::URL.'/courses/1', ['revision' => $revision, 'credit_hours' => 4])->assertOk();
        try {
            app(\App\Services\CourseOfferingContextService::class)->createOffering($context);
            self::fail('A cached pre-edit curriculum context must not create the first offering.');
        } catch (\App\Exceptions\AcademicCatalogException $e) {
            self::assertSame('academic_catalog_stale', $e->errorCode);
        }
        self::assertSame(0, DB::table('course_offerings')->count());
        try {
            DB::transaction(fn () => app(\App\Services\CourseOfferingContextService::class)->retainCatalogProofWithinTransaction($context));
            self::fail('Identity update must not reuse a pre-edit destination context either.');
        } catch (\App\Exceptions\AcademicCatalogException $e) {
            self::assertSame('academic_catalog_stale', $e->errorCode);
        }
        $before = $this->revision();
        DB::table('course_offerings')->insert(['course_id' => 1, 'academic_program_id' => 1]);
        self::assertNotSame($before, $this->revision());
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'credit_hours' => 5])->assertConflict();
    }

    public function test_safe_delete_read_only_permission_unknown_fields_and_schema_failure(): void
    {
        $this->deleteJson(self::URL.'/courses/2', ['revision' => $this->revision(), 'confirmed' => true])->assertOk();
        self::assertFalse(DB::table('courses')->where('course_id', 2)->exists());
        $this->postJson(self::URL.'/courses', $this->newCourse(['fake_approval' => true]))->assertUnprocessable();
        DB::table('role_permissions')->where('permission_id', 3)->delete();
        $this->getJson(self::URL.'/courses')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson(self::URL.'/courses', $this->newCourse())->assertForbidden();
        DB::table('academic_catalog_control')->update(['is_ready' => 0]);
        $this->getJson(self::URL.'/courses')->assertStatus(503)->assertJsonPath('error_code', 'academic_catalog_schema_not_ready');
    }

    public function test_listing_queries_are_bounded_as_matching_courses_increase(): void
    {
        $this->link(1, 1); $this->link(1, 2);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->getJson(self::URL.'/courses')->assertOk(); $before = count(DB::getQueryLog());
        for ($id = 3; $id <= 15; $id++) {
            DB::table('courses')->insert(['course_id' => $id, 'course_code' => 'C'.$id, 'course_name' => 'مادة '.$id, 'credit_hours' => 3]);
            DB::table('course_departments')->insert(['course_id' => $id, 'department_id' => 1]);
            $this->link(1, $id);
        }
        DB::flushQueryLog(); $this->getJson(self::URL.'/courses')->assertOk()->assertJsonPath('data.meta.total', 15);
        self::assertLessThanOrEqual($before + 2, count(DB::getQueryLog())); DB::disableQueryLog();
    }

    public function test_six_membership_pairs_use_the_canonical_classification_projection(): void
    {
        $index = 0;
        foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            $index++;
            if ($index > 2) $this->postJson(self::URL.'/courses', $this->newCourse(['course_code' => 'C'.$index]))->assertOk();
            DB::table('academic_requirement_groups')->insert(['requirement_group_id' => $index, 'academic_program_id' => 1, 'group_code' => 'PAIR'.$index, 'group_name' => 'مجموعة '.$index, 'requirement_scope' => $scope, 'requirement_type' => $type, 'required_credit_hours' => 3]);
            $this->putJson(self::URL.'/programs/1/courses/'.$index, $this->membership(['requirement_group_id' => $index, 'requirement_scope' => $scope, 'course_type' => $type]))->assertOk();
            $this->getJson(self::URL.'/courses?academic_program_id=1&requirement_scope='.$scope.'&course_type='.$type)->assertOk()
                ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.program_courses.0.requirement_classification.requirement_scope', $scope)
                ->assertJsonPath('data.data.0.program_courses.0.requirement_classification.requirement_type', $type);
        }
    }

    public function test_partial_schema_and_inactive_actor_fail_closed(): void
    {
        DB::table('account_statuses')->update(['status_code' => 'inactive']);
        $this->postJson(self::URL.'/courses', $this->newCourse())->assertForbidden();
        DB::table('account_statuses')->update(['status_code' => 'active']);
        Sanctum::actingAs(User::findOrFail(1));
        Schema::table('academic_catalog_control', fn (Blueprint $t) => $t->dropColumn('is_ready'));
        $this->getJson(self::URL.'/courses')->assertStatus(503)->assertJsonPath('error_code', 'academic_catalog_schema_not_ready');
    }

    public function test_shared_origin_confirmation_and_membership_change_do_not_change_other_program(): void
    {
        $this->link(1, 1); $this->link(2, 1);
        $this->getJson(self::URL.'/courses/1')->assertOk()->assertJsonPath('data.impact.shared', true);
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'course_name' => 'تصحيح مشترك'])->assertUnprocessable();
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'course_name' => 'تصحيح مشترك', 'impact_confirmed' => true])->assertOk();
        DB::table('academic_requirement_groups')->insert(['requirement_group_id' => 1, 'academic_program_id' => 1, 'group_code' => 'ELECT', 'group_name' => 'اختياري', 'requirement_scope' => 'college', 'requirement_type' => 'elective', 'required_credit_hours' => 3]);
        $this->putJson(self::URL.'/programs/1/courses/1', $this->membership(['course_type' => 'elective', 'requirement_scope' => 'college']))->assertOk();
        self::assertSame('mandatory', DB::table('program_courses')->where('academic_program_id', 2)->value('course_type'));
        self::assertSame('تصحيح مشترك', DB::table('courses')->where('course_id', 1)->value('course_name'));
        self::assertSame(3, DB::table('academic_programs')->where('academic_program_id', 1)->value('total_credit_hours'));
        $this->deleteJson(self::URL.'/programs/1/courses/1', ['revision' => $this->revision(), 'confirmed' => true])->assertOk();
        self::assertSame(1, DB::table('program_courses')->where('course_id', 1)->count());
        self::assertTrue(DB::table('courses')->where('course_id', 1)->exists());
    }

    public function test_membership_resolves_group_on_server_without_a_third_selection(): void
    {
        DB::table('academic_requirement_groups')->insert(['requirement_group_id' => 5, 'academic_program_id' => 1, 'group_code' => 'AUTO', 'group_name' => 'جامعي', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => 3]);
        $body = $this->membership(); unset($body['requirement_group_id']);
        $this->putJson(self::URL.'/programs/1/courses/1', $body)->assertOk();
        self::assertSame(5, DB::table('program_course_requirement_groups')->value('requirement_group_id'));
        self::assertSame(3, DB::table('academic_programs')->where('academic_program_id', 1)->value('total_credit_hours'));
        $this->putJson(self::URL.'/programs/1/courses/2', $this->membership(['requirement_group_id' => 99]))->assertUnprocessable()->assertJsonValidationErrors('requirement_group_id');
        self::assertSame(1, DB::table('program_courses')->count());
    }

    public function test_missing_inactive_or_ambiguous_group_never_mutates_membership(): void
    {
        $payload = fn () => array_diff_key($this->membership(), ['requirement_group_id' => true]);
        $this->putJson(self::URL.'/programs/1/courses/1', $payload())->assertUnprocessable()->assertJsonValidationErrors('requirement_scope');
        DB::table('academic_requirement_groups')->insert(['requirement_group_id' => 1, 'academic_program_id' => 1, 'group_code' => 'INACTIVE', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => 3, 'is_active' => 0]);
        $this->putJson(self::URL.'/programs/1/courses/1', $payload())->assertUnprocessable();
        // Corrupted test-only fixture: production uniqueness remains unchanged.
        Schema::table('academic_requirement_groups', fn (Blueprint $t) => $t->dropUnique(['academic_program_id', 'requirement_scope', 'requirement_type']));
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['is_active' => 1]);
        DB::table('academic_requirement_groups')->insert(['requirement_group_id' => 2, 'academic_program_id' => 1, 'group_code' => 'DUPPAIR', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => 3]);
        $this->putJson(self::URL.'/programs/1/courses/1', $payload())->assertUnprocessable();
        self::assertSame(0, DB::table('program_courses')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_explicit_requirement_settings_hide_internal_codes_but_never_default_hours(): void
    {
        $body = ['revision' => $this->revision(), 'confirmed' => true, 'total_credit_hours' => 3,
            'groups' => [['requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'is_active' => true]]];
        $this->putJson(self::URL.'/programs/1/requirement-groups', $body)->assertUnprocessable();
        self::assertSame(0, DB::table('academic_requirement_groups')->count());
        $body['groups'][0]['required_credit_hours'] = 3;
        $this->putJson(self::URL.'/programs/1/requirement-groups', $body)->assertOk();
        self::assertSame(3, DB::table('academic_requirement_groups')->value('required_credit_hours'));
        self::assertSame('SC-1-university-mandatory', DB::table('academic_requirement_groups')->value('group_code'));
    }

    public function test_admission_only_program_lock_matrix_preserves_text_and_independent_creation(): void
    {
        $this->link(1, 1);
        DB::table('admission_applications')->insert(['academic_program_id' => 1]);
        $this->getJson(self::URL.'/programs/1')->assertOk()->assertJsonPath('data.capabilities.edit_curriculum', false);
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'course_name' => 'تصحيح'])->assertOk();
        $this->postJson(self::URL.'/courses', $this->newCourse())->assertOk();
        $this->putJson(self::URL.'/courses/1', ['revision' => $this->revision(), 'credit_hours' => 4])->assertConflict();
        $this->putJson(self::URL.'/programs/1/courses/2', $this->membership())->assertConflict();
        $this->putJson(self::URL.'/programs/1/courses/1', $this->membership(['course_type' => 'elective']))->assertConflict();
        $this->deleteJson(self::URL.'/programs/1/courses/1', ['revision' => $this->revision(), 'confirmed' => true])->assertConflict();
        $this->putJson(self::URL.'/programs/1/requirement-groups', ['revision' => $this->revision(), 'confirmed' => true, 'total_credit_hours' => 4,
            'groups' => [['requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => 4, 'is_active' => true]]])->assertConflict();
        self::assertSame(3, DB::table('academic_programs')->where('academic_program_id', 1)->value('total_credit_hours'));
        self::assertSame(1, DB::table('program_courses')->count());
    }

    public function test_read_endpoints_emit_no_for_update_with_mysql_query_grammar(): void
    {
        $connection = DB::connection(); $original = $connection->getQueryGrammar();
        // SQLite rejects FOR UPDATE: unlike its native grammar, MySQL grammar
        // retains lock clauses, proving these GET paths do not request write locks.
        $connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection));
        $revision = $this->revision();
        try {
            foreach (['/courses', '/courses/1', '/programs/1', '/options?resource=courses'] as $path) $this->getJson(self::URL.$path)->assertOk();
            self::assertSame($revision, $this->revision());
            self::assertSame(0, DB::table('user_activity_logs')->count());
        } finally { $connection->setQueryGrammar($original); }
    }

    public function test_read_epoch_fence_rejects_a_committed_aba_without_replaying_read(): void
    {
        $calls = 0;
        try {
            app(AcademicCatalogTransaction::class)->snapshot(function () use (&$calls) {
                $calls++;
                // Deterministic fence simulation only, NOT multi-connection lock evidence.
                DB::table('courses')->where('course_id', 1)->update(['course_name' => 'changed']);
                DB::table('courses')->where('course_id', 1)->update(['course_name' => 'مادة 1']);
                return ['mixed' => true];
            });
            self::fail('A changed epoch must reject the complete read response');
        } catch (\App\Exceptions\AcademicCatalogException $e) {
            self::assertSame('academic_catalog_stale', $e->errorCode);
            self::assertSame(1, $calls);
        }
    }

    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function newCourse(array $extra = []): array { return array_replace(['revision' => $this->revision(), 'course_code' => 'NEW', 'course_name' => 'مادة جديدة', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 2, 'is_active' => true, 'departments' => [['department_id' => 1, 'is_primary' => true]]], $extra); }
    private function membership(array $extra = []): array { return array_replace(['revision' => $this->revision(), 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'course_type' => 'mandatory', 'requirement_scope' => 'university', 'requirement_group_id' => 1, 'is_active' => true], $extra); }
    private function link(int $program, int $course): void { DB::table('program_courses')->insert(['academic_program_id' => $program, 'course_id' => $course, 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'course_type' => 'mandatory']); }

}
