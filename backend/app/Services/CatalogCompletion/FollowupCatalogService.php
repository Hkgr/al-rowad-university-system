<?php

namespace App\Services\CatalogCompletion;

use App\Models\{AcademicPlanVersion,AcademicProgram,Course,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanContext,AcademicPlanWorkflow,ScientificCourseManagementService,ScientificPlanChangeService,ScientificProgramManagementService};
use App\Services\StudentCompletion\{CompletionData,CompletionException,CompletionReceipts};
use App\Support\{ScientificCourseAccess,ScientificProgramAccess};
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Narrow follow-up orchestration. Canonical services still own origins, plans and approvals. */
final class FollowupCatalogService
{
    public function __construct(private AcademicCatalogTransaction $tx,private ScientificProgramAccess $access,
        private ScientificCourseAccess $courses,private AcademicPlanWorkflow $plans,private ScientificPlanChangeService $workspace,
        private ScientificProgramManagementService $programs,private ScientificCourseManagementService $catalog,private CompletionReceipts $receipts) {}

    private function authorize(User $actor,array $bundle): array
    {
        foreach([ScientificProgramAccess::VIEW,ScientificProgramAccess::MANAGE,ScientificProgramAccess::PLANS,ScientificProgramAccess::APPROVE,ScientificProgramAccess::ASSIGN] as $p)$this->access->authorize($actor,$p);
        $this->courses->authorize($actor,true);AcademicPlanContext::assertReady();
        $profile=$bundle['catalog_followup']??[];
        if(!in_array($bundle['contract']??null,['student-completion.sources.v3','student-completion.sources.v4','student-completion.sources.v5','student-completion.sources.v6'],true)||($profile['contract']??null)!=='approved-catalog.followup.v1'
            ||!hash_equals($profile['hash']??'',CompletionData::hash(array_diff_key($profile,['hash'=>true]))))$this->fail('manifest_invalid');
        foreach($profile['programs'] as $p) {
            abort_unless($this->courses->departments($actor)->where('department_id',$p['department_id'])->whereHas('college',fn($q)=>$q->where('college_code',$p['college_code']))->exists(),403);
        }
        return $profile;
    }

    public function preview(User $actor,array $bundle): array
    {
        $profile=$this->authorize($actor,$bundle);
        return $this->tx->snapshot(function()use($actor,$bundle,$profile) {
            $rows=array_map(fn($p)=>$this->project($actor,$p,$profile['hash']),$profile['programs']);
            $review=['contract'=>'approved-catalog.followup.review.v1','actor_id'=>(int)$actor->getKey(),'bundle_hash'=>CompletionData::hash($bundle),
                'profile_hash'=>$profile['hash'],'revision'=>$this->tx->revision(),'programs'=>$rows];
            return ['status'=>collect($rows)->contains(fn($r)=>$r['blockers']!==[])?'BLOCKED':'READY','writes_performed'=>false,
                'review'=>$review,'review_hash'=>CompletionData::hash($review)];
        });
    }

