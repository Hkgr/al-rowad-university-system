<?php

namespace App\Services\StudentCompletion;

/** Explicit, pinned follow-up decisions. Workbook prose never becomes executable instructions. */
final class CompletionFollowup
{
    public function compile(array $bundle,array $handoff): array
    {
        $config=$bundle['config']['followup'];$bundle['approved_identities']??=[];
        if(($config['policy']??null)!=='approved_missing_students_2026_10')throw new \InvalidArgumentException('Unknown completion follow-up policy.');
        foreach(['Decisions','ExamOwners'] as $table)if(!hash_equals($config['table_hashes'][$table]??'',CompletionData::hash($handoff[$table])))
            throw new \InvalidArgumentException('Reviewed handoff table changed: '.$table);
        $decisions=[];$approvals=[];
        foreach($handoff['Decisions'] as $d) {
            if(isset($decisions[$d['decision_id']]))throw new \InvalidArgumentException('Duplicate follow-up decision identity.');
            $decisions[$d['decision_id']]=$d;
        }
        $reference=fn($d)=>['file'=>'handoff','sheet'=>'Decisions','row'=>$d['_row'],'decision_id'=>$d['decision_id'],'sha256'=>$bundle['sources']['handoff']['sha256']];
        foreach($config['decision_ids'] as $id) {
            $d=$decisions[$id]??null;
            if(!$d||$d['authority']!=='USER_APPROVED')throw new \InvalidArgumentException('Unapproved follow-up decision: '.$id);
            $value=$d['approved_value'];$ref=$reference($d);$number=$d['canonical_number'];
            switch($d['field']) {
                case 'status_default':
                    if($value!=='active'||$number!==null)throw new \InvalidArgumentException('Unsupported missing-student default.');
                    $approvals['missing_status']=['value'=>'active','reference'=>$ref];break;
                case 'enrollment_date_fallback':
                    if($value!=='SEPTEMBER_1_OF_EARLIEST_EVIDENCED_EXAM_YEAR'||$number!==null)throw new \InvalidArgumentException('Unsupported enrollment fallback.');
                    $approvals['missing_date']=['value'=>$value,'reference'=>$ref];break;
                case 'enrollment_date':
                    if(!$number||CompletionData::date($value)!==$value)throw new \InvalidArgumentException('Invalid approved completion date.');
                    $approvals['dates'][$number]=['value'=>$value,'reference'=>$ref,'kind'=>'administratively_approved_estimate'];break;
                case 'identity':
                    $v=json_decode($value,true,flags:JSON_THROW_ON_ERROR);
                    foreach(['first_name','last_name','father_name'] as $key)if(!is_string($v[$key]??null)||CompletionData::text($v[$key])==='')throw new \InvalidArgumentException('Explicit name components required.');
                    $this->identity($bundle,$number,$v,$ref);break;
                case 'academic_program':
                    $v=json_decode($value,true,flags:JSON_THROW_ON_ERROR);
                    if(!$number||empty($v['college'])||empty($v['program']))throw new \InvalidArgumentException('Qualified approved program required.');
                    $this->ensure($bundle,$number,$ref);
                    $bundle['approved_identities'][$number]['fields']['program']=['value'=>$v['program'],'authority'=>'approved_followup',
                        'decision_reference'=>$ref,'contexts'=>[['college'=>$v['college'],'source_program'=>null,'reference'=>$ref]],
                        'basis'=>'examination_inference_then_explicit_administrative_approval'];break;
                case 'student_status':
                    if(!$number||$value!=='frozen')throw new \InvalidArgumentException('Unsupported named status decision.');
                    $this->ensure($bundle,$number,$ref);$bundle['approved_identities'][$number]['fields']['status']=['value'=>'frozen','authority'=>'approved_followup','decision_reference'=>$ref];break;
                case 'accepted_label':case 'source_owner':break; // Exact observations below, never a number-wide alias.
                default:throw new \InvalidArgumentException('Unsupported executable follow-up field: '.$d['field']);
            }
        }
        $all=[];foreach(['observations','unassigned_source_records'] as $kind)foreach($bundle[$kind]??[] as $row)if(isset($row['kind'])) {
            $key=$this->recordId($row['reference']);if(isset($all[$key]))throw new \InvalidArgumentException('Duplicate examination reference.');$all[$key]=$row;
        }
        $used=[];$mappings=[];
        foreach($handoff['ExamOwners'] as $owner) {
            if(!in_array($owner['record_id'],$config['owner_record_ids'],true))continue;
            $d=$decisions[$owner['decision_id']]??null;
            if(!$d||!in_array($d['decision_id'],$config['decision_ids'],true)||$d['canonical_number']!==$owner['canonical_number']||$d['authority']!=='USER_APPROVED'||$owner['approval']!=='APPROVED')
                throw new \InvalidArgumentException('Source owner is not explicitly approved.');
            $key=$owner['record_id'];$row=$all[$key]??null;
            if(!$row||$this->recordId(['file'=>$owner['source_id'],'sheet'=>$owner['source_sheet'],'row'=>$owner['source_row']])!==$key
                ||$row['number']!==$owner['original_number']||CompletionData::text($row['name'])!==CompletionData::text($owner['original_name'])
                ||CompletionData::text($row['course_code'])!==CompletionData::text($owner['original_course_code'])
                ||CompletionData::text($row['course_name'])!==CompletionData::text($owner['original_course_name']))throw new \InvalidArgumentException('Approved source observation changed: '.$key);
            if(isset($used[$key]))throw new \InvalidArgumentException('Repeated source-owner approval.');$used[$key]=true;
            $ref=$reference($d);$row['ownership_history'][]=['previous_ownership'=>$row['ownership']??null,'previous_blocker'=>$row['blocker']??null,'previous_decision'=>$row['decision_reference']??null];
            unset($row['blocker'],$row['reason'],$row['decision_reference']);
            $row['ownership']=['number'=>$owner['canonical_number'],'original_number'=>$owner['original_number'],'decision_reference'=>$ref];
            $row['approved_owner_mapping']=$owner+['decision_reference'=>$ref];$all[$key]=$row;
            foreach($bundle['approved_identities'] as &$identity)$identity['accepted_records']=array_values(array_filter($identity['accepted_records'],fn($r)=>$this->recordId($r)!==$key));unset($identity);
            $this->ensure($bundle,$owner['canonical_number'],$ref);$bundle['approved_identities'][$owner['canonical_number']]['accepted_records'][]=$row['reference'];
            $mappings[]=['record_id'=>$key,'canonical_number'=>$owner['canonical_number'],'original_number'=>$owner['original_number'],'reference'=>$row['reference'],'approval'=>$ref];
        }
        if(count($used)!==count(array_unique($config['owner_record_ids'])))throw new \InvalidArgumentException('An approved owner reference is missing.');
        $bundle['observations']=array_values(array_filter($all,fn($r)=>!isset($r['blocker'])));
        $bundle['unassigned_source_records']=array_values(array_filter($bundle['unassigned_source_records']??[],fn($r)=>!isset($used[$this->recordId($r['reference'])])));
        $bundle['contract']='student-completion.sources.v3';$bundle['completion_approvals']=$approvals;
        $bundle['followup_controls']=['source_owner_mappings'=>$mappings,'owner_observations'=>count($mappings),'decision_count'=>count($config['decision_ids'])];
        return $bundle;
    }
    private function ensure(array &$bundle,string $number,array $ref): void
    {
        if(isset($bundle['approved_identities'][$number]))return;
        $bundle['approved_identities'][$number]=['fields'=>[],'display_name'=>'','reference'=>$ref,'accepted_records'=>[],
            'ownership_resolution'=>'explicit_approved_followup','ownership_checks'=>[],'unresolved_fields'=>[],'blockers'=>[]];
        $roster=array_values(array_filter($bundle['identities'],fn($r)=>$r['reference']['file']==='roster'&&($r['ownership']['number']??$r['number'])===$number));
        if(count($roster)===1) {
            $r=$roster[0];$d=&$bundle['approved_identities'][$number];$d['accepted_records'][]=$r['reference'];$d['display_name']=$r['full_name'];
            foreach(['first_name','last_name','father_name','program','enrollment_date'] as $field)if(($r[$field]??null)!==null&&$r[$field]!=='') {
                $d['fields'][$field]=['value'=>$r[$field],'authority'=>'C','roster_reference'=>$r['reference'],'decision_reference'=>$ref];
                if($field==='program')$d['fields'][$field]['contexts']=[['college'=>$r['college'],'source_program'=>$r['program'],'reference'=>$r['reference']]];
            }
        } elseif(count($roster)>1)$bundle['approved_identities'][$number]['blockers'][]='approved_followup_roster_ambiguous';
    }
    private function identity(array &$bundle,string $number,array $value,array $ref): void
    {
        $this->ensure($bundle,$number,$ref);$decision=&$bundle['approved_identities'][$number];
        foreach(['first_name','last_name','father_name'] as $key)$decision['fields'][$key]=['value'=>$value[$key],'authority'=>'approved_followup','decision_reference'=>$ref];
        $decision['display_name']=$value['first_name'].' '.$value['last_name'];
        foreach([...$bundle['identities'],...$bundle['observations']] as $row)if(($row['ownership']['number']??$row['number'])===$number&&in_array(CompletionData::text($row['full_name']??$row['name']??''),$value['accepted_labels'],true)) {
            $decision['accepted_records'][]=$row['reference'];
            if($row['reference']['file']==='roster'&&!isset($decision['fields']['program']))$decision['fields']['program']=['value'=>$row['program'],'authority'=>'C',
                'roster_reference'=>$row['reference'],'decision_reference'=>$ref,'contexts'=>[['college'=>$row['college'],'source_program'=>$row['program'],'reference'=>$row['reference']]]];
        }
    }
    private function recordId(array $ref): string {return $ref['file'].':'.$ref['sheet'].':'.$ref['row'];}
}
