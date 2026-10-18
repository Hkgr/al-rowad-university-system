<?php
namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\CompletionData;

/** Academic groups are not individual activities. Mirrors canonical required-component RAW SUM, not weights. */
final class ExamComponentMapping
{
    public const PROFILE='historical-coursework40-final60.v1';
    public const CAPS=['coursework'=>4000,'exam'=>6000];

    /** Only for a wholly undefined, mutable historical context. Never replaces existing definitions. */
    public static function aggregateComponents(): array
    {
        return [
            ['grade_component_id'=>'new:practical','component_name'=>'إجمالي أعمال الفصل','component_type'=>'practical','max_mark'=>'40.00','is_required'=>true,'status'=>'active'],
            ['grade_component_id'=>'new:theoretical','component_name'=>'الامتحان النهائي','component_type'=>'theoretical','max_mark'=>'60.00','is_required'=>true,'status'=>'active'],
        ];
    }

    private function namedGroup(array $component): ?string
    {
        return match(CompletionData::text($component['component_name'])) {
            'إجمالي أعمال الفصل','أعمال السنة','أعمال الفصل','أعمال فصلية','coursework','Coursework'=>'coursework',
            'الامتحان النهائي','الامتحان النظري','Final examination'=>'exam',default=>null,
        };
    }

    private function activityGroup(array $component): ?string
    {
        return match(CompletionData::text($component['component_name'])) {
            'امتحان عملي','الامتحان العملي','Practical examination','اختبار قصير','اختبارات قصيرة','واجبات','واجب','Quiz','Midterm','Assignments'=>'coursework',default=>null,
        };
    }

