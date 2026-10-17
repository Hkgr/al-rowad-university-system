<?php

namespace Tests\Feature;

use App\Models\{AcademicProgram,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanAdmission};
use App\Services\CatalogCompletion\FollowupCatalogService;
use App\Services\StudentCompletion\{CompletionData,StudentCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StudentCompletionFixture as Fixture;
use Tests\TestCase;

final class ApprovedFollowupCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();Fixture::initialize();
        DB::table('colleges')->where('college_id',1)->update(['college_code'=>'INST-ENG']);
        DB::table('colleges')->where('college_id',2)->update(['college_code'=>'INST-CD']);
        foreach(range(3,6) as $id)DB::table('departments')->insert(['department_id'=>$id,'college_id'=>$id===6?2:1,'department_code'=>'D'.$id,'department_name'=>'Synthetic '.$id]);
    }
    private function bundle(): array
    {
        $b=Fixture::bundle('NEW');$b['contract']='student-completion.sources.v3';$programs=[];
        foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
            $groups=[];foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)$groups[]=['requirement_scope'=>$scope,'requirement_type'=>$type,'required_credit_hours'=>$scope==='college'&&$type==='mandatory'?3:0,'is_active'=>true];
            $programs[]=['key'=>$key,'department_id'=>$i+3,'college_code'=>$i===3?'INST-CD':'INST-ENG','program_code'=>'NEW-'.$i,'program_name'=>'Synthetic '.$key,
                'degree_level'=>'Diploma','duration_years'=>2,'total_credit_hours'=>3,'blockers'=>[],
                'requirements'=>['total_credit_hours'=>3,'groups'=>$groups],
                'courses'=>[['record_id'=>'synthetic:'.$i,'system_code'=>'NEW-SHARED','course_name'=>'Shared synthetic origin','credit_hours'=>3,'requirement_scope'=>'college','course_type'=>'mandatory',
                    'academic_level_id'=>1,'recommended_semester_id'=>1,'prerequisite_codes'=>[],'existing_prerequisites'=>[]]]];
        }
        $b['catalog_followup']=['contract'=>'approved-catalog.followup.v1','programs'=>$programs,'historical_approval_claimed'=>false];return $this->hash($b);
    }
    private function hash(array $b): array {unset($b['catalog_followup']['hash']);$b['catalog_followup']['hash']=CompletionData::hash($b['catalog_followup']);return $b;}
    private function apply(array $b,?array $r=null,?string $id=null): array
    {
        $s=app(FollowupCatalogService::class);$a=User::findOrFail(1);$r??=$s->preview($a,$b);
        return $s->apply($a,$b,$r['review'],$r['review_hash'],$id??(string)Str::uuid(),$r['review']['revision']);
    }
    private function affairsOperator(int $programId): User
    {
        DB::table('users')->insert(['user_id'=>2,'username'=>'student-affairs-synthetic','account_status_id'=>1]);
        DB::table('roles')->insert(['role_id'=>2,'role_code'=>'student_affairs']);DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>2]);
        foreach(['students.view','students.manage','grades.view'] as $code)DB::table('role_permissions')->insert(['role_id'=>2,'permission_id'=>DB::table('permissions')->where('permission_code',$code)->value('permission_id')]);
        DB::table('user_access_scopes')->insert(['user_id'=>2,'scope_type'=>'program','scope_id'=>$programId]);return User::findOrFail(2);
    }
    public function test_student_affairs_operator_can_admit_with_publication_proof_without_scientific_capabilities(): void
    {
        $b=$this->bundle();$this->apply($b);$p=AcademicProgram::where('program_code','NEW-0')->sole();$a=$this->affairsOperator((int)$p->getKey());
        $b['config']['program_aliases']['Synthetic program']='NEW-0';$b['identities'][0]['college']='كلية 1';
        self::assertFalse($a->isSuperAdmin());self::assertFalse($a->effectiveRoles()->contains('vice_president_scientific'));
        self::assertFalse($a->hasPermission(\App\Support\ScientificProgramAccess::PLANS));
        $s=app(StudentCompletionService::class);$r=$s->preview($a,$b);self::assertTrue(collect($r['students'])->firstWhere('number','NEW')['creation']['ready']);
        $result=$s->apply($a,$b,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);self::assertSame('COMPLETE',$result['status'],json_encode($result));
        self::assertSame(1,DB::table('students')->where('student_number','NEW')->count());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Services\AcademicPlanWorkflow::class)->version($a,(int)$p->getKey(),(int)$p->default_academic_plan_version_id);
    }
    public function test_affairs_outside_program_scope_cannot_use_a_forged_admission_context(): void
    {
        $b=$this->bundle();$this->apply($b);$p=AcademicProgram::where('program_code','NEW-0')->sole();$other=AcademicProgram::where('program_code','NEW-1')->sole();
        $a=$this->affairsOperator((int)$other->getKey());$event=DB::table('academic_plan_events')->where('academic_program_id',$p->getKey())->where('action','catalog_completion_published')->sole();
        $context=['kind'=>'approved_now_from_recorded_source_curriculum_not_historical_approval','profile_hash'=>$b['catalog_followup']['hash'],'program_key'=>'engineering_civil','event_id'=>$event->academic_plan_event_id];
        $before=DB::table('user_activity_logs')->get()->toJson();
        try{app(AcademicCatalogTransaction::class)->run(fn()=>AcademicPlanAdmission::createHistorical($a,['academic_program_id'=>$p->getKey(),'student_number'=>'DENIED'],(int)$p->default_academic_plan_version_id,(string)Str::uuid(),$context));self::fail('Out-of-scope admission accepted');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        self::assertSame($before,DB::table('user_activity_logs')->get()->toJson());self::assertFalse(DB::table('students')->where('student_number','DENIED')->exists());
    }
    public function test_resume_revalidates_saved_publications_but_plain_replay_remains_historical(): void
    {
        $b=$this->bundle();$a=User::findOrFail(1);$s=app(FollowupCatalogService::class);$r=$s->preview($a,$b);$id=(string)Str::uuid();$result=$this->apply($b,$r,$id);
        $p=AcademicProgram::where('program_code','NEW-0')->sole();$plans=app(\App\Services\AcademicPlanWorkflow::class);$tx=app(AcademicCatalogTransaction::class);
        $copy=$plans->copy($a,(int)$p->getKey(),(int)$p->default_academic_plan_version_id,['revision'=>$tx->revision(),'label'=>'Authorized replacement']);$v=(int)$copy['version']->getKey();
        $plans->approve($a,(int)$p->getKey(),$v,['revision'=>$tx->revision(),'confirmed'=>true]);$plans->setDefault($a,(int)$p->getKey(),$v,['revision'=>$tx->revision(),'confirmed'=>true]);
        $before=DB::table('user_activity_logs')->get()->toJson();self::assertSame($result,$s->apply($a,$b,$r['review'],$r['review_hash'],$id,'0'));
        try{$s->apply($a,$b,$r['review'],$r['review_hash'],$id,$tx->revision(),true);self::fail('Changed default accepted on resume');}
        catch(\App\Exceptions\AcademicPlanException $e){self::assertSame('catalog_completion_default_changed',$e->errorCode);}
        self::assertSame($before,DB::table('user_activity_logs')->get()->toJson());self::assertSame($v,(int)$p->fresh()->default_academic_plan_version_id);
    }
    public function test_unchanged_explicit_resume_does_not_duplicate_committed_academic_content(): void
    {
        $b=$this->bundle();$a=User::findOrFail(1);$s=app(FollowupCatalogService::class);$r=$s->preview($a,$b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        $before=[];foreach(['courses','academic_plan_versions','program_courses','academic_requirement_groups','academic_plan_events','student_academic_plan_assignments'] as $t)$before[$t]=DB::table($t)->orderByRaw('1')->get()->toJson();
        $result=$s->apply($a,$b,$r['review'],$r['review_hash'],$id,app(AcademicCatalogTransaction::class)->revision(),true);self::assertSame('COMPLETE',$result['status']);
        foreach($before as $t=>$value)self::assertSame($value,DB::table($t)->orderByRaw('1')->get()->toJson());
    }
    public function test_historical_affairs_admission_rejects_each_changed_or_forged_publication_without_writes(): void
    {
        $b=$this->bundle();$this->apply($b);$p=AcademicProgram::where('program_code','NEW-0')->sole();$a=$this->affairsOperator((int)$p->getKey());
        $event=DB::table('academic_plan_events')->where('academic_program_id',$p->getKey())->where('action','catalog_completion_published')->sole();
        $context=['kind'=>'approved_now_from_recorded_source_curriculum_not_historical_approval','profile_hash'=>$b['catalog_followup']['hash'],'program_key'=>'engineering_civil','event_id'=>$event->academic_plan_event_id];
        foreach(['default','unavailable','content','profile','provenance','key','event','context'] as $damage) {
            $before=DB::table('user_activity_logs')->get()->toJson();$ctx=$context;DB::beginTransaction();
            try {
                $proof=json_decode($event->context,true);
                if($damage==='default')DB::table('academic_programs')->where('academic_program_id',$p->getKey())->update(['default_academic_plan_version_id'=>null]);
                if($damage==='unavailable')DB::table('academic_plan_versions')->where('academic_plan_version_id',$p->default_academic_plan_version_id)->update(['status'=>'draft']);
                if($damage==='content')$proof['content_fingerprint']=str_repeat('0',64);
                if($damage==='profile')$proof['manifest_hash']=str_repeat('0',64);
                if($damage==='provenance')$proof['new_program_created']=false;
                if(in_array($damage,['content','profile','provenance'],true))DB::table('academic_plan_events')->where('academic_plan_event_id',$event->academic_plan_event_id)->update(['context'=>json_encode($proof)]);
                if($damage==='key')$ctx['program_key']='different';
                if($damage==='event')$ctx['event_id']++;
                if($damage==='context')$ctx['kind']='fabricated';
                try{app(AcademicCatalogTransaction::class)->run(fn()=>AcademicPlanAdmission::createHistorical($a,['academic_program_id'=>$p->getKey(),'student_number'=>'FORGED'],(int)$p->default_academic_plan_version_id,(string)Str::uuid(),$ctx));self::fail('Damaged proof admitted: '.$damage);}
                catch(\App\Exceptions\AcademicPlanException $e){self::assertSame(409,$e->status,$damage);}
            } finally {DB::rollBack();}
            self::assertSame($before,DB::table('user_activity_logs')->get()->toJson());self::assertFalse(DB::table('students')->where('student_number','FORGED')->exists());
        }
    }
    public function test_final_confirmation_revalidates_every_saved_stage_not_just_success_statuses(): void
    {
        $b=$this->bundle();$n=0;$id=(string)Str::uuid();
        DB::listen(function($query)use(&$n){
            if(str_starts_with(strtolower($query->sql),'insert into "user_activity_logs"')&&in_array('student_completion.followup_catalog_program',$query->bindings,true)&&++$n===4) {
                DB::afterCommit(function(){
                    $event=DB::table('academic_plan_events')->where('action','catalog_completion_published')->orderBy('academic_plan_event_id')->first();
                    $proof=json_decode($event->context,true);$proof['content_fingerprint']=str_repeat('0',64);
                    DB::table('academic_plan_events')->where('academic_plan_event_id',$event->academic_plan_event_id)->update(['context'=>json_encode($proof)]);
                });
            }
        });
        try{$this->apply($b,null,$id);self::fail('False COMPLETE after publication changed');}
        catch(\App\Services\StudentCompletion\CompletionException $e){self::assertSame('catalog_followup_stale',$e->errorCode);}
        self::assertSame(4,$n);self::assertCount(4,DB::table('user_activity_logs')->where('action_code','student_completion.followup_catalog_program')->get());
        self::assertNull(app(\App\Services\StudentCompletion\CompletionReceipts::class)->find('followup_catalog_result',$id));
    }
    public function test_four_contexts_publish_atomically_per_program_and_share_origins_without_rewriting_history_or_repeating_replay(): void
    {
        $b=$this->bundle();$s=app(FollowupCatalogService::class);$a=User::findOrFail(1);$before=DB::table('student_course_results')->get()->toJson();
        $logs=DB::table('user_activity_logs')->count();$r=$s->preview($a,$b);self::assertSame($logs,DB::table('user_activity_logs')->count());self::assertSame('READY',$r['status']);
        $id=(string)Str::uuid();$result=$this->apply($b,$r,$id);self::assertSame('COMPLETE',$result['status'],json_encode($result));
        self::assertSame(4,DB::table('academic_plan_versions')->where('status','approved')->count());self::assertSame(0,DB::table('academic_plan_versions')->where('status','transitional')->count());
        self::assertSame(1,DB::table('courses')->where('course_code','NEW-SHARED')->count());self::assertSame($before,DB::table('student_course_results')->get()->toJson());
        $state=DB::table('user_activity_logs')->get()->toJson();self::assertSame($result,$s->apply($a,$b,$r['review'],$r['review_hash'],$id,'0'));self::assertSame($state,DB::table('user_activity_logs')->get()->toJson());
        self::assertSame('COMPLETE',$s->verify($a,$b)['status']);self::assertSame('COMPLETE',$this->apply($b)['status']);
        self::assertSame(4,DB::table('academic_plan_versions')->count());
    }
    public function test_rejected_program_rolls_back_its_origins_and_draft_but_other_independent_contexts_continue(): void
    {
        $b=$this->bundle();$b['catalog_followup']['programs'][0]['requirements']['groups'][2]['required_credit_hours']=4;$b=$this->hash($b);
        $r=$this->apply($b);self::assertSame('PARTIAL',$r['status']);self::assertSame('BLOCKED',$r['programs'][0]['status']);
        self::assertFalse(AcademicProgram::where('program_code','NEW-0')->exists());self::assertSame(3,DB::table('academic_plan_versions')->where('status','approved')->count());
        self::assertSame(0,DB::table('academic_plan_versions')->where('status','draft')->count());
    }
    public function test_new_program_historical_admission_requires_exact_current_publication_not_merely_a_default(): void
    {
        $b=$this->bundle();$this->apply($b);$p=AcademicProgram::where('program_code','NEW-0')->sole();$a=User::findOrFail(1);
        $b['config']['program_aliases']['Synthetic program']='NEW-0';$b['identities'][0]['college']='كلية 1';
        $s=app(StudentCompletionService::class);$r=$s->preview($a,$b);$row=collect($r['students'])->firstWhere('number','NEW');self::assertTrue($row['creation']['ready'],json_encode($row['creation']['blockers']));
        self::assertSame('approved_now_from_recorded_source_curriculum_not_historical_approval',$row['historical_plan']['basis']);
        $result=$s->apply($a,$b,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);self::assertSame('COMPLETE',$result['status']);
        $new=DB::table('students')->where('student_number','NEW')->sole();self::assertSame((int)$p->default_academic_plan_version_id,(int)DB::table('student_academic_plan_assignments')->where('student_id',$new->student_id)->where('current_slot',1)->value('academic_plan_version_id'));
        $this->expectException(\App\Exceptions\AcademicPlanException::class);
        app(AcademicCatalogTransaction::class)->run(fn()=>AcademicPlanAdmission::createHistorical($a,array_replace($row['creation']['fields'],['student_number'=>'FORGED']),$p->default_academic_plan_version_id,(string)Str::uuid()));
    }
    public function test_review_is_invalidated_by_actual_catalog_change_and_uuid_is_bound_to_actor_and_content(): void
    {
        $b=$this->bundle();$s=app(FollowupCatalogService::class);$a=User::findOrFail(1);$r=$s->preview($a,$b);
        DB::table('courses')->where('course_id',1)->update(['course_name'=>'Concurrent']);
        $this->expectException(\App\Services\StudentCompletion\CompletionException::class);$this->apply($b,$r);
    }
    public function test_changed_review_with_the_same_uuid_is_rejected_before_revision_checks_without_writing(): void
    {
        $b=$this->bundle();$s=app(FollowupCatalogService::class);$a=User::findOrFail(1);$r=$s->preview($a,$b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        $logs=DB::table('user_activity_logs')->count();$changed=$r['review'];$changed['programs'][0]['blockers'][]='different_intent';
        try{$s->apply($a,$b,$changed,CompletionData::hash($changed),$id,'0');self::fail('Changed review replayed');}
        catch(\App\Services\StudentCompletion\CompletionException $e){self::assertSame('catalog_followup_request_mismatch',$e->errorCode);}
        self::assertSame($logs,DB::table('user_activity_logs')->count());
    }
    public function test_scoped_operator_cannot_silently_drop_other_program_targets(): void
    {
        $b=$this->bundle();DB::table('user_access_scopes')->delete();DB::table('user_access_scopes')->insert(['user_id'=>1,'scope_type'=>'department','scope_id'=>3]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(FollowupCatalogService::class)->preview(User::findOrFail(1),$b);
    }
}