    private function project(User $actor,array $p,string $hash,array $operationOrigins=[]): array
    {
        $d=$this->courses->departments($actor)->with('college')->findOrFail($p['department_id']);$blockers=$p['blockers'];
        if(!$d->is_active||!$d->college?->is_active)$blockers[]='department_or_institute_inactive';
        if(isset($p['department_identity'])&&!hash_equals(CompletionData::hash($p['department_identity']),CompletionData::hash($d->only(array_keys($p['department_identity'])))))$blockers[]='department_identity_changed';
        $all=$this->access->programs($actor)->where('department_id',$d->getKey())->get();
        // More than one program in this exact approved department needs an explicit mapping.
        if($all->count()>1)$blockers[]='program_context_ambiguous';
        $program=$all->count()===1?$all->sole():null;$sourceId=null;$publication=null;
        if(!$program&&AcademicProgram::where('program_code',$p['program_code'])->exists())$blockers[]='program_code_collision';
        if($program) {
            if($program->archived_at||!$program->is_active)$blockers[]='program_unavailable';
            $versions=AcademicPlanVersion::where('academic_program_id',$program->getKey())->orderBy('academic_plan_version_id')->get();
            $event=DB::table('academic_plan_events')->where('academic_program_id',$program->getKey())->where('action','catalog_completion_published')
                ->where('context->manifest_hash',$hash)->first();
            if($event) {
                try {$publication=app(CatalogCompletionPublication::class)->assertTarget($actor,['academic_program_id'=>$program->getKey(),'academic_plan_version_id'=>$event->academic_plan_version_id],$hash);}
                catch(\App\Exceptions\AcademicPlanException $e){$blockers[]=$e->errorCode;}
                $sourceId=(int)$event->academic_plan_version_id;
            } elseif($versions->isNotEmpty()) {
                // Never choose an unrelated draft or rewrite an already-published plan.
                if($versions->where('status','draft')->isNotEmpty())$blockers[]='existing_draft_requires_explicit_review';
                $sourceId=$program->default_academic_plan_version_id;
                if(!$sourceId)$blockers[]='explicit_fixed_source_required';
            } elseif($program->plan_state!=='legacy')$blockers[]='program_context_invalid';
            if(!$publication) {
                $expectedProgram=$p['expected_program']??null;
                if(!$expectedProgram||!hash_equals(CompletionData::hash($expectedProgram),CompletionData::hash($program->only(array_keys($expectedProgram)))))$blockers[]='unreviewed_existing_program_identity';
            }
        }
        $origins=[];$publishedOrigins=$this->publishedOrigins($actor,$hash);
        foreach($p['courses'] as $row) {
            $current=$this->courses->courses($actor)->where('course_code',$row['system_code'])->first();
            $expected=$row['existing']??null;
            if(isset($row['approved_existing_course_id'])&&(!$current||(int)$current->getKey()!==$row['approved_existing_course_id']))$blockers[]='course_identity_unverified:'.$row['record_id'];
            if($current) {
                $attributes=$current->only(['course_id','course_code','course_name','credit_hours','theoretical_hours','practical_hours','is_active']);
                $owned=isset($publishedOrigins[$row['system_code']])&&hash_equals(CompletionData::hash($publishedOrigins[$row['system_code']]),CompletionData::hash($attributes));
                $identity=app(FollowupCourseIdentity::class);$contexts=$identity->liveContexts((int)$current->getKey());
                if(!$identity->nameMatches($row,$attributes)||(!$owned&&!$identity->contextMatches($row,$p,$contexts)))$blockers[]='course_identity_unverified:'.$row['record_id'];
                // Persisted artifacts sort associative keys; compare all typed fields, not insertion order.
                $contextHashes=array_map(CompletionData::hash(...),$contexts);
                foreach($row['identity_audit']['candidate_context']??[] as $reference)if(!in_array(CompletionData::hash($reference),$contextHashes,true))$blockers[]='course_identity_context_changed:'.$row['record_id'];
                if(!$expected&&!$publication&&!$owned)$blockers[]='unreviewed_course_collision:'.$row['record_id'];
                if($expected&&((int)$expected['course_id']!==(int)$current->getKey()||!hash_equals(CompletionData::hash($this->origin($expected)),CompletionData::hash($this->origin($current->only(array_keys($expected)))))))$blockers[]='course_origin_changed:'.$row['record_id'];
                if((int)$current->credit_hours!==$row['credit_hours']||!$current->is_active)$blockers[]='course_credit_or_activity_conflict:'.$row['record_id'];
                $prereqs=DB::table('course_prerequisites as p')->join('courses as c','c.course_id','=','p.prerequisite_course_id')->where('p.course_id',$current->getKey())
                    ->orderBy('c.course_code')->get(['c.course_code','p.minimum_result_status_id'])->map(fn($r)=>(array)$r)->all();
                if(!hash_equals(CompletionData::hash($prereqs),CompletionData::hash($row['existing_prerequisites']??[])))$blockers[]='course_prerequisites_changed:'.$row['record_id'];
                $required=$row['prerequisite_codes']??[];sort($required);
                if($required!==array_column($prereqs,'course_code'))$blockers[]='used_origin_prerequisite_context_mismatch:'.$row['record_id'];
            } elseif($expected)$blockers[]='expected_course_missing:'.$row['record_id'];
            $origins[$row['system_code']]=$current?->only(['course_id','course_code','course_name','credit_hours','theoretical_hours','practical_hours','is_active']);
            // Only earlier receipts of THIS immutable operation can account for a newly-created
            // shared origin when comparing against its original pre-write review.
            if(!$expected&&isset($operationOrigins[$row['system_code']])&&hash_equals(CompletionData::hash($operationOrigins[$row['system_code']]),CompletionData::hash($origins[$row['system_code']])))$origins[$row['system_code']]=null;
        }
        $source=$program&&($sourceId!==null||$versions->isEmpty())?$this->workspace->source($actor,$program,$sourceId):null;
        return ['key'=>$p['key'],'program_id'=>$program?(int)$program->getKey():null,'source_version_id'=>$sourceId,
            'published_version_id'=>$publication?(int)$program->default_academic_plan_version_id:null,
            'before'=>['department'=>$d->only(['department_id','college_id','department_code','department_name','is_active']),
                'program'=>$program?->getAttributes(),'plan'=>$source?$this->workspace->values($source):null,'origins'=>$origins],
            'blockers'=>array_values(array_unique($blockers))];
    }

