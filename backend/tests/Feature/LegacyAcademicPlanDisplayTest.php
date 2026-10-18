<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LegacyAcademicPlanDisplayTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/scientific/program-management/';
    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
    }
    private function confirm(): array { return ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true]; }
    private function rows(): array
    {
        $data = [];
        foreach (['academic_requirement_groups', 'program_courses', 'program_course_requirement_groups'] as $table) {
            $data[$table] = DB::table($table)->orderBy(1)->get()->map(fn ($r) => collect((array) $r)->except(['academic_plan_version_id', 'plan_scope_key'])->all())->all();
        }
        return $data;
    }
    public function test_legacy_read_is_real_scoped_data_without_writes_or_admission_changes(): void
    {
        $before = $this->rows(); $revision = $this->confirm()['revision'];
        DB::enableQueryLog(); DB::flushQueryLog();
        $r = $this->getJson(self::URL.'1')->assertOk()->assertJsonPath('data.program.plan_state', 'legacy')
            ->assertJsonPath('data.current_plan.persisted', false)->assertJsonPath('data.current_plan.version.label', 'الإصدار الأول — الخطة الحالية')
            ->assertJsonPath('data.current_plan.version.academic_plan_version_id', null)->assertJsonCount(6, 'data.current_plan.groups')->assertJsonCount(1, 'data.current_plan.courses');
        $this->getJson(self::URL.'1/transition-preview')->assertOk()->assertJsonPath('data.can_fix', true);
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter)\b/i', $q['query']);
        DB::disableQueryLog();
        self::assertSame($before, $this->rows()); self::assertSame($revision, $this->confirm()['revision']);
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(3, (int) $r->json('data.current_plan.version.total_credit_hours'));
        self::assertSame(1, (int) $r->json('data.current_plan.courses.0.requirement_mapping.requirement_group_id'));
    }
    public function test_missing_and_empty_programs_remain_readable_without_fabricated_groups(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 2)->delete();
        DB::table('academic_requirement_groups')->where('requirement_group_id', 3)->update(['required_credit_hours' => null]);
        $r = $this->getJson(self::URL.'1')->assertOk()->assertJsonCount(5, 'data.current_plan.groups')->assertJsonCount(1, 'data.current_plan.courses')
            ->assertJsonPath('data.current_plan.configuration.complete', false);
        $groups = collect($r->json('data.current_plan.groups'))->keyBy('requirement_group_id');
        self::assertNull($groups[3]['required_credit_hours']); self::assertSame(0, (int) $groups[4]['required_credit_hours']);
        $this->getJson(self::URL.'2')->assertOk()->assertJsonCount(0, 'data.current_plan.groups')->assertJsonCount(0, 'data.current_plan.courses');
        $this->postJson(self::URL.'1/transition', $this->confirm())->assertOk();
        self::assertSame(5, DB::table('academic_requirement_groups')->count());
        self::assertNull(DB::table('academic_requirement_groups')->where('requirement_group_id', 3)->value('required_credit_hours'));
        $this->postJson(self::URL.'2/transition', $this->confirm())->assertOk();
        self::assertSame(0, DB::table('program_courses')->where('academic_program_id', 2)->count());
    }
    public function test_fixation_preserves_ids_classifications_archived_students_and_does_not_duplicate(): void
    {
        DB::table('students')->insert(['student_id' => 2, 'academic_program_id' => 1, 'deleted_at' => now()]);
        $before = $this->rows(); $old = $this->getJson(self::URL.'1')->json('data.current_plan');
        $confirmation = $this->confirm();
        $id = $this->postJson(self::URL.'1/transition', $confirmation)->assertOk()->json('data.current_version_id');
        self::assertSame($before, $this->rows());
        $new = $this->getJson(self::URL.'1/versions/'.$id)->assertOk()->json('data');
        self::assertSame($old['version']['total_credit_hours'], $new['version']['total_credit_hours']);
        self::assertSame('transitional', $new['version']['status']); self::assertNull($new['version']['approved_at']);
        self::assertSame(2, DB::table('student_academic_plan_assignments')->where('academic_plan_version_id', $id)->count());
        $this->getJson(self::URL.'1')->assertOk()->assertJsonPath('data.current_plan', null)->assertJsonCount(1, 'data.versions');
        $this->postJson(self::URL.'1/transition', $confirmation)->assertStatus(409);
        $this->postJson(self::URL.'1/transition', $this->confirm())->assertStatus(409);
        self::assertSame(1, DB::table('academic_plan_versions')->count()); self::assertSame(2, DB::table('student_academic_plan_assignments')->count());
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', 1)->value('default_academic_plan_version_id'));
        $this->postJson(self::URL.'1/versions/'.$id.'/copy', ['revision' => $this->confirm()['revision'], 'label' => 'نسخة جديدة'])->assertOk()->assertJsonPath('data.version.version_number', 2);
    }
    public function test_existing_version_is_not_shadowed_by_a_virtual_first_plan(): void
    {
        $this->postJson(self::URL.'1/transition', $this->confirm())->assertOk();
        $before = DB::table('academic_plan_versions')->get()->toJson();
        $this->getJson(self::URL.'1')->assertOk()->assertJsonPath('data.current_plan', null)->assertJsonCount(1, 'data.versions');
        self::assertSame($before, DB::table('academic_plan_versions')->get()->toJson());
    }
    public function test_direct_legacy_fix_preserves_official_student_results_and_progress(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 1, 'student_id' => 1, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        DB::table('student_course_results')->insert(['student_course_registration_id' => 1, 'result_status_id' => 1, 'final_mark' => 80, 'theoretical_total' => 80]);
        $snapshot = function () {
            $student = \App\Models\Student::findOrFail(1);
            return [app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($student),
                app(\App\Services\GradeService::class)->getTranscript($student),
                app(\App\Services\GraduationEligibilityService::class)->evaluate($student)];
        };
        $before = $snapshot(); $results = DB::table('student_course_results')->get()->toJson();
        $id = $this->postJson(self::URL.'1/transition', $this->confirm())->assertOk()->json('data.current_version_id');
        self::assertEquals($before, $snapshot());
        self::assertSame($results, DB::table('student_course_results')->get()->toJson());
        self::assertSame((int) $id, (int) DB::table('student_course_registrations')->value('academic_plan_version_id'));
        self::assertSame(1, (int) DB::table('student_course_registrations')->value('plan_program_course_id'));
    }
    public function test_failed_direct_fixation_also_rolls_back_starting_initialization(): void
    {
        $before = $this->rows(); $revision = $this->confirm()['revision'];
        DB::unprepared("CREATE TRIGGER fail_plan_audit BEFORE INSERT ON user_activity_logs BEGIN SELECT RAISE(ABORT,'test audit failure'); END");
        try {
            app(\App\Services\AcademicPlanWorkflow::class)->fixTransition(User::findOrFail(1), 1, $this->confirm());
            self::fail('Audit failure ignored');
        } catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('test audit failure', $e->getMessage()); }
        self::assertSame('legacy', DB::table('academic_programs')->where('academic_program_id', 1)->value('plan_state'));
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame($before, $this->rows()); self::assertSame($revision, $this->confirm()['revision']);
    }
    public function test_curriculum_aba_and_new_student_each_invalidate_the_read_only_fixation_preview(): void
    {
        $preview = $this->getJson(self::URL.'1/transition-preview')->assertOk()->json('data');
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['required_credit_hours' => 4]);
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['required_credit_hours' => 3]);
        $this->postJson(self::URL.'1/transition', ['revision' => $preview['revision'], 'confirmed' => true])->assertStatus(409);
        $preview = $this->getJson(self::URL.'1/transition-preview')->assertOk()->json('data');
        DB::table('students')->insert(['academic_program_id' => 1]);
        $this->postJson(self::URL.'1/transition', ['revision' => $preview['revision'], 'confirmed' => true])->assertStatus(409);
        self::assertSame(0, DB::table('academic_plan_versions')->count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame('legacy', DB::table('academic_programs')->where('academic_program_id', 1)->value('plan_state'));
    }
}
