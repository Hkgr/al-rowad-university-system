<?php

namespace App\Services\StudentCompletion;

/** Regenerate private, field-bound E/C interpretations from the two supplied inputs. */
final class ConsolidatedCompletionDecisions
{
    private function norm(string $s): string {return strtr(CompletionData::text($s),['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي']);}
    /** Only within a numbered, explicitly approved mapping; never a university-wide name match. */
    private function mappingLabel(string $s): string {return preg_replace('/(^| )ال/u','$1',$this->norm($s));}
    public function configure(array $bundle,array $tables): array
    {
        $config=$bundle['config'];$all=[...$bundle['identities'],...$bundle['observations']];$roster=array_values(array_filter($bundle['identities'],fn($r)=>$r['reference']['file']==='roster'));
        $config['resolution_policy']='administration_e_then_roster_c';$config['resolutions']=[];$config['identity_decisions']=[];$approved=[];
        foreach($tables['Decisions'] as $d)if($d['field']==='approved_identity_mapping') {
            $p=json_decode($d['approved_value'],true,flags:JSON_THROW_ON_ERROR);
            if(($p['approved_by_user']??false)!==true||$d['authority']!=='USER_APPROVED')throw new \InvalidArgumentException('Unapproved prior identity interpretation.');
            if(isset($p['father']))$p['father_name']=$p['father'];
            if(isset($p['mappings']))foreach($p['mappings'] as $m)$approved[]=['number'=>$m['canonical_number'],'source'=>$m['source_number'],'labels'=>[$m['name_in_grades']],'data'=>$p];
            elseif(isset($p['canonical_number'])) {
                $labels=[$p['name']];foreach($bundle['conflicts'] as $review)if($review['number']===$p['canonical_number'])foreach(['B','C'] as $col)$labels[]=explode("\n",(string)$review['cells'][$col])[0];
                $approved[]=['number'=>$p['canonical_number'],'source'=>$p['other_source_number']??$p['canonical_number'],'labels'=>$labels,'data'=>$p];
            }
        }
        foreach($approved as &$a) {
            foreach($roster as $r)if($r['number']===$a['number']){$a['labels'][]=$r['full_name'];$a['labels'][]=CompletionData::text($r['first_name'].' '.$r['father_name'].' '.$r['last_name']);}
            if(isset($a['data']['father_name']))foreach($a['labels'] as $label) {
                $parts=explode(' ',CompletionData::text($label));if(count($parts)===2)$a['labels'][]=$parts[0].' '.$a['data']['father_name'].' '.$parts[1];
            }
            $labels=array_map($this->mappingLabel(...),$a['labels']);$names=[];
            foreach($all as $r)if($r['number']===$a['source']&&in_array($this->mappingLabel($r['full_name']??$r['name']),$labels,true))$names[]=CompletionData::text($r['full_name']??$r['name']);
            if($names&&$a['source']!==$a['number'])$config['identity_decisions'][]=['source_number'=>$a['source'],'canonical_number'=>$a['number'],'names'=>array_values(array_unique($names))]
                +(isset($a['data']['father_name'])?['father_name'=>$a['data']['father_name']]:[]);
            $a['labels']=[...$a['labels'],...$names];
        }unset($a);
        $canonical=function($r)use($config){foreach($config['identity_decisions'] as $d)if($r['number']===$d['source_number']&&in_array(CompletionData::text($r['full_name']??$r['name']),$d['names'],true)
            &&(!isset($d['father_name'])||empty($r['father_name'])||$r['father_name']===$d['father_name']))return $d['canonical_number'];return $r['number'];};
        $cfield=fn($value,$ref)=>['value'=>$value,'authority'=>'C','roster_reference'=>$ref];
        $efield=fn($value,$solution)=>['value'=>$value,'authority'=>'E','passage'=>$solution];
        $programLabels=array_keys($config['program_aliases']);usort($programLabels,fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        foreach($bundle['conflicts'] as $review) {
            $number=$review['number'];$solution=CompletionData::text($review['solution']);$targets=[$number];
            // Separate numbered, parenthesized people remain separate; never split a triplet.
            if(preg_match_all('/\(([^()]*R[0-9]{8}[^()]*)\)/u',$solution,$clauses)&&count($clauses[0])>1)foreach($clauses[0] as $clause)if(preg_match('/R[0-9]{8}/',$clause,$m))$targets[]=$m[0];
            $targets=array_values(array_unique($targets));$decision=['review_row'=>$review['reference']['row'],'expected_cells'=>$review['cells'],'targets'=>[],'records'=>[],
                'ownership_resolution'=>$solution!==''?'explicit_E':'administration_approved_C_fallback'];
            foreach($targets as $target) {
                $candidate=array_values(array_filter($roster,fn($r)=>$r['number']===$target));$fields=[];$unresolved=[];
                if(count($candidate)===1&&!str_contains((string)$review['cells']['C'],'غير وارد'))foreach(['first_name','last_name','father_name','program','enrollment_date'] as $field)
                    if(($candidate[0][$field]??null)!==null&&$candidate[0][$field]!=='')$fields[$field]=$cfield($candidate[0][$field],$candidate[0]['reference']);
                $support=array_values(array_filter($all,fn($r)=>$canonical($r)===$target&&$r['reference']['file']!=='roster'));
                if($solution!=='') {
                    foreach(['first_name'=>'الاسم الأول','last_name'=>'الكنية','father_name'=>'اسم الأب'] as $field=>$label)
                        if(preg_match('/(?:^|[-–]\s*)'.preg_quote($label,'/').'\s*[:：]?\s*(.+?)(?=\s*[-–]|$)/u',$solution,$m))$fields[$field]=$efield(trim($m[1]),$solution);
                    $nameText=null;
                    if(preg_match('/الاسم الثلاثي\s*[:：(]?\s*([^)-]+)/u',$solution,$m))$nameText=trim($m[1]);
                    elseif(preg_match('/الاسم والكنية\s*[:：]?\s*([^–-]+)/u',$solution,$m))$nameText=trim($m[1]);
                    elseif(!preg_match('/إضافة|المعتمد|قسم|علامات|الرقم|كلية/u',$solution)&&!isset($config['program_aliases'][$solution]))$nameText=$solution;
                    // Structured roster components are boundaries, not arbitrary word positions.
                    if($nameText&&isset($fields['first_name'])&&!preg_match('/الاسم الأول|الكنية/u',$solution)) {
                        $first=$fields['first_name']['value'];$father=$fields['father_name']['value']??'';
                        if(str_starts_with($nameText,$first.' ')) {
                            $rest=trim(mb_substr($nameText,mb_strlen($first)));
                            if($father!==''&&str_starts_with($rest,$father.' '))$rest=trim(mb_substr($rest,mb_strlen($father)));
                            if($rest!==''&&!str_contains($rest,' - ')&&$rest!==($fields['last_name']['value']??null))$fields['last_name']=$efield($rest,$solution);
                        } elseif($this->norm($first)!==$this->norm(explode(' ',$nameText)[0])&&preg_match('/الاسم الثلاثي/u',$solution)) {
                            unset($fields['first_name']);$unresolved['first_name']='Unlabelled spelling conflicts with structured compound boundaries; retain for review.';
                        }
                    }
                    if($nameText&&isset($fields['father_name'])) {
                        $father=$fields['father_name']['value'];
                        foreach(explode(' ',$nameText) as $word)if($this->norm($word)===$this->norm($father)&&$word!==$father)$fields['father_name']=$efield($word,$solution);
                    }
                    $explicitProgram=null;foreach($programLabels as $label)if(!str_contains($label,'|')&&mb_strlen($label)>3&&str_contains($solution,$label)){$explicitProgram=$label;break;}
                    $prior=collect($approved)->firstWhere('number',$target);
                    if($prior&&isset($prior['data']['canonical_program']))$explicitProgram=$prior['data']['canonical_program'];
                    if($explicitProgram)$fields['program']=$efield($explicitProgram,$solution);
                    if(str_contains($solution,'إضافة')) {
                        if(!isset($fields['first_name'],$fields['last_name'])) {
                            $label=$prior['data']['name']??CompletionData::text(explode("\n",(string)$review['cells']['B'])[0]);
                            $match=collect($support)->first(fn($r)=>CompletionData::text($r['full_name']??$r['name'])===$label);
                            $parts=explode(' ',$label);
                            // The already-approved compound boundary in review row 22 is
                            // preserved, not generalized to arbitrary unlabelled triplets.
                            $compound=$review['reference']['row']===22&&count($parts)===3&&$parts[0]==='عبد'&&($match['reference']['sheet']??null)==='Data';
                            if($match&&(count($parts)===2||$compound))foreach(['first_name'=>$compound?$parts[0].' '.$parts[1]:$parts[0],'last_name'=>$parts[$compound?2:1]] as $f=>$value)$fields[$f]=$efield($value,$solution)+['support_reference'=>$match['reference']];
                        }
                        if(!isset($fields['program'])) {
                            $programs=array_values(array_unique(array_filter(array_column($support,'program'))));
                            if(count($programs)===1)$fields['program']=$efield($programs[0],$solution);
                        }
                    }
                    if(str_contains($solution,'منقطع'))$fields['status']=$efield('frozen',$solution);
                }
                // Where E is an explicit addition, bind its values to actual source evidence.
                foreach($fields as $key=>&$field)if($field['authority']==='E') {
                    if($key!=='status'&&!str_contains($solution,(string)$field['value'])) {
                        $full=CompletionData::text(($fields['first_name']['value']??'').' '.($fields['last_name']['value']??''));
                        $match=collect($support)->first(fn($r)=>$key==='program'?($r['program']===$field['value']):CompletionData::text($r['full_name']??$r['name'])===$full);
                        if($match)$field['support_reference']=$match['reference'];
                    }
                    if($key==='program') {
                        $contexts=[...$candidate,...$support];$matching=array_values(array_filter($contexts,fn($r)=>$r['college']!==''&&($r['program']===''||($config['program_aliases'][$r['program']]??$r['program'])===($config['program_aliases'][$field['value']]??$field['value']))));
                        if($matching)$field['context_reference']=$matching[0]['reference'];
                    }
                }unset($field);
                $decision['targets'][]=['number'=>$target,'display_name'=>CompletionData::text(($fields['first_name']['value']??'').' '.($fields['last_name']['value']??'')),
                    'fields'=>$fields,'blockers'=>[],'unresolved_fields'=>$unresolved];
            }
            $allowed=[];foreach(['B','C'] as $col)foreach(explode("\n",(string)$review['cells'][$col]) as $label)if(!preg_match('/الرقم|القسم|R[0-9]{8}|غير وارد/u',$label))$allowed[]=CompletionData::text($label);
            foreach($decision['targets'] as $t) {
                $allowed[]=$t['display_name'];foreach($roster as $r)if($canonical($r)===$t['number']){$allowed[]=$r['full_name'];$allowed[]=CompletionData::text($r['first_name'].' '.$r['father_name'].' '.$r['last_name']);}
                foreach($approved as $a)if($a['number']===$t['number'])$allowed=[...$allowed,...$a['labels']];
            }
            foreach($all as $r)if(in_array($canonical($r),$targets,true)) {
                $owner=$canonical($r);$label=CompletionData::text($r['full_name']??$r['name']);
                if(count($targets)>1) {
                    $matches=[];foreach($targets as $target)foreach($roster as $candidate)if($candidate['number']===$target&&
                        (!empty($r['father_name'])?$r['father_name']===$candidate['father_name']:
                        ($r['program']!==''?$r['program']===$candidate['program']:
                        ($r['college']!==''&&($config['college_aliases'][$r['college']]??$r['college'])===($config['college_aliases'][$candidate['college']]??$candidate['college'])))))$matches[$target]=true;
                    $owner=count($matches)===1?array_key_first($matches):null;
                }
                $competing=false;foreach($bundle['conflicts'] as $other)if(!in_array($other['number'],$targets,true)&&CompletionData::text(explode("\n",(string)$other['cells']['C'])[0])===$label&&empty($r['father_name']))$competing=true;
                if(!in_array($label,$allowed,true)||$competing)$owner=null;
                $decision['records'][]=['reference'=>$r['reference'],'number'=>$r['number'],'label'=>$label,'program'=>$r['program'],'college'=>$r['college'],
                    'father_name'=>$r['father_name']??null,'target'=>$owner,'reason'=>$competing?'competing_reviewed_number_owner':'unlisted_label'];
            }
            $config['resolutions'][]=$decision;
        }
        return $config;
    }
}
