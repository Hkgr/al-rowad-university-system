<?php

namespace App\Services\CatalogCompletion;

use App\Services\StudentCompletion\CompletionData;

/** Exact, program-qualified evidence. Never infer equivalence from a code/credit match. */
final class FollowupCourseIdentity
{
    public const REVISION='followup-course-identities.2026-10-18.v2';
    private const CIVIL=[
        'exam1:DBMater:833'=>['CE211','علم السكون – استاتيك',4,'TI-CE-211'],
        'exam1:DBMater:834'=>['CE212','جيولوجيا هندسية',3,'TI-CE-212'],
        'exam1:DBMater:846'=>['CE222','منشأ بيتون مسلح (2)',3,'TI-CE-222'],
    ];
    // Exact documented university labels, not a fuzzy Arabic-name normalizer.
    // The existing shared university membership is independently required too.
    private const SHARED=[
        'RU111'=>['اللغة العربية (1)','لغة عربية (1)'],
        'RU112'=>['اللغة الإنكليزية (1)','لغة انكليزية (1)'],
        'RU113'=>['مدخل الى المعلوماتيه','مدخل الى المعلوماتية'],
        'RU121'=>['اللغة العربية (2)','لغة عربية (2)'],
        'RU122'=>['اللغة الإنكليزية (2)','لغة الإنكليزية (2)'],
        'RU123'=>['حضارة وثقافة','الحضارة والثقافة'],
    ];

    public function nameMatches(array $row,array $origin): bool
    {
        $source=CompletionData::text($row['course_name']);$actual=CompletionData::text($origin['course_name']);
        if($source===$actual)return true;
        $labels=self::SHARED[$row['system_code']]??[];
        return ($row['requirement_scope']??null)==='university'&&(int)$row['credit_hours']===2&&(int)$origin['credit_hours']===2
            &&in_array($source,$labels,true)&&in_array($actual,$labels,true);
    }

    /** Context references use stable IDs, not a student's current/default plan. */
    public function contexts(array $state,int $courseId): array
    {
        $out=[];
        foreach($state['course_departments']??[] as $d)if((int)$d['course_id']===$courseId)$out[]=['kind'=>'course_department','id'=>(int)$d['course_department_id'],'department_id'=>(int)$d['department_id']];
        $programs=array_column($state['academic_programs']??[],null,'academic_program_id');$groups=array_column($state['academic_requirement_groups']??[],null,'requirement_group_id');
        $mapping=array_column($state['program_course_requirement_groups']??[],null,'program_course_id');
        foreach($state['program_courses']??[] as $pc)if((int)$pc['course_id']===$courseId&&(bool)($pc['is_active']??true)) {
            $program=$programs[$pc['academic_program_id']]??null;$g=$groups[$mapping[$pc['program_course_id']]['requirement_group_id']??0]??null;
            if(!$program||!$g||!(bool)($g['is_active']??true))continue;
            $out[]=['kind'=>$g['requirement_scope']==='university'?'university_membership':'program_membership','id'=>(int)$pc['program_course_id'],
                'program_id'=>(int)$pc['academic_program_id'],'department_id'=>(int)$program['department_id'],'requirement_group_id'=>(int)$g['requirement_group_id']];
        }
        usort($out,fn($a,$b)=>[$a['kind'],$a['id']]<=>[$b['kind'],$b['id']]);return $out;
    }

    public function contextMatches(array $row,array $program,array $contexts): bool
    {
        foreach($contexts as $c)if(($row['requirement_scope']==='university'&&$c['kind']==='university_membership')
            ||($row['requirement_scope']!=='university'&&(int)$c['department_id']===(int)$program['department_id']))return true;
        return false;
    }

    public function liveContexts(int $courseId): array
    {
        $out=[];
        foreach(\Illuminate\Support\Facades\DB::table('course_departments')->where('course_id',$courseId)->orderBy('course_department_id')->get() as $d)
            $out[]=['kind'=>'course_department','id'=>(int)$d->course_department_id,'department_id'=>(int)$d->department_id];
        $members=\Illuminate\Support\Facades\DB::table('program_courses as pc')->join('academic_programs as p','p.academic_program_id','=','pc.academic_program_id')
            ->join('program_course_requirement_groups as m','m.program_course_id','=','pc.program_course_id')->join('academic_requirement_groups as g','g.requirement_group_id','=','m.requirement_group_id')
            ->where('pc.course_id',$courseId)->where('pc.is_active',true)->where('g.is_active',true)->orderBy('pc.program_course_id')
            ->get(['pc.program_course_id','p.academic_program_id','p.department_id','g.requirement_group_id','g.requirement_scope']);
        foreach($members as $m)$out[]=['kind'=>$m->requirement_scope==='university'?'university_membership':'program_membership','id'=>(int)$m->program_course_id,
            'program_id'=>(int)$m->academic_program_id,'department_id'=>(int)$m->department_id,'requirement_group_id'=>(int)$m->requirement_group_id];
        usort($out,fn($a,$b)=>[$a['kind'],$a['id']]<=>[$b['kind'],$b['id']]);return $out;
    }