    public function apply(User $actor,array $bundle,array $review,string $reviewHash,string $id,string $revision,bool $resume=false): array
    {
        $profile=$this->authorize($actor,$bundle);$id=strtolower($id);
        if(!Uuid::isValid($id)||!preg_match('/^[0-9]+$/',$revision)||($review['contract']??null)!=='approved-catalog.followup.review.v1'
            ||($review['actor_id']??null)!==(int)$actor->getKey()||!hash_equals($reviewHash,CompletionData::hash($review))
            ||!hash_equals($review['bundle_hash']??'',CompletionData::hash($bundle))||($review['profile_hash']??null)!==$profile['hash'])$this->fail('review_invalid');
        $digest=CompletionData::hash(['bundle'=>$review['bundle_hash'],'review'=>$reviewHash]);
        $start=$this->tx->run(function()use($actor,$bundle,$profile,$review,$id,$digest,$revision,$resume) {
            $this->authorize($actor,$bundle);
            if($old=$this->receipts->find('followup_catalog_started',$id)) {
                $this->check($actor,$old,$digest);
                if(!$resume)return ['replay'=>$this->receipts->find('followup_catalog_result',$id)['value']['result']??['status'=>'INTERRUPTED','operation_id'=>$id]];
            } elseif($revision!==$review['revision'])$this->fail('stale');
            if($revision!==$this->tx->revision())$this->fail('stale');
            if($old&&$resume)foreach($profile['programs'] as $p) {
                $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$p['key']);
                if($saved=$this->receipts->find('followup_catalog_program',$stage)) {
                    $this->check($actor,$saved,$digest);$this->assertStage($actor,$saved['value']['result'],$p,$profile['hash']);
                }
            }
            if(!$old)$this->receipts->put($actor,'followup_catalog_started',$id,['digest'=>$digest,'profile_hash'=>$profile['hash'],'review'=>$review]);
            return ['revision'=>$this->tx->revision()];
        });
        if(isset($start['replay']))return $start['replay'];
        $next=$start['revision'];$outcomes=[];$operationOrigins=[];
        foreach($profile['programs'] as $p) {
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$p['key']);
            try {
                $stageResult=$this->tx->run(function()use($actor,$bundle,$profile,$review,$id,$digest,$stage,$p,$next,$operationOrigins) {
                    $this->authorize($actor,$bundle);
                    if($old=$this->receipts->find('followup_catalog_program',$stage)) {
                        $this->check($actor,$old,$digest);$this->assertStage($actor,$old['value']['result'],$p,$profile['hash']);
                        if($next!==$this->tx->revision())$this->fail('stale');
                        return ['result'=>$old['value']['result'],'revision'=>$this->tx->revision()];
                    }
                    if($next!==$this->tx->revision())$this->fail('stale');
                    $current=$this->project($actor,$p,$profile['hash'],$operationOrigins);$approved=collect($review['programs'])->firstWhere('key',$p['key']);
                    if(!$approved||!hash_equals(CompletionData::hash($current),CompletionData::hash($approved)))$this->fail('review_changed');
                    if($current['blockers'])return ['result'=>['key'=>$p['key'],'status'=>'BLOCKED','blockers'=>$current['blockers']],'revision'=>$this->tx->revision()];
                    if($current['published_version_id'])$result=['key'=>$p['key'],'status'=>'UNCHANGED','academic_program_id'=>$current['program_id'],'academic_plan_version_id'=>$current['published_version_id']];
                    else $result=$this->publish($actor,$p,$current,$profile['hash'],$stage);
                    $this->receipts->put($actor,'followup_catalog_program',$stage,['digest'=>$digest,'result'=>$result]);
                    return ['result'=>$result,'revision'=>$this->tx->revision()];
                });
                $result=$stageResult['result'];$outcomes[]=$result;$operationOrigins=array_replace($operationOrigins,$result['created_origins']??[]);$next=$stageResult['revision'];
            } catch(\Throwable $e) {
                $outcomes[]=['key'=>$p['key'],'status'=>'BLOCKED','blockers'=>[$e->errorCode??'catalog_validation_failed'],'message'=>$e->getMessage()];
                // A concurrent commit invalidates the remainder; never attach it to a new epoch.
                if($next!==$this->tx->revision())break;
            }
        }
        $result=CompletionData::canonical(['operation_id'=>$id,'profile_hash'=>$profile['hash'],'status'=>count($outcomes)===count($profile['programs'])&&!collect($outcomes)->contains(fn($r)=>$r['status']==='BLOCKED')?'COMPLETE':'PARTIAL','programs'=>$outcomes]);
        $this->tx->run(function()use($actor,$bundle,$profile,$id,$digest,$result,$next) {
            $this->authorize($actor,$bundle);
            if($next!==$this->tx->revision())$this->fail('stale');
            if($result['status']==='COMPLETE')foreach($profile['programs'] as $p) {
                $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$p['key']);$saved=$this->receipts->find('followup_catalog_program',$stage);
                if(!$saved)$this->fail('publication_changed');
                $this->check($actor,$saved,$digest);$this->assertStage($actor,$saved['value']['result'],$p,$profile['hash']);
            }
            $this->receipts->put($actor,'followup_catalog_result',$id,['digest'=>$digest,'result'=>$result]);
        });
        return $result;
    }

    private function publish(User $actor,array $p,array $current,string $hash,string $request): array
    {
        $history=app(CatalogCompletionService::class)->academicFingerprint();$programId=$current['program_id'];$sourceId=$current['source_version_id'];$created=$programId===null;
        if($created) {
            $new=$this->programs->save($actor,null,['revision'=>$this->tx->revision(),'department_id'=>$p['department_id'],'program_code'=>$p['program_code'],
                'program_name'=>$p['program_name'],'degree_level'=>$p['degree_level'],'duration_years'=>$p['duration_years'],'total_credit_hours'=>$p['total_credit_hours']]);
            $programId=(int)$new['program']['academic_program_id'];$sourceId=(int)AcademicPlanVersion::where('academic_program_id',$programId)->sole()->getKey();
        } elseif($sourceId===null) {
            // Pin even an incomplete reference before the first origin is created.
            $fixed=$this->plans->fixTransition($actor,$programId,['revision'=>$this->tx->revision(),'confirmed'=>true]);$sourceId=(int)$fixed['current_version_id'];
        }
        $fixed=AcademicPlanVersion::findOrFail($sourceId);$historicalId=$fixed->status==='draft'?null:$sourceId;
        $sourceHash=$historicalId?CatalogCompletionPublication::sourceFingerprint($historicalId):null;
        $ids=[];$newOrigins=[];$pending=$p['courses'];
        while($pending) {
            $progress=false;
            foreach($pending as $i=>$row) {
                $prereq=[];foreach($row['prerequisite_codes']??[] as $code) {
                    $prereqId=$ids[$code]??$this->courses->courses($actor)->where('course_code',$code)->value('course_id');
                    if(!$prereqId)continue 2;$prereq[]=['prerequisite_course_id'=>(int)$prereqId,'minimum_result_status_id'=>null];
                }
                $existing=$this->courses->courses($actor)->where('course_code',$row['system_code'])->first();
                if($existing)$ids[$row['system_code']]=(int)$existing->getKey();
                else {
                    $saved=$this->catalog->saveCourse($actor,null,['revision'=>$this->tx->revision(),'course_code'=>$row['system_code'],'course_name'=>$row['course_name'],
                        'credit_hours'=>$row['credit_hours'],'theoretical_hours'=>$row['theoretical_hours']??null,'practical_hours'=>$row['practical_hours']??null,
                            'is_active'=>true,'departments'=>isset($row['identity_department_id'])?[['department_id'=>$row['identity_department_id'],'is_primary'=>true]]:($row['requirement_scope']==='university'?[]:[['department_id'=>$p['department_id'],'is_primary'=>true]]),'prerequisites'=>$prereq]);
                    $ids[$row['system_code']]=(int)$saved['data']['course_id'];
                    $newOrigins[$row['system_code']]=Course::findOrFail($ids[$row['system_code']])->only(['course_id','course_code','course_name','credit_hours','theoretical_hours','practical_hours','is_active']);
                }
                unset($pending[$i]);$progress=true;
            }
            if(!$progress)$this->fail('prerequisite_identity_unresolved');
        }
        $memberships=[];foreach($p['courses'] as $row)$memberships[]=['course_id'=>$ids[$row['system_code']],'requirement_scope'=>$row['requirement_scope'],'course_type'=>$row['course_type'],
            'academic_level_id'=>$row['academic_level_id'],'recommended_semester_id'=>$row['recommended_semester_id'],'is_active'=>true];
        $saved=$this->workspace->save($actor,['request_id'=>$request,'revision'=>$this->tx->revision(),'confirmed'=>true,'new_courses'=>[],
            'targets'=>[['academic_program_id'=>$programId,'source_version_id'=>$sourceId,'courses'=>$memberships,'requirements'=>$p['requirements']]]]);
        $versionId=(int)$saved['targets'][0]['academic_plan_version_id'];$program=AcademicProgram::findOrFail($programId);$version=AcademicPlanVersion::findOrFail($versionId);
        if(!hash_equals($history,app(CatalogCompletionService::class)->academicFingerprint()))$this->fail('history_changed');
        $after=$this->workspace->values($this->plans->versionProjection($actor,$program,$version));
        $proof=['manifest_hash'=>$hash,'program_key'=>$p['key'],'source_version_id'=>$historicalId,'source_fingerprint'=>$sourceHash,'new_program_created'=>$created,
            'reference_kind'=>'approved_now_from_recorded_source_curriculum_not_historical_approval','content_fingerprint'=>hash('sha256',json_encode($after,JSON_THROW_ON_ERROR)),
            'before'=>$current['before']['plan'],'after'=>$after,'source_rows'=>$p['courses'],'created_origins'=>$newOrigins,'explicit_approvals'=>$p['requirements']['groups'],'academic_history_fingerprint'=>$history];
        DB::table('academic_plan_events')->insert(['academic_program_id'=>$programId,'academic_plan_version_id'=>$versionId,'action'=>'catalog_completion_published',
            'actor_user_id'=>$actor->getKey(),'context'=>json_encode($proof,JSON_THROW_ON_ERROR),'created_at'=>now()]);
        return ['key'=>$p['key'],'status'=>'PUBLISHED','academic_program_id'=>$programId,'academic_plan_version_id'=>$versionId,'created_origins'=>$newOrigins];
    }

    private function publishedOrigins(User $actor,string $hash): array
    {
        $out=[];
        foreach(DB::table('academic_plan_events')->where('action','catalog_completion_published')->where('context->manifest_hash',$hash)->get() as $e) {
            if(!$this->access->programs($actor)->whereKey($e->academic_program_id)->exists())continue;
            try {$proof=app(CatalogCompletionPublication::class)->assertTarget($actor,['academic_program_id'=>$e->academic_program_id,'academic_plan_version_id'=>$e->academic_plan_version_id],$hash);}
            catch(\App\Exceptions\AcademicPlanException){continue;} // A changed publication cannot authorize reuse of its origins.
            foreach($proof['created_origins']??[] as $code=>$origin)$out[$code]=$origin;
        }
        return $out;
    }

    private function assertStage(User $actor,array $saved,array $program,string $hash): void
    {
        if(($saved['key']??null)!==$program['key']||!in_array($saved['status']??null,['PUBLISHED','UNCHANGED'],true))$this->fail('publication_changed');
        $proof=app(CatalogCompletionPublication::class)->assertTarget($actor,$saved,$hash);
        if(($proof['program_key']??null)!==$program['key'])$this->fail('publication_changed');
        $actualProgram=$this->access->programs($actor)->findOrFail($saved['academic_program_id']);
        if((int)$actualProgram->department_id!==(int)$program['department_id'])$this->fail('publication_changed');
        if(isset($saved['created_origins'])&&!hash_equals(CompletionData::hash($saved['created_origins']),CompletionData::hash($proof['created_origins']??[])))$this->fail('publication_changed');
        foreach($proof['created_origins']??[] as $origin) {
            $course=$this->courses->courses($actor)->find($origin['course_id']);
            if(!$course||!hash_equals(CompletionData::hash($this->origin($origin)),CompletionData::hash($this->origin($course->only(array_keys($origin))))))$this->fail('created_origin_changed');
        }
    }

    public function status(User $actor,array $bundle,string $id): array
    {
        $profile=$this->authorize($actor,$bundle);if(!Uuid::isValid($id))$this->fail('operation_invalid');
        return $this->tx->snapshot(function()use($actor,$profile,$id) {
            $receipt=$this->receipts->find('followup_catalog_started',strtolower($id));if(!$receipt)return ['status'=>'NOT_FOUND'];
            abort_unless((int)$receipt['actor_id']===(int)$actor->getKey(),403);
            if(($receipt['value']['profile_hash']??null)!==$profile['hash'])$this->fail('request_mismatch');
            return $this->receipts->find('followup_catalog_result',strtolower($id))['value']['result']??['status'=>'INTERRUPTED'];
        });
    }

    public function verify(User $actor,array $bundle): array
    {
        $result=$this->preview($actor,$bundle);$rows=$result['review']['programs'];
        return ['status'=>collect($rows)->every(fn($r)=>$r['published_version_id']&&$r['blockers']===[])?'COMPLETE':'PARTIAL','writes_performed'=>false,'programs'=>$rows];
    }
    private function check(User $actor,array $receipt,string $digest): void
    {
        abort_unless((int)$receipt['actor_id']===(int)$actor->getKey(),403);if(!hash_equals($receipt['value']['digest']??'',$digest))$this->fail('request_mismatch');
    }
    private function origin(array $value): array
    {
        foreach(['course_id','credit_hours','theoretical_hours','practical_hours'] as $key)if(isset($value[$key]))$value[$key]=(int)$value[$key];
        if(isset($value['is_active']))$value['is_active']=(bool)$value['is_active'];return $value;
    }
    private function fail(string $code): never {throw new CompletionException('catalog_followup_'.$code,'Catalog follow-up blocked: '.$code.'. Preserve the review and explicitly prepare a new operation when its intent changes.');}
}
