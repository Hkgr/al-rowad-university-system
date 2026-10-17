<?php

namespace App\Services\CatalogCompletion;

use App\Services\StudentCompletion\CompletionData;

/** Source-bound academic intent, not a new schema, academic calculator or unrestricted import. */
final class ApprovedFollowupCatalog
{
    private const UNITS=['engineering_civil'=>'INST-ENG','engineering_survey'=>'INST-ENG','engineering_drawing'=>'INST-ENG','environment'=>'INST-CD'];

    public function compile(array $bundle,array $handoff): array
    {
        $c=$bundle['config']['followup']['catalog'];
        foreach(['Programs','Curriculum','CourseMappings','SourceRows'] as $table)if(!hash_equals($c['table_hashes'][$table]??'',CompletionData::hash($handoff[$table]??[])))
            throw new \InvalidArgumentException('Catalog handoff table changed: '.$table);
        $source=array_column($handoff['Curriculum'],null,'record_id');$programs=[];
        foreach($c['programs'] as $p) {
            $key=$p['key'];
            if(!isset(self::UNITS[$key])||$p['college_code']!==self::UNITS[$key]||isset($programs[$key]))throw new \InvalidArgumentException('Catalog follow-up scope is not approved.');
            $rows=[];$seen=[];$blockers=$p['blockers']??[];
            foreach($p['courses'] as $row) {
                $s=$source[$row['record_id']]??null;
                if(!$s||$s['program_key']!==$key||!hash_equals($row['source_hash'],CompletionData::hash($s)))throw new \InvalidArgumentException('Curriculum source row changed.');
                if($s['row_kind']==='CHOICE_SLOT')throw new \InvalidArgumentException('A choice slot cannot become a course origin.');
                $code=CompletionData::text($s['source_code']);$credits=(int)$s['credits'];
                if($row['system_code']!==$code||$row['credit_hours']!==$credits) {
                    $a=$c['amendments'][$row['record_id']]??null;
                    if(!$a||($a['before_code']??null)!==$code||($a['before_credits']??null)!==$credits||($a['system_code']??null)!==$row['system_code']
                        ||($a['credit_hours']??null)!==$row['credit_hours']||empty($a['approval_reference']['decision_id'])||empty($a['approval_reference']['approved_at']))throw new \InvalidArgumentException('Academic change lacks its explicit scoped approval.');
                    $row['amendment']=$a;
                }
                if(preg_match('/^RUS\d+[Xx]$/',$row['system_code'])||isset($seen[$row['system_code']]))$blockers[]='course_identity_collision:'.$row['record_id'];
                $seen[$row['system_code']]=true;$row['source']=$s;$rows[]=$row;
            }
            $expected=array_column(array_filter($handoff['Curriculum'],fn($r)=>$r['program_key']===$key&&$r['row_kind']!=='CHOICE_SLOT'),'record_id');
            if(array_diff($expected,array_column($rows,'record_id'))||array_diff(array_column($rows,'record_id'),$expected))throw new \InvalidArgumentException('Incomplete curriculum intent.');
            foreach($p['elective_alternatives']??[] as $option) {
                $e=collect($handoff['SourceRows'])->firstWhere('record_id',$option['record_id']);
                $a=$option['approval_reference']??[];
                if(!$e||$key!=='environment'||!hash_equals($option['source_hash'],CompletionData::hash($e))||empty($a['decision_id'])||empty($a['approved_at'])
                    ||!str_contains($e['values_json'],$option['course_name'])||$option['credit_hours']!==2||!preg_match('/^ESI-UE-0[1-4]$/',$option['system_code']))
                    throw new \InvalidArgumentException('Elective alternative lacks its exact evidence and explicit approval.');
                if(isset($seen[$option['system_code']]))throw new \InvalidArgumentException('Duplicate elective alternative.');
                $seen[$option['system_code']]=true;$option['source']=$e;$rows[]=$option;
            }
            if($key==='environment'&&count($p['elective_alternatives']??[])!==4)$blockers[]='environment_elective_alternatives_unapproved';
            $groups=$p['requirements']['groups']??[];$pairs=[];
            foreach($groups as $g) {
                $pair=$g['requirement_scope'].':'.$g['requirement_type'];
                if(isset($pairs[$pair])||!in_array($g['requirement_scope'],['university','college','department'],true)||!in_array($g['requirement_type'],['mandatory','elective'],true))throw new \InvalidArgumentException('Invalid requirement group identity.');
                $pairs[$pair]=true;if(!is_int($g['required_credit_hours'])||$g['required_credit_hours']<0)$blockers[]='approved_requirement_budget_missing:'.$pair;
            }
            if(count($pairs)!==6)$blockers[]='six_explicit_requirement_budgets_required';
            if(!is_int($p['total_credit_hours'])||$p['total_credit_hours']<1)$blockers[]='approved_program_total_missing';
            if(count($pairs)===6&&array_sum(array_column($groups,'required_credit_hours'))!==$p['total_credit_hours'])$blockers[]='requirement_budgets_total_mismatch';
            foreach($groups as $g)if(is_int($g['required_credit_hours'])) {
                $hours=array_sum(array_column(array_filter($rows,fn($r)=>($r['requirement_scope']??null)===$g['requirement_scope']&&($r['course_type']??null)===$g['requirement_type']),'credit_hours'));
                if($g['requirement_type']==='mandatory'?$hours!==$g['required_credit_hours']:$hours<$g['required_credit_hours'])$blockers[]='curriculum_budget_mismatch:'.$g['requirement_scope'].':'.$g['requirement_type'];
            }
            $p['courses']=$rows;$p['blockers']=array_values(array_unique($blockers));$programs[$key]=$p;
        }
        if(count($programs)!==4)throw new \InvalidArgumentException('All four approved program contexts must be reported.');
        $profile=['contract'=>'approved-catalog.followup.v1','source_hashes'=>$bundle['sources'],'programs'=>array_values($programs),
            'approvals'=>$c['approvals']??[],'historical_approval_claimed'=>false];
        $profile['hash']=CompletionData::hash($profile);return $profile;
    }
}
