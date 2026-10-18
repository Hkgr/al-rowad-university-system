<?php
namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\CompletionData;

/** Read-only counterpart of GradePartWorkflowService::finalize: required component TYPE owns each aggregate. */
final class ExamComponentMapping
{
    public function inspect(array $values,array $components,array $marks,?array $policy,?array $result): array
    {
        $mapping=[];$blockers=[];$writes=[];$fields=[];$conflict=false;$complete=true;$definitionsComplete=true;
        $totals=['theoretical'=>0,'practical'=>0];$limits=$totals;$required=['theoretical'=>false,'practical'=>false];
        foreach($components as $component) {
            if(!(bool)$component['is_required'])continue; // Canonical finalization sums required components only.
            $field=match(CompletionData::text($component['component_name'])) {
                'أعمال السنة','أعمال الفصل','أعمال فصلية','coursework','Coursework'=>'coursework',
                'الامتحان النهائي','الامتحان النظري','Final examination'=>'exam',default=>null,
            };
            $type=$component['component_type'];$max=ExamProjection::cents($component['max_mark']);
            if(!$field||isset($fields[$field])||!array_key_exists($type,$totals)||$max===null) {
                $blockers[]='component_semantics_not_faithfully_representable';$complete=false;$definitionsComplete=false;continue;
            }
            $fields[$field]=true;$required[$type]=true;$limits[$type]+=$max;
            $value=ExamProjection::cents($values[$field]??null);
            $existing=array_values(array_filter($marks,fn($m)=>(string)$m['grade_component_id']===(string)$component['grade_component_id']));
            if(count($existing)>1){$blockers[]='component_marks_ambiguous';$complete=false;continue;}$existing=$existing[0]??null;
            $mapping[]=['source_field'=>$field,'grade_component_id'=>$component['grade_component_id'],'component_name'=>$component['component_name'],'component_type'=>$type,'aggregate'=>$type.'_total','source_cents'=>$value,'max_cents'=>$max,'stored_mark'=>$existing];
            if($value===null||$value>$max){$blockers[]='source_mark_missing_symbolic_or_outside_component_limit';$complete=false;continue;}
            $totals[$type]+=$value;
            if($existing&&$existing['mark']!==null) {
                if(ExamProjection::cents($existing['mark'])!==$value)$conflict=true;
            } else {
                $writes[]=['key'=>(string)$component['grade_component_id'],'mark'=>sprintf('%d.%02d',intdiv($value,100),$value%100)];
                if(($component['status']??'active')!=='active')$blockers[]='component_definition_not_editable';
                if($existing&&!in_array($existing['grade_status'],[null,'draft','returned'],true))$blockers[]='existing_component_submission_or_approval_protected';
            }
        }
        if(!$mapping){$blockers[]='component_definitions_require_canonical_preparation_preview';$complete=false;$definitionsComplete=false;}
        if(ExamProjection::cents($values['coursework']??null)!==null&&!isset($fields['coursework'])){$blockers[]='coursework_has_no_explicit_component_mapping';$complete=false;}
        if(ExamProjection::cents($values['exam']??null)===null||!isset($fields['exam'])){$blockers[]='examination_not_representable';$complete=false;}
        $compatible=$policy!==null&&$definitionsComplete;
        if(!$policy)$blockers[]='grading_policy_missing_or_ambiguous';
        elseif($definitionsComplete) {
            foreach($required as $part=>$needed)if($needed&&$limits[$part]!==ExamProjection::cents($policy[$part.'_max_mark']))$compatible=false;
            if(ExamProjection::cents($policy['minimum_final_mark'])>array_sum($limits))$compatible=false;
            if(!$compatible)$blockers[]='canonical_grading_policy_incompatible';
        }
        $outcome=null;
        if($complete) {
            // coursework_total is legacy retained metadata, NOT a third component type or an additional summand.
            // Canonical finalization retains it as-is (or initializes zero); it does not map source J into it.
            if($result)foreach($totals as $type=>$sum)if(ExamProjection::cents($result[$type.'_total']??null)!==$sum)$conflict=true;
            $total=ExamProjection::cents($values['total']??null);
            if($total!==null&&array_sum($totals)!==$total)$blockers[]='source_total_not_canonical_component_sum';
            if($compatible) {
                $failed=array_sum($totals)<ExamProjection::cents($policy['minimum_final_mark']);
                foreach($required as $type=>$needed)if($needed&&$totals[$type]<ExamProjection::cents($policy['minimum_'.$type.'_mark']))$failed=true;
                $outcome=$failed?'failed':'passed';$condition=CompletionData::text($values['condition']??null);
                if(($condition==='F'&&!$failed)||($condition==='T'&&$failed))$blockers[]='source_outcome_not_representable_by_current_policy';
            }
        }
        return ['mapping'=>$mapping,'proposed_components'=>$writes,'blockers'=>array_values(array_unique($blockers)),'conflict'=>$conflict,
            'complete'=>$complete,'expected_aggregates'=>$complete?$totals:null,'canonical_outcome'=>$outcome,'aggregate_basis'=>'required_component_type; coursework_total_retained_not_compared'];
    }
}
