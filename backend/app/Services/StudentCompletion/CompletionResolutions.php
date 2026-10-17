<?php

namespace App\Services\StudentCompletion;

/** Field-specific, source-bound administrative interpretations. Never executes workbook prose. */
final class CompletionResolutions
{
    public function compile(array $bundle): array
    {
        if(!isset($bundle['config']['resolution_policy']))return $bundle;
        if($bundle['config']['resolution_policy']!=='administration_e_then_roster_c')throw new \InvalidArgumentException('Unsupported administrative resolution policy.');
        $reviews=[];foreach($bundle['conflicts'] as $row)$reviews[$row['reference']['row']]=$row;
        $approved=[];$owners=[];$used=[];$covered=[];
        foreach($bundle['config']['resolutions']??[] as $decision) {
            $review=$reviews[$decision['review_row']]??null;
            if(isset($covered[$decision['review_row']]))throw new \InvalidArgumentException('Duplicate review-row interpretation.');
            $covered[$decision['review_row']]=true;
            if(!$review || ($review['cells']??[])!==$decision['expected_cells'])throw new \InvalidArgumentException('Administrative decision source cells changed.');
            $reference=$review['reference']+['sha256'=>$bundle['sources']['conflicts']['sha256']];
            foreach($decision['targets'] as $target) {
                $number=$target['number'];
                if(isset($approved[$number]))throw new \InvalidArgumentException('Contradictory approved ownership of a student number.');
                if($number!==$review['number']&&!str_contains((string)$review['solution'],$number))throw new \InvalidArgumentException('Target number not identified by the decision.');
                $fields=[];$blockers=$target['blockers']??[];
                $ambiguous=CompletionData::text($review['solution'])===''&&preg_match_all('/\bR[0-9]{8}\b/u',(string)$review['cells']['C'],$ids)>1&&count(array_unique($ids[0]))>1;
                if($ambiguous)$blockers[]='student_affairs_identity_ambiguous';
                foreach($target['fields'] as $key=>$field) {
                    if(!in_array($key,['first_name','last_name','father_name','program','enrollment_date','status'],true))throw new \InvalidArgumentException('Unsupported administrative field.');
                    if($field['authority']==='C') {
                        if($ambiguous)continue;
                        $source=collect($bundle['identities'])->first(fn($r)=>CompletionData::hash($r['reference'])===CompletionData::hash($field['roster_reference'])&&$r['reference']['file']==='roster');
                        if(!$source||$source['number']!==($target['roster_number']??$number)||($source[$key]??null)!==$field['value']
                            ||CompletionData::text($review['cells']['C'])===''||str_contains((string)$review['cells']['C'],'غير وارد'))throw new \InvalidArgumentException('Student Affairs fallback is not backed by a structured roster field: row '.$decision['review_row'].' / '.$key);
                    } elseif($field['authority']==='E') {
                        if(CompletionData::text($review['solution'])==='')throw new \InvalidArgumentException('Explicit authority requires a nonempty solution.');
                        // Store the exact interpreted passage, not an untraceable global allowlist.
                        $passage=$field['passage']??'';
                        if($passage===''||!str_contains((string)$review['solution'],$passage))throw new \InvalidArgumentException('Decision passage missing from source.');
                        if($key==='status'&&($field['value']!=='frozen'||!str_contains($passage,'منقطع')))throw new \InvalidArgumentException('Unsupported administrative status decision.');
                        if($key!=='status'&&!str_contains(CompletionData::text($review['solution']),CompletionData::text($field['value']))) {
                            $support=collect([...$bundle['identities'],...$bundle['observations']])->first(fn($r)=>CompletionData::hash($r['reference'])===CompletionData::hash($field['support_reference']??null));
                            $name=CompletionData::text(($target['fields']['first_name']['value']??'').' '.($target['fields']['last_name']['value']??''));
                            $supported=$support&&str_contains((string)$review['solution'],'إضافة')&&
                                (($key==='program'&&$support['program']===$field['value'])||
                                 (in_array($key,['first_name','last_name'],true)&&$name===CompletionData::text($support['full_name']??$support['name']??'')
                                    &&(!isset($support['kind'])||$name===CompletionData::text($review['cells']['B']))));
                            if(!$supported)throw new \InvalidArgumentException('Explicit field value lacks supporting source evidence: row '.$decision['review_row'].' / '.$key);
                        }
                    } else throw new \InvalidArgumentException('Unsupported field authority.');
                    $fields[$key]=$field+['decision_reference'=>$reference+['cell'=>$field['authority'].$decision['review_row']]];
                }
                $approved[$number]=['fields'=>$fields,'display_name'=>$target['display_name'],'reference'=>$reference,
                    'ownership_resolution'=>$decision['ownership_resolution']??'approved_number_owner','accepted_records'=>[],
                    'ownership_checks'=>$decision['ownership_checks']??[],'unresolved_fields'=>$target['unresolved_fields']??[],'blockers'=>$blockers];
            }
            foreach($decision['records'] as $record) {
                $key=CompletionData::hash($record['reference']);
                if(isset($owners[$key]))throw new \InvalidArgumentException('One source record has multiple ownership decisions.');
                if($record['target']!==null&&!isset($approved[$record['target']]))throw new \InvalidArgumentException('Source record targets an unapproved identity.');
                $owners[$key]=$record+['decision_reference'=>$reference];
            }
        }
        if(array_diff(array_keys($reviews),array_keys($covered)))throw new \InvalidArgumentException('Every review row needs a field-specific interpretation or explicit blockers.');
        $unassigned=[];
        foreach(['identities','observations'] as $kind) {
            $rows=[];
            foreach($bundle[$kind] as $row) {
                $key=CompletionData::hash($row['reference']);$owner=$owners[$key]??null;
                if($owner) {
                    $name=CompletionData::text($row['full_name']??$row['name']??'');
                    if($row['number']!==$owner['number']||$name!==$owner['label']||($row['program']??null)!==($owner['program']??null)
                        ||($row['father_name']??null)!==($owner['father_name']??null)||($row['college']??null)!==($owner['college']??null))throw new \InvalidArgumentException('Owned source record context changed.');
                    $used[$key]=true;
                    if($owner['target']===null){$unassigned[]=$row+['blocker'=>'source_ownership_unproven','reason'=>$owner['reason']??'ambiguous_context','decision_reference'=>$owner['decision_reference']];continue;}
                    $row['ownership']=['number'=>$owner['target'],'original_number'=>$row['number'],'decision_reference'=>$owner['decision_reference']];
                    $approved[$owner['target']]['accepted_records'][]=$row['reference'];
                }
                $rows[]=$row;
            }
            $bundle[$kind]=$rows;
        }
        if(count($used)!==count($owners))throw new \InvalidArgumentException('A reviewed source reference no longer exists.');
        $bundle['approved_identities']=$approved;$bundle['unassigned_source_records']=$unassigned;
        $bundle['resolution_controls']=['rows'=>count($reviews),'explicit'=>count(array_filter($reviews,fn($r)=>CompletionData::text($r['solution'])!=='')),
            'fallback'=>count(array_filter($reviews,fn($r)=>CompletionData::text($r['solution'])===''))];
        return $bundle;
    }
}
