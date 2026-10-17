<?php

namespace App\Services\StudentCompletion;

/** Qualified, read-only matching against the actual program -> department -> college relationships. */
final class CompletionProgramIdentity
{
    public function resolve(array $field,array $state,array $config): array
    {
        $contexts=$field['contexts']??[];$ids=[];$blockers=[];$label=CompletionData::text($field['value']);
        if(!$contexts)$blockers[]='approved_program_context_missing';
        foreach($contexts as $context) {
            $unit=CompletionData::text($context['college']);
            if($unit===''){$blockers[]='approved_program_context_missing';continue;}
            $collegeCode=$config['college_aliases'][$unit]??null;
            $colleges=array_values(array_filter($state['colleges'],fn($c)=>CompletionData::text($c['college_name'])===$unit));
            if($colleges&&$collegeCode!==null&&count(array_filter($colleges,fn($c)=>($c['college_code']??null)===$collegeCode))!==count($colleges)) {
                $blockers[]='approved_program_context_conflict';continue;
            }
            if(!$colleges&&$collegeCode!==null)$colleges=array_values(array_filter($state['colleges'],fn($c)=>($c['college_code']??null)===$collegeCode));
            if(count($colleges)!==1){$blockers[]='approved_program_college_missing_or_ambiguous';continue;}
            $college=$colleges[0];$departments=array_column(array_filter($state['departments'],fn($d)=>(int)$d['college_id']===(int)$college['college_id']),null,'department_id');
            $programs=array_values(array_filter($state['academic_programs'],fn($p)=>isset($departments[$p['department_id']])));
            $aliases=$config['program_aliases']??[];
            $qualified=array_values(array_unique(array_filter([$aliases[$unit.'|'.$label]??null,$aliases[$college['college_name'].'|'.$label]??null])));
            if(count($qualified)>1){$blockers[]='approved_program_context_conflict';continue;}
            if($qualified) $matches=array_values(array_filter($programs,fn($p)=>$p['program_code']===$qualified[0]));
            else {
                $matches=array_values(array_filter($programs,fn($p)=>CompletionData::text($p['program_name'])===$label
                    ||CompletionData::text($departments[$p['department_id']]['department_name'])===$label));
                // A generic alias is only a candidate INSIDE the authoritative unit, never a replacement for it.
                if(!$matches&&isset($aliases[$label]))$matches=array_values(array_filter($programs,fn($p)=>$p['program_code']===$aliases[$label]));
            }
            if(count($matches)!==1){$blockers[]='approved_program_missing_or_ambiguous_in_college';continue;}
            $p=$matches[0];$ids[(int)$p['academic_program_id']]=['program_id'=>(int)$p['academic_program_id'],'program_code'=>$p['program_code'],
                'college_id'=>(int)$college['college_id'],'college_name'=>$college['college_name']];
        }
        if(count($ids)>1)$blockers[]='approved_program_context_conflict';
        $resolved=!$blockers&&count($ids)===1?array_values($ids)[0]:['program_id'=>null];
        return $resolved+['contexts'=>$contexts,'blockers'=>array_values(array_unique($blockers))];
    }
}