    public function origin(array $c): array
    {
        $c=array_intersect_key($c,array_flip(['course_id','course_code','course_name','credit_hours','theoretical_hours','practical_hours','is_active']));
        foreach(['course_id','credit_hours','theoretical_hours','practical_hours'] as $k)if(isset($c[$k]))$c[$k]=(int)$c[$k];
        $c['is_active']=(bool)$c['is_active'];return $c;
    }

    /** Only the explicitly new preparation revision invokes this transformation. */
    public function revise(array $config,array $state,array $tables): array
    {
        if(($config['identity_mapping_revision']??null)!==self::REVISION)throw new \InvalidArgumentException('Unsupported follow-up identity mapping revision.');
        $sources=array_column($tables['Curriculum'],null,'record_id');$byCode=[];$contextMap=[];
        foreach($state['courses'] as $c){$byCode[$c['course_code']][]=$c;$contextMap[(int)$c['course_id']]=$this->contexts($state,(int)$c['course_id']);}
        $approval=['decision_id'=>'civil-distinct-course-identities-2026-10-18','authority'=>'USER_EXPLICIT_REQUEST','approved_at'=>'2026-10-18',
            'decision'=>'Only Civil source rows DBMater 833, 834 and 846 use TI-CE-211, TI-CE-212 and TI-CE-222. Existing CE origins remain unchanged.'];
        if(!in_array($approval,$config['approvals']??[],true))$config['approvals'][]=$approval;
        foreach($config['programs'] as &$p) {
            foreach(['courses','elective_alternatives'] as $kind)foreach($p[$kind]??[] as $i=>$row) {
                $source=$sources[$row['record_id']]??null;$original=$source['source_code']??null;
                if($p['key']==='engineering_civil'&&isset(self::CIVIL[$row['record_id']])) {
                    [$code,$name,$credits,$system]=self::CIVIL[$row['record_id']];
                    if(!$source||CompletionData::text($original)!==$code||CompletionData::text($source['course_name'])!==$name||(int)$source['credits']!==$credits) {
                        $p['blockers'][]='approved_identity_source_changed:'.$row['record_id'];
                    } else {
                        $row['system_code']=$system;
                        $config['amendments'][$row['record_id']]=['before_code'=>$code,'before_credits'=>$credits,'system_code'=>$system,'credit_hours'=>$credits,
                            'reason'=>'Distinct institute subject; existing source-code origin is a different course.','approval_reference'=>$approval];
                    }
                }
                // Never keep the old code-only origin or its inherited prerequisite proposal.
                unset($row['existing']);$row['existing_prerequisites']=[];$row['prerequisite_codes']=[];
                $row['original_source_code']=$original;$matches=$byCode[$row['system_code']]??[];$candidate=count($matches)===1?$matches[0]:null;
                $contexts=$candidate?$contextMap[(int)$candidate['course_id']]:[];
                $valid=$candidate&&$this->nameMatches($row,$candidate)&&(int)$candidate['credit_hours']===$row['credit_hours']
                    &&(bool)$candidate['is_active']&&$this->contextMatches($row,$p,$contexts);
                $alternates=[];
                if(!$matches&&!isset($config['amendments'][$row['record_id']])&&$original!==null)foreach($state['courses'] as $other) {
                    $otherContext=$contextMap[(int)$other['course_id']];
                    if($this->nameMatches($row,$other)&&(int)$other['credit_hours']===$row['credit_hours']&&$this->contextMatches($row,$p,$otherContext))
                        $alternates[]=['origin'=>$this->origin($other),'context'=>$otherContext];
                }
                $blocked=count($matches)>1||($candidate&&!$valid)||$alternates!==[];
                if($alternates)$p['blockers'][]='different_code_identity_requires_review:'.$row['record_id'];
                elseif($blocked)$p['blockers'][]='course_identity_unverified:'.$row['record_id'];
                if($valid)$row['existing']=$this->origin($candidate);
                $row['identity_audit']=['revision'=>self::REVISION,'record_id'=>$row['record_id'],'source_hash'=>$row['source_hash'],
                    'source_code'=>$original,'source_name'=>$row['course_name'],'system_code'=>$row['system_code'],'credits'=>$row['credit_hours'],
                    'program_key'=>$p['key'],'college_code'=>$p['college_code'],'department_id'=>$p['department_id'],
                    'candidate'=>$candidate?$this->origin($candidate):null,'candidate_context'=>$contexts,
                    'alternate_candidates'=>$alternates,
                    'state'=>$blocked?'BLOCKED':($valid?'VERIFIED_REUSE':'NEW_IDENTITY'),
                    'basis'=>$alternates?'different_code_equivalence_not_approved':($blocked?'no_verified_subject_and_scope_identity':($valid?(CompletionData::text($row['course_name'])===CompletionData::text($candidate['course_name'])?'exact_subject_and_scoped_origin':'documented_shared_university_alias'):'verified_source_definition_noncolliding_code')),
                    'mapping'=>$config['amendments'][$row['record_id']]??null];
                $p[$kind][$i]=$row;
            }
            $p['blockers']=array_values(array_unique($p['blockers']));
            $this->prerequisites($p,$state,$sources);
        }unset($p);
        // The same proposed code must describe the same subject across programs,
        // not merely have equal credits. No arbitrary suffix or automatic split.
        $proposed=[];foreach($config['programs'] as $p)foreach([...$p['courses'],...($p['elective_alternatives']??[])] as $r)$proposed[$r['system_code']][]=$r;
        foreach($config['programs'] as &$p)foreach([...$p['courses'],...($p['elective_alternatives']??[])] as $row)foreach($proposed[$row['system_code']] as $other)
            if(!$this->nameMatches($row,['course_name'=>$other['course_name'],'credit_hours'=>$other['credit_hours']])||$row['credit_hours']!==$other['credit_hours'])$p['blockers'][]='proposed_course_identity_collision:'.$row['record_id'];
        unset($p);foreach($config['programs'] as &$p)$p['blockers']=array_values(array_unique($p['blockers']));unset($p);return $config;
    }

