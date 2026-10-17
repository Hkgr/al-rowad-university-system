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
