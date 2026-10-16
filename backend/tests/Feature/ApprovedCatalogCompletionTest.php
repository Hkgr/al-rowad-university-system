<?php

namespace Tests\Feature;

use App\Exceptions\AcademicPlanException;
use App\Models\{Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow, GradeService};
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApprovedCatalogCompletionTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); \Tests\Support\ApprovedCatalogFixture::initialize(); }
    private function service(): CatalogCompletionService { return app(CatalogCompletionService::class); }
    private function actor(): User { return User::findOrFail(1); }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function apply(?string $id = null): array { return $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $this->revision(), $id ?? (string) Str::uuid()); }

    public function test_all_nine_sheets_formulas_decisions_and_exact_hours_are_pinned(): void
    {
        $m = new ApprovedCatalogManifest;
        self::assertCount(9, $m->data['sheets']); self::assertCount(67, $m->data['formulas']);
        self::assertCount(15, $m->programs()); self::assertSame(909, array_sum(array_column($m->programs(), 'students_baseline')));
        self::assertCount(24, $m->rows('Decisions'));
        foreach ($m->programs() as $p) foreach ($m->rows('Requirements', (int) $p['program_id']) as $group) {
            $sum = collect($m->rows('Curriculum', (int) $p['program_id']))->filter(fn ($r) => $r['requirement_scope'] === $group['requirement_scope'] && $r['course_type'] === $group['requirement_type'])->sum('target_credits');
            self::assertSame((int) $group['required_credit_hours'], (int) $sum, $p['program_id'].':'.$group['requirement_scope'].':'.$group['requirement_type']);
        }
        $sports = collect($m->rows('Curriculum', 40))->keyBy('target_code');
        self::assertSame('3', $sports['TS423']['target_credits']);
        self::assertSame('college', $sports['TS221']['requirement_scope']);
        self::assertSame('department', $sports['ES223']['requirement_scope']);
        self::assertSame(141, array_sum(array_column($m->rows('Requirements', 40), 'required_credit_hours')));
        $tif = collect($m->rows('Curriculum', 41))->firstWhere('target_code', 'TIF125');
        self::assertSame(['3', '2', '2'], [$tif['target_credits'], $tif['theoretical_hours'], $tif['practical_hours']]);
    }

    public function test_preview_is_read_only_and_unresolved_rows_block_their_program_not_independent_groups(): void
    {
        $before = $this->revision();
        $r = $this->service()->preview($this->actor(), new ApprovedCatalogManifest);
        self::assertSame($before, $this->revision());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
        self::assertSame('budget_only', collect($r['rows'])->first(fn ($row) => ($row['source']['entry_type'] ?? null) === 'CHOICE_SLOT')['state']);
        self::assertNotEmpty($r['programs'][29]['blockers']);
        self::assertEmpty($r['programs'][40]['blockers']);
        self::assertSame('reuse', $r['rows']['P29-R0029']['state']);
        self::assertSame(464, $r['rows']['P29-R0029']['course_id']);
        self::assertSame(464, $r['rows']['P30-R0084']['course_id']);
        self::assertSame('create', $r['rows']['P34-R0529']['state']); // PSB remains separate from same-name PSC subjects.
    }

    public function test_empty_sports_and_both_institutes_publish_atomically_preserve_old_context_and_share_real_origins(): void
    {
        $transcript = app(GradeService::class)->getTranscript(Student::findOrFail(1040));
        $r = $this->apply();
        self::assertSame('PARTIAL', $r['status']);
        foreach ([40 => 141, 41 => 82, 42 => 82] as $id => $hours) {
            $version = AcademicPlanContext::forProgram($id)->versionId;
            self::assertNotNull($version, json_encode($r['groups']));
            self::assertSame($hours, (int) DB::table('academic_plan_versions')->where('academic_plan_version_id', $version)->value('total_credit_hours'), json_encode($r['groups']));
            self::assertSame($version, AcademicPlanContext::forStudent(Student::findOrFail(1000 + $id))->versionId);
            $old = DB::table('academic_plan_versions')->where('academic_program_id', $id)->where('status', 'transitional')->sole();
            self::assertSame($id === 41 ? 1 : 0, DB::table('program_courses')->where('academic_plan_version_id', $old->academic_plan_version_id)->count());
            self::assertNull($old->approved_at);
            if ($id === 41) {
                $group = DB::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->sole();
                self::assertNull($group->required_credit_hours); self::assertSame(0, (int) $group->is_active);
                self::assertSame((int) $old->academic_plan_version_id, (int) $group->academic_plan_version_id);
            }
        }
        self::assertSame(147, (int) DB::table('academic_programs')->where('academic_program_id', 40)->value('total_credit_hours'));
        $visible = app(\App\Services\ScientificProgramManagementService::class)->detail($this->actor(), 40);
        self::assertSame(141, (int) $visible['program']['plan_total_credit_hours']);
        // D02 intentionally corrects the current display name, not any academic result.
        array_walk_recursive($transcript, function (&$value, $key) {
            if ($key === 'program_name') $value = 'برنامج الإجازة في التدريب الرياضي';
        });
        self::assertEquals($transcript, app(GradeService::class)->getTranscript(Student::findOrFail(1040)));
        foreach (['TIF211', 'TIF226'] as $code) {
            self::assertSame(1, DB::table('courses')->where('course_code', $code)->count());
            self::assertSame(2, DB::table('program_courses as pc')->join('academic_plan_versions as v', 'v.academic_plan_version_id', '=', 'pc.academic_plan_version_id')
                ->join('courses as c', 'c.course_id', '=', 'pc.course_id')->where('c.course_code', $code)->where('v.status', 'approved')->count());
        }
        self::assertSame(0, DB::table('courses')->where('course_code', 'like', '%CHOICE%')->count());
        self::assertSame(2, (int) DB::table('courses')->where('course_code', 'TIF125')->value('practical_hours'));
        $verified = $this->service()->verify($this->actor(), new ApprovedCatalogManifest);
        self::assertSame(['memberships' => 1, 'requirement_groups' => 1, 'total_credit_hours' => 82], $verified['programs'][41]['before']);
        self::assertSame(['memberships' => 32, 'requirement_groups' => 6, 'total_credit_hours' => 82], $verified['programs'][41]['after']);
    }

    public function test_lost_response_replay_precedes_stale_revision_and_noop_does_not_duplicate_plans(): void
    {
        $id = (string) Str::uuid(); $revision = $this->revision();
        $first = $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $revision, $id);
        $versions = DB::table('academic_plan_versions')->count(); $courses = DB::table('courses')->count(); $events = DB::table('academic_plan_events')->count();
        self::assertSame($first, $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $revision, $id));
        self::assertSame($first, $this->service()->status($this->actor(), new ApprovedCatalogManifest, $id));
        $resumed = $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $this->revision(), $id, true);
        self::assertContains(1040, $resumed['students'][40]['already_assigned']);
        $this->apply();
        self::assertSame($versions, DB::table('academic_plan_versions')->count());
        self::assertSame($courses, DB::table('courses')->count());
        self::assertSame($events, DB::table('academic_plan_events')->count());
    }

    public function test_pending_registration_and_archived_students_are_reported_without_bypassing_blockers(): void
    {
        DB::table('students')->where('student_id', 1040)->update(['deleted_at' => now()]);
        DB::table('student_registration_requests')->insert(['student_id' => 1041, 'status' => 'submitted']);
        $r = $this->apply();
        self::assertContains(1040, $r['students'][40]['transferred']);
        self::assertSame(1041, $r['students'][41]['blocked'][0]['student_id']);
        $old = DB::table('academic_plan_versions')->where('academic_program_id', 41)->where('status', 'transitional')->value('academic_plan_version_id');
        self::assertSame((int) $old, AcademicPlanContext::forStudent(Student::findOrFail(1041))->versionId);
        self::assertSame('PARTIAL', $r['status']);
    }

    public function test_unauthorized_actor_and_stale_revision_write_nothing(): void
    {
        DB::table('user_roles')->delete();
        try { $this->apply(); self::fail('Unauthorized completion accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        try { $this->service()->apply($this->actor(), new ApprovedCatalogManifest, '0', (string) Str::uuid()); self::fail('Stale completion accepted.'); }
        catch (AcademicPlanException $e) { self::assertSame('academic_catalog_stale', $e->errorCode); }
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_console_defaults_to_preview_and_rejects_incomplete_apply_flags_without_writing(): void
    {
        $revision = $this->revision();
        $this->artisan('academic-catalog:complete', ['--actor' => 1])->assertSuccessful();
        $this->artisan('academic-catalog:complete', ['--actor' => 1, '--apply' => true])->assertFailed();
        self::assertSame($revision, $this->revision());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_failed_approval_rolls_back_fixation_origins_memberships_and_assignments(): void
    {
        DB::unprepared("CREATE TRIGGER reject_completion_approval BEFORE INSERT ON academic_plan_events WHEN NEW.action='approved' BEGIN SELECT RAISE(ABORT,'academic_plan_approval_probe'); END");
        $before = DB::table('courses')->orderBy('course_id')->get()->toArray();
        try { $this->apply(); self::fail('Expected the injected persistence failure.'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('academic_plan_approval_probe', $e->getMessage()); }
        self::assertEquals($before, DB::table('courses')->orderBy('course_id')->get()->toArray());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame(0, DB::table('academic_plan_events')->count());
        self::assertNull(DB::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->value('required_credit_hours'));
    }

    public function test_assignment_replay_rejects_different_student_content_before_current_revision_check(): void
    {
        $this->apply();
        $receipt = json_decode(DB::table('user_activity_logs')->where('action_code', 'catalog_completion.assignment')->first()->description, true);
        $studentId = $receipt['result']['student_ids'][0];
        $program = (int) Student::withTrashed()->findOrFail($studentId)->academic_program_id;
        $input = ['revision' => '0', 'request_id' => $receipt['request_id'], 'manifest_hash' => ApprovedCatalogManifest::SHA256, 'student_ids' => [$studentId]];
        self::assertSame($receipt['result'], app(AcademicPlanWorkflow::class)->transferCatalogCompletion($this->actor(), $program, $receipt['result']['academic_plan_version_id'], $input));
        $input['student_ids'][] = 99999;
        try { app(AcademicPlanWorkflow::class)->transferCatalogCompletion($this->actor(), $program, $receipt['result']['academic_plan_version_id'], $input); self::fail('Mismatched replay accepted.'); }
        catch (AcademicPlanException $e) { self::assertSame('catalog_completion_request_mismatch', $e->errorCode); }
    }
}