    private function prerequisites(array &$p,array $state,array $sources): void
    {
        $ids=array_column($state['courses'],null,'course_id');$rows=$p['courses'];
        foreach($rows as $i=>$row) {
            $raw=CompletionData::text($sources[$row['record_id']]['prerequisite_source']??null);$required=[];
            if($raw!=='')foreach(preg_split('/[,،;\n]+/u',$raw) as $token) {
                $token=CompletionData::text($token);$match=array_values(array_filter($rows,fn($r)=>CompletionData::text($sources[$r['record_id']]['source_code']??null)===$token||CompletionData::text($r['course_name'])===$token));
                if(count($match)!==1||$match[0]['identity_audit']['state']==='BLOCKED'){$p['blockers'][]='source_prerequisite_identity_unresolved:'.$row['record_id'];continue;}
                if($match[0]['system_code']===$row['system_code']){$p['blockers'][]='source_prerequisite_self_edge:'.$row['record_id'];continue;}
                $required[]=$match[0]['system_code'];
            }
            $required=array_values(array_unique($required));sort($required);$row['prerequisite_codes']=$required;
            if(isset($row['existing']))foreach($state['course_prerequisites'] as $edge)if((int)$edge['course_id']===$row['existing']['course_id']) {
                $target=$ids[$edge['prerequisite_course_id']]??null;
                if(!$target){$p['blockers'][]='existing_prerequisite_identity_missing:'.$row['record_id'];continue;}
                $row['existing_prerequisites'][]=['course_code'=>$target['course_code'],'minimum_result_status_id'=>$edge['minimum_result_status_id']===null?null:(int)$edge['minimum_result_status_id']];
                if($raw==='')$p['blockers'][]='source_prerequisite_evidence_missing:'.$row['record_id'];
                if($edge['minimum_result_status_id']!==null)$p['blockers'][]='source_prerequisite_minimum_unproven:'.$row['record_id'];
            }
            usort($row['existing_prerequisites'],fn($a,$b)=>strcmp($a['course_code'],$b['course_code']));
            if(isset($row['existing'])&&$required!==array_column($row['existing_prerequisites'],'course_code'))$p['blockers'][]='used_origin_prerequisite_context_mismatch:'.$row['record_id'];
            $p['courses'][$i]=$row;
        }
        $edges=array_column($p['courses'],'prerequisite_codes','system_code');$done=[];$active=[];
        $visit=function($code)use(&$visit,&$done,&$active,$edges,&$p){if(isset($active[$code])){$p['blockers'][]='source_prerequisite_cycle';return;}if(isset($done[$code]))return;
            $active[$code]=true;foreach($edges[$code]??[] as $to)$visit($to);unset($active[$code]);$done[$code]=true;};
        foreach(array_keys($edges) as $code)$visit($code);$p['blockers']=array_values(array_unique($p['blockers']));
    }
}
