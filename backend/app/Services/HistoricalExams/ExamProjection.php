<?php
namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\{CompletionData,CompletionProgramIdentity};

/** Pure reconciliation shared by snapshot analysis, live preview and locked revalidation. */
final class ExamProjection
{
    public const CONTRACT='historical-exam-completion.review.v3';
    public function index(array $state): array
    {
        foreach(['student_course_registrations'=>'student_id','student_course_results'=>'student_course_registration_id','grade_components'=>'course_offering_id','student_grade_components'=>'student_course_registration_id'] as $table=>$key) {
            $state['_'.$table]=[];foreach($state[$table]??[] as $row)$state['_'.$table][$row[$key]][]=$row;
        }
        $state['_historical_configuration_evidence']=[];$offeringByRegistration=array_column($state['student_course_registrations'],'course_offering_id','student_course_registration_id');
        foreach(['student_course_results','student_grade_components','student_course_incomplete_cases','supplementary_exam_registrations'] as $table)
            foreach($state[$table]??[] as $e)if($id=$offeringByRegistration[$e['student_course_registration_id']??null]??null)$state['_historical_configuration_evidence'][$id]=true;
        foreach(['grade_approvals','grade_part_approvals'] as $table)foreach($state[$table]??[] as $e)$state['_historical_configuration_evidence'][$e['course_offering_id']]=true;
        return $state;
    }
    public static function cents(mixed $v): ?int
    {
        $v=CompletionData::text($v);if(!preg_match('/^(\d{1,3})(?:\.(\d{1,2})0*)?$/D',$v,$m))return null;
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public function build(array $bundle,array $state,string $revision): array
    {
        $state=$this->index($state);$actions=[];$groups=[];
        foreach($bundle['rows'] as $row) {
            $a=$this->row($row,$state,$bundle['student_master']??[]);$a['references']=[$row['reference']+['row_hash'=>$row['row_hash']]];
            $v=$row['values'];
            $key=$a['attempt_key']??('unresolved-source/'.CompletionData::hash([$a['owner']['number']??$v['number'],$row['kind'],CompletionData::text($v['college']),CompletionData::text($v['program']),preg_replace('/\s+/u','',CompletionData::text($v['code'])),CompletionData::text($v['course']),$v['credits'],$v['year']??null]));
            $groups[$key][]=$a;
        }
        foreach($groups as $rows) {
            $facts=[];foreach($rows as $r)$facts[CompletionData::hash($r['interpreted'])]=true;
            if(count($facts)>1)foreach($rows as &$r){$r['blockers'][]='conflicting_observations_not_distinct_attempt_evidence';$r['classification']='source_conflict';$r['eligible']=false;}unset($r);
            $dedup=[];
            foreach($rows as $r) {
                $key=CompletionData::hash([$r['interpreted'],$r['blockers']]);
                if(isset($dedup[$key])){$dedup[$key]['references']=[...$dedup[$key]['references'],...$r['references']];continue;}
                $dedup[$key]=$r;
            }
            foreach($dedup as $r){$r['action_id']=CompletionData::hash([$r['attempt_key']??null,$r['references']]);$actions[]=$r;}
        }
        $counts=['observations'=>count($bundle['rows']),'actions'=>count($actions),'eligible'=>0,'no_op'=>0,'conflict'=>0,'deferred'=>0,'pending_approval'=>0,'classifications'=>[]];
        foreach($actions as $a){$counts['eligible']+=(int)$a['eligible'];$counts['no_op']+=(int)($a['classification']==='existing_equivalent'&&!$a['blockers']);$counts['conflict']+=(int)in_array($a['classification'],['source_conflict','existing_value_conflict'],true);$counts['deferred']+=(int)($a['classification']==='deferred_identity');$counts['pending_approval']+=(int)($a['classification']==='pending_approval');$counts['classifications'][$a['classification']]=($counts['classifications'][$a['classification']]??0)+1;}
        $counts['aggregate_known_component_breakdown_missing']=collect($actions)->filter(fn($a)=>in_array('aggregate_known_component_breakdown_missing',$a['blockers'],true))->count();
        $initializations=collect($actions)->filter(fn($a)=>$a['eligible']&&$a['component_mapping']['initialize_aggregate_profile']);
        $counts['aggregate_initialization_actions']=$initializations->count();
        $counts['aggregate_initialization_contexts']=$initializations->map(fn($a)=>implode('/',[$a['interpreted']['course_id'],$a['interpreted']['program_id'],$a['period']['academic_year_id'],$a['period']['semester_id']]))->unique()->count();
        $review=['contract'=>self::CONTRACT,'sources'=>$bundle['sources'],'bundle_file_hash'=>$bundle['artifact_file_sha256']??null,'bundle_digest'=>CompletionData::hash($bundle),'revision'=>$revision,'actions'=>$actions];
        return ['status'=>$counts['eligible']?'PARTIAL':($counts['no_op']===count($actions)?'COMPLETE':'BLOCKED'),'writes_performed'=>false,'counts'=>$counts,'review'=>$review,'review_hash'=>CompletionData::hash($review)];
    }
    public function row(array $row,array $s,array $master=[]): array
    {
        $v=$row['values'];$identity=new ExamIdentity;$owner=$identity->student($row,$s);$student=$owner['student'];$b=$owner['blockers'];
        $p=$identity->sourceProgram($row,$owner,$s,$master);
        // An existing uniquely identified registration may supply a missing source program, never the current/default plan.
        if(!$p['program_id']&&$student&&CompletionData::text($v['program'])===''&&!($p['evidence_conflict']??false)) {
            $os=array_column($s['course_offerings'],null,'course_offering_id');$cs=array_column($s['courses'],null,'course_id');$ys=array_column($s['academic_years'],null,'academic_year_id');$candidates=[];
            foreach($s['_student_course_registrations'][$student['student_id']]??$s['student_course_registrations'] as $rr)if((int)$rr['student_id']===(int)$student['student_id']) {
                $oo=$os[$rr['course_offering_id']]??null;$cc=$oo?($cs[$oo['course_id']]??null):null;
                if($cc&&preg_replace('/\s+/u','',$cc['course_code'])===preg_replace('/\s+/u','',CompletionData::text($v['code']))&&($row['kind']==='undated_supplementary'||CompletionData::year($ys[$oo['academic_year_id']]['year_name'])===CompletionData::year($v['year']??null)))$candidates[$oo['academic_program_id']]=true;
            }
            if(count($candidates)===1) {
                $pp=collect($s['academic_programs'])->firstWhere('academic_program_id',array_key_first($candidates));
                $evidence=$p;$p=$identity->program(array_replace($v,['program'=>$pp['program_name']]),$s);$p['basis']='unique_existing_registration_program_not_student_default';$p['student_master_evidence']=$evidence['student_master_evidence'];
            }
        }
        // Data is supplementary source evidence, never permission to move an already recorded attempt into another program.
        if($student&&CompletionData::text($v['program'])===''&&$p['basis']==='unique_qualified_student_master') {
            $contexts=[];$os=array_column($s['course_offerings'],null,'course_offering_id');$cs=array_column($s['courses'],null,'course_id');$ys=array_column($s['academic_years'],null,'academic_year_id');
            foreach($s['_student_course_registrations'][$student['student_id']]??$s['student_course_registrations'] as $rr)if((int)$rr['student_id']===(int)$student['student_id']) {
                $oo=$os[$rr['course_offering_id']]??null;$cc=$oo?($cs[$oo['course_id']]??null):null;$pp=$oo?collect($s['academic_programs'])->firstWhere('academic_program_id',$oo['academic_program_id']):null;
                $approved=$pp?(new \App\Services\CatalogCompletion\FollowupCourseIdentity)->historicalMapping($pp['program_code']??'',preg_replace('/\s+/u','',CompletionData::text($v['code'])),CompletionData::text($v['course']),(int)$v['credits']):null;
                if($cc&&in_array(preg_replace('/\s+/u','',$cc['course_code']),array_filter([preg_replace('/\s+/u','',CompletionData::text($v['code'])),$approved['system_code']??null]),true)
                    &&($row['kind']==='undated_supplementary'||CompletionData::year($ys[$oo['academic_year_id']]['year_name'])===CompletionData::year($v['year']??null)))
                    $contexts[(int)$oo['academic_program_id']][]=['registration_id'=>$rr['student_course_registration_id'],'offering_id'=>$oo['course_offering_id'],'program_id'=>$oo['academic_program_id']];
            }
            if(count($contexts)>1){$p['blockers'][]='student_master_existing_registration_context_ambiguous';$p['existing_registration_contexts']=$contexts;}
            elseif(count($contexts)===1&&(int)array_key_first($contexts)!==(int)$p['program_id']) {
                $evidence=$p;$pp=collect($s['academic_programs'])->firstWhere('academic_program_id',array_key_first($contexts));$p=$identity->program(array_replace($v,['program'=>$pp['program_name']]),$s);
                $p['basis']='existing_registration_program_with_conflicting_student_master';$p['student_master_evidence']=$evidence['student_master_evidence'];$p['reviewed_data_program_id']=$evidence['program_id'];$p['existing_registration_contexts']=$contexts;$p['blockers'][]='student_master_existing_registration_context_conflict';
            }
        }
        $b=[...$b,...$p['blockers']];$program=$p['program_id']??null;
        $c=$program?$identity->course($v,$program,$s,$student?(int)$student['student_id']:null):['course'=>null,'mapping'=>null,'blockers'=>['program_context_unresolved']];$b=[...$b,...$c['blockers']];$course=$c['course'];
        $year=CompletionData::year($v['year']??null);$years=array_values(array_filter($s['academic_years'],fn($y)=>CompletionData::year($y['year_name'])===$year));
        if($year===null||count($years)!==1)$b[]=$row['kind']==='regular'?'academic_year_unresolved':'supplementary_sitting_undated';$yearId=$year!==null&&count($years)===1?(int)$years[0]['academic_year_id']:null;
        $offerings=array_column($s['course_offerings'],null,'course_offering_id');$attempts=[];$otherPrograms=[];
        if($student&&$course&&$yearId)foreach($s['_student_course_registrations'][$student['student_id']]??$s['student_course_registrations'] as $r) {
            $o=$offerings[$r['course_offering_id']]??null;
            if($o&&(int)$r['student_id']===(int)$student['student_id']&&(int)$o['course_id']===(int)$course['course_id']&&(int)$o['academic_year_id']===$yearId) {
                if((int)$o['academic_program_id']===$program)$attempts[]=$r;else $otherPrograms[]=['registration_id'=>$r['student_course_registration_id'],'offering_id'=>$o['course_offering_id'],'academic_program_id'=>$o['academic_program_id']];
            }
        }
        if(!$attempts&&$otherPrograms)$b[]='existing_registration_other_program_requires_review';
        if(count($attempts)>1)$b[]='repeated_attempt_semester_not_proven';$reg=count($attempts)===1?$attempts[0]:null;$offering=$reg?$offerings[$reg['course_offering_id']]:null;
        $semester=$offering?(int)$offering['semester_id']:null;$period=['basis'=>$semester?'existing_registration_actual_period':null,'academic_year_id'=>$yearId,'semester_id'=>$semester];
        $term=filter_var($v['term'],FILTER_VALIDATE_INT);$members=$program&&$course?array_values(array_filter($s['program_courses']??[],fn($m)=>(int)$m['academic_program_id']===$program&&(int)$m['course_id']===(int)$course['course_id'])):[];
        if(!$reg&&$course&&$student&&$yearId) {
            $pr=collect($s['academic_programs'])->firstWhere('academic_program_id',$program);$version=$pr['default_academic_plan_version_id']??null;
            $approved=collect($s['academic_plan_versions'])->first(fn($x)=>(int)$x['academic_plan_version_id']===(int)$version&&$x['status']==='approved'&&!empty($x['fixed_at']));
            $placements=array_values(array_filter($members,fn($m)=>$approved&&(int)($m['academic_plan_version_id']??0)===(int)$version));
            $terms=array_values(array_unique(array_map(function($m)use($s){$l=collect($s['academic_levels'])->firstWhere('academic_level_id',$m['academic_level_id']);$t=collect($s['semesters'])->firstWhere('semester_id',$m['recommended_semester_id']);return ((int)($l['level_order']??0)-1)*2+(int)($t['semester_order']??0);},$placements)));
            if(!$approved||count($terms)!==1)$b[]='approved_guidance_plan_missing_or_ambiguous';
            elseif($term!==$terms[0])$b[]='source_and_approved_guidance_terms_conflict';
            if($term===false||$term<1||$term>8)$b[]='guidance_term_invalid';
            $semesters=array_values(array_filter($s['semesters'],fn($t)=>(int)($t['semester_order']??0)===($term%2?1:2)));
            if(count($semesters)!==1)$b[]='semester_identity_unresolved';else $semester=(int)$semesters[0]['semester_id'];
            $period=['basis'=>'administrative_guidance_fallback_not_verified_exam_date','academic_year_id'=>$yearId,'semester_id'=>$semester,'source_plan_term'=>$v['term'],'academic_plan_version_id'=>$version,'program_course_ids'=>array_column($placements,'program_course_id'),'rule'=>'odd_1_3_5_7_first_even_2_4_6_8_second','authority'=>'explicit_user_request_historical_exam_completion'];
            $choices=array_values(array_filter($s['course_offerings'],fn($o)=>(int)$o['course_id']===(int)$course['course_id']&&(int)$o['academic_program_id']===$program&&(int)$o['academic_year_id']===$yearId&&(int)$o['semester_id']===$semester));
            if(count($choices)>1)$b[]='offering_ambiguous';elseif(count($choices)===1)$offering=$choices[0];
            if((int)$student['academic_program_id']!==$program)$b[]='historical_program_registration_requires_review';
        }
        $results=$reg?($s['_student_course_results'][$reg['student_course_registration_id']]??array_values(array_filter($s['student_course_results'],fn($r)=>(int)$r['student_course_registration_id']===(int)$reg['student_course_registration_id']))):[];
        if(count($results)>1)$b[]='multiple_results_invalid';$result=count($results)===1?$results[0]:null;
        $status=$result?collect($s['result_statuses'])->firstWhere('result_status_id',$result['result_status_id']):null;
        $condition=CompletionData::text($v['condition']??null);$exam=self::cents($v['exam']);$work=self::cents($v['coursework']??null);$total=self::cents($v['total']??null);
        $interpreted=['student_number'=>$owner['number'],'course_id'=>$course?(int)$course['course_id']:null,'program_id'=>$program,'year_id'=>$yearId,'semester_id'=>$semester,'kind'=>$row['kind'],'coursework'=>$v['coursework']??null,'examination'=>$v['exam'],'total'=>$v['total']??null,'condition'=>$condition,'supplementary'=>$v['supplementary']??null];
        if($row['kind']==='regular'&&$total!==null&&$exam!==null&&$work!==null&&$total!==$exam+$work)$b[]='cached_total_inconsistent_or_folded_supplementary';
        if(CompletionData::text($v['supplementary']??null)!=='')$b[]='cached_supplementary_effect_requires_dated_sitting';
        $sourceStatus=match($condition){'F'=>'failed','Z'=>'deprived','W'=>'withdrawn','I'=>'incomplete',default=>null};
        if(!in_array($condition,['','T','F','Z','W','I','معادلة'],true))$b[]='source_condition_not_supported_by_ordinary_completion';
        $conflict=$result&&(($sourceStatus!==null&&($status['status_code']??null)!==$sourceStatus)||($total!==null&&self::cents($result['final_mark'])!==$total));$candidateConflicts=[];
        // Investigation only: retain a direct F/passed discrepancy even when program/subject resolution is blocked.
        if($student&&$sourceStatus==='failed'&&$total!==null)foreach($s['_student_course_registrations'][$student['student_id']]??$s['student_course_registrations'] as $rr)if((int)$rr['student_id']===(int)$student['student_id']) {
            $oo=$offerings[$rr['course_offering_id']]??null;$cc=$oo?collect($s['courses'])->firstWhere('course_id',$oo['course_id']):null;
            $yy=$oo?collect($s['academic_years'])->firstWhere('academic_year_id',$oo['academic_year_id']):null;
            if(!$cc||preg_replace('/\s+/u','',$cc['course_code'])!==preg_replace('/\s+/u','',CompletionData::text($v['code']))||CompletionData::year($yy['year_name'])!==$year)continue;
            foreach($s['_student_course_results'][$rr['student_course_registration_id']]??$s['student_course_results'] as $res)if((int)$res['student_course_registration_id']===(int)$rr['student_course_registration_id']&&self::cents($res['final_mark'])===$total
                &&(collect($s['result_statuses'])->firstWhere('result_status_id',$res['result_status_id'])['status_code']??null)==='passed')$candidateConflicts[]=['registration_id'=>$rr['student_course_registration_id'],'offering_id'=>$oo['course_offering_id'],'result'=>$res,'basis'=>'direct_total_agrees_but_condition_F_disagrees_with_passed_not_full_identity_acceptance'];
        }
        if($candidateConflicts)$conflict=true;
        $components=$offering?array_values(array_filter($s['grade_components']??[],fn($x)=>(int)$x['course_offering_id']===(int)$offering['course_offering_id'])):[];$storedComponents=$components;
        $policies=array_values(array_filter($s['grading_policies']??[],fn($x)=>(bool)$x['is_active']));usort($policies,fn($a,$b)=>(int)$a['grading_policy_id']<=>(int)$b['grading_policy_id']);
        $defaults=array_values(array_filter($policies,fn($x)=>(bool)$x['is_default']));$policy=count($defaults)<=1?($defaults[0]??$policies[0]??null):null;
        $initialize=!$components&&$course&&$policy&&self::cents($policy['theoretical_max_mark'])===6000&&self::cents($policy['practical_max_mark'])===4000;
        if($initialize) {
            $evidence=$s['_historical_configuration_evidence']??$this->index($s)['_historical_configuration_evidence'];
            if($offering&&isset($evidence[$offering['course_offering_id']]))$b[]='historical_aggregate_initialization_existing_evidence';
            // A virtual profile cannot explain or contradict retained results when no actual definitions exist.
            $initialize=!in_array('historical_aggregate_initialization_existing_evidence',$b,true);
            if($initialize)$components=ExamComponentMapping::aggregateComponents();
        } elseif(!$components&&$course&&$policy)$b[]='historical_aggregate_policy_incompatible';
        $marks=$reg?array_values(array_filter($s['student_grade_components']??[],fn($x)=>(int)$x['student_course_registration_id']===(int)$reg['student_course_registration_id'])):[];
        $componentMapping=(new ExamComponentMapping)->inspect($v,$components,$marks,$policy,$result,(bool)$initialize);$writes=$componentMapping['proposed_components'];$b=[...$b,...$componentMapping['blockers']];
        if($componentMapping['conflict']||($result&&$componentMapping['canonical_outcome']!==null&&($status['status_code']??null)!==$componentMapping['canonical_outcome']))$conflict=true;
        if($result&&(bool)($result['is_deprived']??false)!==($sourceStatus==='deprived'))$conflict=true;
        if(in_array($sourceStatus,['deprived','withdrawn','incomplete'],true)||$condition==='معادلة')$b[]='symbol_requires_existing_authorized_workflow';
        $lifecycle=$reg?array_values(array_filter($s['student_course_incomplete_cases']??[],fn($x)=>(int)$x['student_course_registration_id']===(int)$reg['student_course_registration_id'])):[];
        if($lifecycle)$b[]='incomplete_lifecycle_owned_all_states';
        if($reg) {
            $rs=collect($s['registration_statuses'])->firstWhere('registration_status_id',$reg['registration_status_id']);
            if(!in_array($rs['status_code']??null,['registered','completed'],true))$b[]='registration_status_protected';
            foreach($s['supplementary_exam_registrations']??[] as $x)if((int)($x['student_course_registration_id']??0)===(int)$reg['student_course_registration_id'])$b[]='supplementary_registration_requires_review';
        }
        $approvals=$offering?array_values(array_filter($s['grade_approvals'],fn($x)=>(int)$x['course_offering_id']===(int)$offering['course_offering_id'])):[];
        usort($approvals,fn($a,$b)=>(int)$b['grade_approval_id']<=>(int)$a['grade_approval_id']);$approval=$approvals[0]??null;
        $official=$approval&&(collect($s['approval_statuses'])->firstWhere('approval_status_id',$approval['approval_status_id'])['status_code']??null)==='approved';
        $parts=$offering?array_values(array_filter($s['grade_part_approvals']??[],fn($x)=>(int)$x['course_offering_id']===(int)$offering['course_offering_id'])):[];
        $mutationBlockers=$official||collect($parts)->contains(fn($x)=>!in_array($x['status'],['draft','returned'],true))?['official_or_submitted_result_locked']:[];
        if(($writes||!$result)&&$mutationBlockers)$b=[...$b,...$mutationBlockers];
        $classification=$row['kind']!=='regular'?'unresolved_period':(!$student?'deferred_identity':($conflict?'existing_value_conflict':(!$course?'unresolved_course':(!$yearId||!$semester?'unresolved_period':($lifecycle?'protected_result':(!$reg?'missing_registration':(!$result?'missing_result':($writes?'missing_component':($official?'existing_equivalent':'pending_approval')))))))));
        if($conflict)$b[]='existing_values_require_separate_correction_review';
        $equivalent=$classification==='existing_equivalent'&&$b===[];
        if($classification==='existing_equivalent'&&!$equivalent)$classification='protected_result';
        if(array_intersect($b,['repeated_attempt_semester_not_proven','offering_ambiguous']))$classification='ambiguous_attempt';
        if($classification==='missing_result'&&!$writes&&!$b){$classification='pending_approval';$b[]='canonical_finalization_pending_no_missing_marks';}
        $parents=[];
        if($row['kind']==='undated_supplementary'&&$student&&$course)foreach($s['_student_course_registrations'][$student['student_id']]??$s['student_course_registrations'] as $rr) {
            $oo=$offerings[$rr['course_offering_id']]??null;
            if((int)$rr['student_id']===(int)$student['student_id']&&$oo&&(int)$oo['course_id']===(int)$course['course_id'])$parents[]=['registration_id'=>$rr['student_course_registration_id'],'offering_id'=>$oo['course_offering_id'],'academic_year_id'=>$oo['academic_year_id'],'semester_id'=>$oo['semester_id'],'basis'=>'possible_parent_only_not_supplementary_sitting_date'];
        }
        $assignments=$student?array_values(array_filter($s['student_academic_plan_assignments']??[],fn($x)=>(int)$x['student_id']===(int)$student['student_id'])):[];
        $baseline=['student'=>$student,'course'=>$course,'program_id'=>$program,'program_evidence'=>$p,'other_program_registrations'=>$otherPrograms,'assignments'=>$assignments,'memberships'=>$members,'period'=>$period,'offering'=>$offering,'registration'=>$reg,'result'=>$result,'components'=>$storedComponents,'marks'=>$marks,'approvals'=>$approvals,'parts'=>$parts,'lifecycle'=>$lifecycle,'policy'=>$policy];
        return ['source_id'=>$row['id'],'source_values'=>$v,'owner'=>$owner,'program_evidence'=>$p,'course_mapping'=>$c['mapping'],'component_mapping'=>$componentMapping,'mutation_blockers'=>$mutationBlockers,'period'=>$period,'interpreted'=>$interpreted,
            'attempt_key'=>$student&&$course&&$yearId?implode('/',[$student['student_id'],$course['course_id'],$yearId,$semester??'unknown']):null,
            'classification'=>$classification,'eligible'=>!$b&&!$equivalent&&($writes!==[]||!$reg),'blockers'=>array_values(array_unique($b)),'possible_parent_contexts'=>$parents,
            'proposed_components'=>$writes,'baseline'=>$baseline,'baseline_hash'=>CompletionData::hash($baseline),'official'=>$official,
            'required_workflow'=>'Draft marks only; canonical part submission, review, approval and offering finalization remain mandatory; official readers decide GPA eligibility.',
            'correction_proposal'=>$conflict?['existing'=>$result,'candidate_conflicts'=>$candidateConflicts,'source'=>$v,'apply_allowed'=>false]:null];
    }
}
