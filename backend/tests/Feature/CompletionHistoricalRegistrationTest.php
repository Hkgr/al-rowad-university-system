<?php

namespace Tests\Feature;

use App\Models\{Student, StudentCourseRegistration, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanWorkflow, GradeService};
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService, CompletionRegistrationReadiness};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\Support\{ApprovedCatalogFixture, CompletionRegistrationFixture as Fixture};
use Tests\TestCase;

/** Actual services and HTTP, synthetic SQLite data. MariaDB races have a separate harness. */
final class CompletionHistoricalRegistrationTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); ApprovedCatalogFixture::initialize(); Fixture::prepare(); Fixture::sqliteLifecycle(); }
    protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }
    private function report(int $id=1040): array { return app(CompletionRegistrationReadiness::class)->forStudents([$id])[$id]; }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function apply(string $operation,bool $resume=false): array { return app(CatalogCompletionService::class)->apply(User::findOrFail(1),new ApprovedCatalogManifest,$this->revision(),$operation,$resume); }
    private function evidence(): array
    {
        $result=[];
        foreach (['student_course_registrations','student_course_results','grade_components','student_grade_components','grade_approvals',
            'grade_part_approvals','grade_audit_logs','student_course_incomplete_cases','student_course_incomplete_events','student_progression_decisions','student_graduation_decisions'] as $table)
            $result[$table]=DB::table($table)->orderByRaw('1')->get()->map(function($r){$r=(array)$r;unset($r['academic_plan_version_id'],$r['plan_program_course_id']);return $r;})->all();
        return $result;
    }
    public function test_closed_official_registered_history_qualifies_without_mutation_and_global_current_remains_current(): void
    {
        Fixture::registration(10001);
        $before=$this->evidence(); $epoch=$this->revision();
        self::assertSame(1,$this->report()['historical_settled_registrations']); self::assertSame(0,$this->report()['blocking_registrations']);
        $ready=app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040]);
        self::assertSame([],$ready[0]['blockers']); self::assertSame($this->report(),$ready[0]['registration_readiness']);
        self::assertSame($before,$this->evidence()); self::assertSame($epoch,$this->revision());
        self::assertTrue(StudentCourseRegistration::whereKey(10001)->current()->exists());
        self::assertTrue(StudentCourseRegistration::findOrFail(10001)->allowsAttendanceEntry());
        self::assertTrue(StudentCourseRegistration::findOrFail(10001)->allowsGradeEntry());
    }
    public function test_open_missing_result_missing_approval_superseded_and_unknown_outcomes_are_conservative(): void
    {
        Fixture::registration(10001); Fixture::registration(10002,1040,'open',null);
        Fixture::registration(10003,1040,'closed',null); Fixture::registration(10004,1040,'closed','passed',null);
        Fixture::registration(10005); Fixture::approval(10005,'revoked');
        Fixture::registration(10006,1040,'closed','unknown');
        DB::table('student_course_results')->where('student_course_registration_id',10006)->update(['is_deprived'=>true]);
        foreach ([10007=>'pending',10008=>'rejected'] as $id=>$status) {Fixture::registration($id);Fixture::approval($id,$status);}
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>10009,'student_id'=>1040,'course_offering_id'=>987654,'registration_status_id'=>2]);
        self::assertSame(['authoritative_approval_not_approved'=>4,'offering_missing'=>1,'offering_not_closed'=>1,'outcome_not_settled'=>1,'result_missing'=>1],$this->report()['blocking_reasons']);
        self::assertSame(1,$this->report()['historical_settled_registrations']);
        self::assertNotEmpty(app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040])[0]['blockers']);
    }
    public function test_active_legacy_and_conflicting_I_are_blocked_but_canonical_resolved_and_expired_F_Z_with_null_marks_qualify(): void
    {
        foreach ([10001=>'active',10002=>'resolved',10003=>'expired',10004=>'expired'] as $id=>$state) {
            Fixture::registration($id,1040,'closed',null);
            if($id===10004) DB::table('student_course_registrations')->where('student_course_registration_id',$id)->update(['registration_status_id'=>1]);
            Fixture::lifecycle($id,$state,$id===10004?'Z':'F');
        }
        Fixture::registration(10005,1040,'closed','incomplete');
        self::assertSame(3,$this->report()['historical_settled_registrations']);
        self::assertSame(['incomplete_active'=>1,'legacy_incomplete_unsettled'=>1],$this->report()['blocking_reasons']);
        foreach ([10003,10004] as $id) self::assertNull(DB::table('student_course_results')->where('student_course_registration_id',$id)->value('final_mark'));
        DB::table('student_course_results')->where('student_course_registration_id',10002)->update(['final_mark'=>81]);
        self::assertSame(1,$this->report()['blocking_reasons']['incomplete_outcome_conflict']);
        // Completed does not hide an active lifecycle.
        DB::table('student_course_registrations')->where('student_course_registration_id',10001)->update(['registration_status_id'=>1]);
        self::assertSame(1,$this->report()['blocking_reasons']['incomplete_active']);
    }
    public function test_unavailable_lifecycle_schema_blocks_without_guessing_an_absent_case(): void
    {
        Fixture::registration(10001); Fixture::registration(10002,1040,'closed',null);
        DB::table('student_course_registrations')->where('student_course_registration_id',10002)->update(['registration_status_id'=>1]);
        Fixture::lifecycle(10002,'expired'); Schema::drop('student_course_incomplete_events');
        self::assertSame(['incomplete_schema_unavailable'=>2],$this->report()['blocking_reasons']);
        $preview=app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040]);
        self::assertFalse($preview[0]['before']['available']); self::assertNotEmpty($preview[0]['blockers']);
    }
    public function test_existing_academic_operation_blockers_all_remain_effective(): void
    {
        Fixture::registration(10001);
        foreach (['student_registration_requests','student_registration_modification_requests','student_registration_replacement_requests'] as $table) {
            $id=DB::table($table)->insertGetId(['student_id'=>1040,'status'=>'submitted']);
            self::assertContains('توجد طلبات تسجيل غير محسومة.',app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040])[0]['blockers']);
            DB::table($table)->where('student_id',1040)->delete();
        }
        foreach (['student_progression_decisions','student_graduation_decisions'] as $table) {
            DB::table($table)->insert(['student_id'=>1040,'current_slot'=>1,'status'=>'returned']);
            self::assertContains('توجد قرارات أكاديمية غير محسومة.',app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040])[0]['blockers']); DB::table($table)->delete();
        }
        DB::table('student_graduation_decisions')->insert(['student_id'=>1040,'status'=>'approved','materialized_at'=>'2025-01-01']);
        DB::table('student_registration_withdrawal_requests')->insert(['student_id'=>1040,'student_course_registration_id'=>10001,'status'=>'submitted']);
        DB::table('grade_appeals')->insert(['student_id'=>1040]);
        DB::table('supplementary_exam_registrations')->insert(['student_id'=>1040,'status'=>'registered']);
        self::assertCount(4,app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040])[0]['blockers']);
    }
    public function test_partial_same_operation_resume_preserves_history_assignments_result_codes_GPA_and_transcript(): void
    {
        Fixture::registration(10001,1040,'open'); $operation=(string)Str::uuid();
        $first=$this->apply($operation); self::assertSame('PARTIAL',$first['status']); self::assertNotEmpty($first['students'][40]['blocked']);
        $versions=DB::table('academic_plan_versions')->get()->all(); $events=DB::table('academic_plan_events')->where('action','catalog_completion_published')->get()->all();
        DB::table('course_offerings')->where('course_offering_id',10001)->update(['status'=>'closed']);
        $read=app(AcademicPlanWorkflow::class)->previewCatalogCompletion(User::findOrFail(1),40,(int)DB::table('academic_programs')->where('academic_program_id',40)->value('default_academic_plan_version_id'),[1040],ApprovedCatalogManifest::SHA256);
        self::assertTrue($read['can_transfer']);
        $verification=app(CatalogCompletionService::class)->verify(User::findOrFail(1),new ApprovedCatalogManifest);
        self::assertSame(1,$verification['programs'][40]['assignment_readiness']['eligible_students']);
        $ordinary=app(AcademicPlanWorkflow::class)->previewTransfer(User::findOrFail(1),40,$read['students'][0]['to_version_id'],['student_ids'=>[1040]]);
        self::assertFalse($ordinary['can_transfer']); self::assertContains('توجد تسجيلات دراسية جارية.',$ordinary['students'][0]['blockers']);
        \Laravel\Sanctum\Sanctum::actingAs(User::findOrFail(1));
        $this->postJson('/api/v1/vice-presidency/scientific/program-management/40/versions/'.$read['students'][0]['to_version_id'].'/transfer-preview',['student_ids'=>[1040]])
            ->assertOk()->assertJsonPath('data.can_transfer',false);
        DB::table('students')->where('student_id',1040)->update(['deleted_at'=>now()]);
        $prior=$this->evidence(); $registration=(array)DB::table('student_course_registrations')->where('student_course_registration_id',10001)->first();
        $grades=app(GradeService::class); $student=Student::withTrashed()->findOrFail(1040); $gpa=$grades->calculateGpa($student,1,1); $transcript=$grades->getTranscript($student);
        $resumed=$this->apply($operation,true); self::assertContains(1040,$resumed['students'][40]['transferred']);
        $verification=app(CatalogCompletionService::class)->verify(User::findOrFail(1),new ApprovedCatalogManifest);
        self::assertSame(1,$verification['programs'][40]['assignment_readiness']['already_assigned_students']);
        self::assertEquals($versions,DB::table('academic_plan_versions')->get()->all()); self::assertEquals($events,DB::table('academic_plan_events')->where('action','catalog_completion_published')->get()->all());
        self::assertSame($prior,$this->evidence()); self::assertSame($registration,(array)DB::table('student_course_registrations')->where('student_course_registration_id',10001)->first());
        self::assertSame($gpa,$grades->calculateGpa($student,1,1)); self::assertSame($transcript,$grades->getTranscript($student));
        self::assertSame($resumed,app(CatalogCompletionService::class)->apply(User::findOrFail(1),new ApprovedCatalogManifest,'0',$operation));
        $this->apply($operation,true); self::assertSame($prior,$this->evidence());
        // A later authorized default change must make current eligibility unavailable, not restore it.
        $workflow=app(AcademicPlanWorkflow::class); $target=$read['students'][0]['to_version_id'];
        $alternate=(int)$workflow->copy(User::findOrFail(1),40,$target,['revision'=>$this->revision(),'label'=>'Synthetic later default'])['version']->getKey();
        $workflow->approve(User::findOrFail(1),40,$alternate,['revision'=>$this->revision(),'confirmed'=>true]);
        $workflow->setDefault(User::findOrFail(1),40,$alternate,['revision'=>$this->revision(),'confirmed'=>true]);
        $verification=app(CatalogCompletionService::class)->verify(User::findOrFail(1),new ApprovedCatalogManifest);
        self::assertFalse($verification['programs'][40]['assignment_readiness']['available']);
        self::assertSame('publication_context_not_verified',$verification['programs'][40]['assignment_readiness']['reason']);
        self::assertSame($alternate,(int)DB::table('academic_programs')->where('academic_program_id',40)->value('default_academic_plan_version_id'));
    }
    public function test_classifier_queries_are_batched_not_per_registration(): void
    {
        Fixture::registration(10001); DB::connection()->enableQueryLog(); $this->report(); $one=count(DB::getQueryLog()); DB::flushQueryLog();
        foreach (range(10002,10080) as $id) Fixture::registration($id); DB::flushQueryLog();
        self::assertSame(80,$this->report()['historical_settled_registrations']); $many=count(DB::getQueryLog());
        self::assertSame($one,$many); DB::connection()->disableQueryLog();
        foreach (range(10081,10150) as $id) Fixture::registration($id);
        DB::connection()->enableQueryLog(); DB::flushQueryLog();
        self::assertSame(150,$this->report()['historical_settled_registrations']);
        self::assertLessThanOrEqual($one*2+2,count(DB::getQueryLog())); DB::connection()->disableQueryLog();
    }
    public function test_completion_readiness_requires_current_authorization_and_actual_scope(): void
    {
        $read=fn()=>app(AcademicPlanWorkflow::class)->catalogCompletionStudentReadiness(User::findOrFail(1),40,[1040]);
        $grants=DB::table('role_permissions')->get()->map(fn($r)=>(array)$r)->all();
        DB::table('role_permissions')->delete();
        try {$read();self::fail('Missing permission was accepted');} catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(403,$e->getStatusCode());}
        DB::table('role_permissions')->insert($grants); self::assertCount(1,$read());
        DB::table('user_access_scopes')->delete();
        try {$read();self::fail('Missing scope was accepted');} catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(403,$e->getStatusCode());}
        self::assertSame(0,DB::table('student_academic_plan_assignments')->count());
    }
}