    public function inspect(array $values,array $components,array $marks,?array $policy,?array $result,bool $initialize=false): array
    {
        $mapping=[];$blockers=[];$writes=[];$conflicts=[];$complete=true;$definitionsComplete=true;
        $totals=['theoretical'=>0,'practical'=>0];$limits=$totals;$required=['theoretical'=>false,'practical'=>false];$partCounts=$totals;
        foreach($components as $c)if((bool)$c['is_required']&&isset($limits[$c['component_type']])) {
            $limits[$c['component_type']]+=ExamProjection::cents($c['max_mark'])??0;$partCounts[$c['component_type']]++;
        }
        // Explicit approved compatibility mapping for a detailed canonical 40/60 part profile.
        // The technical name practical identifies the COURSEWORK GROUP, not an individual practical exam.
        $detailedPartProfile=$limits===['theoretical'=>6000,'practical'=>4000]&&max($partCounts)>1;
        $groups=[];
        foreach(self::CAPS as $field=>$cap)$groups[$field]=[
            'identity'=>$field==='coursework'?'coursework':'final_examination','cap_cents'=>$cap,
            'source_field'=>$field,'source_aggregate_cents'=>ExamProjection::cents($values[$field]??null),
            'source_value'=>$values[$field]??null,'known_subtotal_cents'=>0,'missing_component_ids'=>[],
            'components'=>[],'breakdown_complete'=>false,'representation'=>null,
        ];
        foreach($components as $component) {
            if(!(bool)$component['is_required'])continue; // Official finalization excludes optional components.
            $type=$component['component_type'];$max=ExamProjection::cents($component['max_mark']);$field=$this->namedGroup($component)??$this->activityGroup($component);
            $basis=$this->activityGroup($component)!==null?'explicit_reviewed_activity_group':'explicit_reviewed_group_label';
            if($field===null&&$detailedPartProfile&&isset($limits[$type])){$field=$type==='practical'?'coursework':'exam';$basis='approved_40_60_detailed_technical_part_group';}
            if(!$field||!array_key_exists($type,$totals)||$max===null||$max<=0) {
                $blockers[]='component_semantics_not_faithfully_representable';$complete=false;$definitionsComplete=false;continue;
            }
            $required[$type]=true;
            $existing=array_values(array_filter($marks,fn($m)=>(string)$m['grade_component_id']===(string)$component['grade_component_id']));
            if(count($existing)>1){$blockers[]='component_marks_ambiguous';$complete=false;}$existing=count($existing)===1?$existing[0]:null;
            $mark=$existing?ExamProjection::cents($existing['mark']):null;
            if($existing&&$existing['mark']!==null&&($mark===null||$mark>$max)){$blockers[]='existing_component_mark_outside_definition';$complete=false;}
            $item=['source_field'=>$field,'grade_component_id'=>$component['grade_component_id'],'component_name'=>$component['component_name'],
                'component_type'=>$type,'aggregate'=>$type.'_total','group_basis'=>$basis,'source_cents'=>null,'max_cents'=>$max,
                'weight_percentage'=>$component['weight_percentage']??null,'stored_mark'=>$existing,
                'academic_role'=>$this->namedGroup($component)!==null?'source_group_assessment':'assessed_activity_not_inferred_from_technical_part'];
            $groups[$field]['components'][]=$item;
            if($mark!==null)$groups[$field]['known_subtotal_cents']+=$mark;
            else $groups[$field]['missing_component_ids'][]=$component['grade_component_id'];
        }
        foreach($groups as $field=>&$group) {
            $source=$group['source_aggregate_cents'];$items=$group['components'];
            if($source===null||$source>self::CAPS[$field]){$blockers[]=$source===null?'source_mark_missing_symbolic_or_outside_component_limit':'source_group_mark_outside_approved_cap';$complete=false;}
            if(!$items){$blockers[]=$field==='coursework'?'coursework_has_no_explicit_component_mapping':'examination_not_representable';$complete=false;continue;}
            $aggregateAssessment=count($items)===1&&$items[0]['academic_role']==='source_group_assessment';
            $group['representation']=$aggregateAssessment?($field==='coursework'?'aggregate_coursework_internal_breakdown_unavailable':'final_examination_assessment'):'detailed_assessments';
            $group['breakdown_complete']=$group['missing_component_ids']===[];
            if(!$aggregateAssessment&&$group['missing_component_ids']) {
                if($source!==null)$blockers[]='aggregate_known_component_breakdown_missing';$complete=false;
                // No residual inference, redistribution, whole-J assignment to an activity, or synthetic zero.
            }
            foreach($group['components'] as &$item) {
                $existing=$item['stored_mark'];$mark=$existing?ExamProjection::cents($existing['mark']):null;
                $value=$aggregateAssessment?$source:$mark;
                if($aggregateAssessment) {
                    $item['source_cents']=$source;
                    if($source!==null&&$source>$item['max_cents']){$blockers[]='source_mark_missing_symbolic_or_outside_component_limit';$complete=false;}
                    elseif($source!==null&&$source<=self::CAPS[$field]) {
                        if($existing&&$existing['mark']!==null) {
                            if($mark!==$source)$conflicts[]=['group'=>$field,'grade_component_id'=>$item['grade_component_id'],'source_cents'=>$source,'existing_cents'=>$mark];
                        } else {
                            $writes[]=['key'=>(string)$item['grade_component_id'],'mark'=>sprintf('%d.%02d',intdiv($source,100),$source%100)];
                            $definition=collect($components)->firstWhere('grade_component_id',$item['grade_component_id']);
                            if(($definition['status']??'active')!=='active')$blockers[]='component_definition_not_editable';
                            if($existing&&!in_array($existing['grade_status'],[null,'draft','returned'],true))$blockers[]='existing_component_submission_or_approval_protected';
                        }
                    }
                }
                if($value!==null)$totals[$item['component_type']]+=$value;
                $mapping[]=$item;
            }
            unset($item);
            if(!$aggregateAssessment&&$group['breakdown_complete']&&$source!==null&&$source!==$group['known_subtotal_cents'])
                $conflicts[]=['group'=>$field,'source_aggregate_cents'=>$source,'existing_group_cents'=>$group['known_subtotal_cents'],'components'=>$group['components']];
        }
        unset($group);
        if(!$mapping){$blockers[]='component_definitions_require_canonical_preparation_preview';$complete=false;$definitionsComplete=false;}
        $compatible=$policy!==null&&$definitionsComplete;
        if(!$policy)$blockers[]='grading_policy_missing_or_ambiguous';
        elseif($definitionsComplete) {
            foreach($required as $part=>$needed)if($needed&&$limits[$part]!==ExamProjection::cents($policy[$part.'_max_mark']))$compatible=false;
            if(ExamProjection::cents($policy['minimum_final_mark'])>array_sum($limits))$compatible=false;
            if(!$compatible)$blockers[]='canonical_grading_policy_incompatible';
        }
        $outcome=null;
        if($complete) {
            // Legacy coursework_total is retained metadata, never an additional third summand.
            if($result)foreach($totals as $type=>$sum)if(ExamProjection::cents($result[$type.'_total']??null)!==$sum)
                $conflicts[]=['aggregate'=>$type.'_total','expected_cents'=>$sum,'existing_cents'=>ExamProjection::cents($result[$type.'_total']??null)];
            $total=ExamProjection::cents($values['total']??null);
            if($total!==null&&array_sum($totals)!==$total)$blockers[]='source_total_not_canonical_component_sum';
            if($compatible) {
                $failed=array_sum($totals)<ExamProjection::cents($policy['minimum_final_mark']);
                foreach($required as $type=>$needed)if($needed&&$totals[$type]<ExamProjection::cents($policy['minimum_'.$type.'_mark']))$failed=true;
                $outcome=$failed?'failed':'passed';$condition=CompletionData::text($values['condition']??null);
                // The source macro writes T when the selected examination reaches 15,
                // not when the course passes. Canonical minima still determine the outcome.
                // F is separate source evidence of failure and must not become a pass.
                if($condition==='F'&&!$failed)$blockers[]='source_outcome_not_representable_by_current_policy';
            }
        }
        return ['profile'=>self::PROFILE,'groups'=>$groups,'mapping'=>$mapping,'proposed_components'=>$writes,
            'blockers'=>array_values(array_unique($blockers)),'conflict'=>$conflicts!==[],'conflicts'=>$conflicts,'complete'=>$complete,
            'expected_aggregates'=>$complete?$totals:null,'canonical_outcome'=>$outcome,'initialize_aggregate_profile'=>$initialize,
            'coursework_representation'=>$groups['coursework']['representation'],'aggregation_method'=>'required_component_raw_sum_weights_not_applied',
            'aggregate_basis'=>'required_component_type; coursework_total_retained_not_compared'];
    }
}
