<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use App\Support\ScientificCourseAccess;
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
        self::assertSame('sqlite', DB::connection()->getDriverName());
        Schema::dropAllTables();
        $this->schema();
        DB::table('academic_catalog_control')->insert(['control_id' => 1, 'revision' => 1, 'schema_version' => 1, 'is_ready' => 1]);
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('users')->insert(['user_id' => 1, 'username' => 'scientific', 'account_status_id' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'vice_president_scientific']);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        foreach (['vice_presidency.scientific.access', ScientificCourseAccess::VIEW, ScientificCourseAccess::MANAGE, 'courses.manage', 'academic_structure.manage'] as $i => $code) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $code]);
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $i + 1]);
        }
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'university', 'scope_id' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('colleges')->insert(['college_id' => $id, 'college_name' => 'كلية '.$id]);
            DB::table('departments')->insert(['department_id' => $id, 'college_id' => $id, 'department_name' => 'قسم '.$id]);
            DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $id, 'program_name' => 'برنامج '.$id, 'total_credit_hours' => 3]);
            DB::table('courses')->insert(['course_id' => $id, 'course_code' => 'C'.$id, 'course_name' => 'مادة '.$id, 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 0]);
            DB::table('course_departments')->insert(['course_id' => $id, 'department_id' => $id]);
        }
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_name' => 'الأول']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'الأول']);
        DB::table('result_statuses')->insert(['result_status_id' => 1, 'status_code' => 'passed', 'status_name' => 'ناجح']);
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

    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function newCourse(array $extra = []): array { return array_replace(['revision' => $this->revision(), 'course_code' => 'NEW', 'course_name' => 'مادة جديدة', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 2, 'is_active' => true, 'departments' => [['department_id' => 1, 'is_primary' => true]]], $extra); }
    private function membership(array $extra = []): array { return array_replace(['revision' => $this->revision(), 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'course_type' => 'mandatory', 'requirement_scope' => 'university', 'requirement_group_id' => 1, 'is_active' => true], $extra); }
    private function link(int $program, int $course): void { DB::table('program_courses')->insert(['academic_program_id' => $program, 'course_id' => $course, 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'course_type' => 'mandatory']); }

    private function schema(): void
    {
        $tables = [
            'account_statuses' => ['account_status_id', ['status_code']], 'users' => ['user_id', ['username'], ['account_status_id', 'student_id', 'employee_id']],
            'roles' => ['role_id', ['role_code']], 'permissions' => ['permission_id', ['permission_code']],
            'role_permissions' => ['role_permission_id', [], ['role_id', 'permission_id']], 'user_roles' => ['user_role_id', [], ['user_id', 'role_id']],
            'organizational_units' => ['organizational_unit_id', ['unit_code']], 'user_access_scopes' => ['user_access_scope_id', ['scope_type'], ['user_id', 'scope_id']],
            'academic_catalog_control' => ['control_id', [], ['schema_version', 'revision', 'is_ready']],
            'colleges' => ['college_id', ['college_name']], 'departments' => ['department_id', ['department_name'], ['college_id']],
            'academic_programs' => ['academic_program_id', ['program_name', 'program_code', 'degree_level', 'description'], ['department_id', 'total_credit_hours', 'duration_years']],
            'academic_levels' => ['academic_level_id', ['level_name']], 'semesters' => ['semester_id', ['semester_name']],
            'courses' => ['course_id', ['course_code', 'course_name', 'description'], ['credit_hours', 'theoretical_hours', 'practical_hours']],
            'course_departments' => ['course_department_id', [], ['course_id', 'department_id', 'is_primary']],
            'course_instructors' => ['course_instructor_id', [], ['course_id']], 'course_prerequisites' => ['course_prerequisite_id', [], ['course_id', 'prerequisite_course_id', 'minimum_result_status_id']],
            'result_statuses' => ['result_status_id', ['status_name', 'status_code']],
            'program_courses' => ['program_course_id', ['course_type'], ['academic_program_id', 'course_id', 'academic_level_id', 'recommended_semester_id']],
            'academic_requirement_groups' => ['requirement_group_id', ['group_code', 'group_name', 'requirement_scope', 'requirement_type'], ['academic_program_id', 'required_credit_hours']],
            'program_course_requirement_groups' => ['program_course_requirement_group_id', [], ['program_course_id', 'requirement_group_id']],
            'user_activity_logs' => ['activity_log_id', ['module_code', 'action_code', 'description', 'ip_address'], ['user_id']],
        ];
        foreach (\App\Services\AcademicCatalogHistory::PROGRAM_REFERENCES as $table => [$key, $foreign]) $tables[$table] = [$key, [], [$foreign, ...in_array($table, ['course_offerings', 'supplementary_exam_offerings']) ? ['course_id'] : []]];
        foreach ($tables as $table => $definition) Schema::create($table, function (Blueprint $t) use ($table, $definition) {
            $t->increments($definition[0]);
            foreach ($definition[1] as $field) $t->string($field)->nullable()->collation('nocase');
            foreach ($definition[2] ?? [] as $field) $t->integer($field)->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
            if ($table === 'students') $t->softDeletes();
            if ($table === 'courses') $t->unique('course_code');
            if ($table === 'program_courses') $t->unique(['academic_program_id', 'course_id']);
            if ($table === 'academic_requirement_groups') { $t->unique('group_code'); $t->unique(['academic_program_id', 'requirement_scope', 'requirement_type']); }
            if ($table === 'program_course_requirement_groups') $t->unique('program_course_id');
            $foreign = match ($table) {
                'course_departments' => ['course_id' => ['courses', 'course_id'], 'department_id' => ['departments', 'department_id']],
                'course_prerequisites' => ['course_id' => ['courses', 'course_id'], 'prerequisite_course_id' => ['courses', 'course_id'], 'minimum_result_status_id' => ['result_statuses', 'result_status_id']],
                'program_courses' => ['course_id' => ['courses', 'course_id'], 'academic_program_id' => ['academic_programs', 'academic_program_id'], 'academic_level_id' => ['academic_levels', 'academic_level_id'], 'recommended_semester_id' => ['semesters', 'semester_id']],
                'academic_requirement_groups' => ['academic_program_id' => ['academic_programs', 'academic_program_id']],
                'program_course_requirement_groups' => ['program_course_id' => ['program_courses', 'program_course_id'], 'requirement_group_id' => ['academic_requirement_groups', 'requirement_group_id']],
                'course_offerings' => ['course_id' => ['courses', 'course_id'], 'academic_program_id' => ['academic_programs', 'academic_program_id']],
                'students' => ['academic_program_id' => ['academic_programs', 'academic_program_id']],
                default => [],
            };
            foreach ($foreign as $column => [$target, $key]) $t->foreign($column)->references($key)->on($target)->restrictOnDelete()->restrictOnUpdate();
        });
        foreach (['courses', 'academic_programs', 'program_courses', 'academic_requirement_groups', 'program_course_requirement_groups', 'course_departments', 'course_prerequisites', ...array_keys(\App\Services\AcademicCatalogHistory::PROGRAM_REFERENCES)] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) DB::unprepared("CREATE TRIGGER epoch_{$table}_{$event} AFTER {$event} ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        }
        DB::unprepared("CREATE TRIGGER used_course BEFORE UPDATE ON courses WHEN (NEW.credit_hours IS NOT OLD.credit_hours OR NEW.is_active IS NOT OLD.is_active) AND (EXISTS(SELECT 1 FROM course_offerings WHERE course_id=OLD.course_id) OR EXISTS(SELECT 1 FROM program_courses pc JOIN students s ON s.academic_program_id=pc.academic_program_id WHERE pc.course_id=OLD.course_id)) BEGIN SELECT RAISE(ABORT,'academic_catalog_history_locked'); END");
    }
}
