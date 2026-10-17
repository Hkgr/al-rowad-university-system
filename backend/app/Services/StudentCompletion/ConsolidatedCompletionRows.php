<?php

namespace App\Services\StudentCompletion;

/** Typed layout adapters over verified embedded rows, not a parser of workbook instructions. */
final class ConsolidatedCompletionRows
{
    public function read(ConsolidatedHandoff $reader): array
    {
        $tables=[];foreach(array_diff(array_keys(ConsolidatedHandoff::HEADERS),['SourceRows']) as $t)$tables[$t]=$reader->table($t);
        $sources=[];foreach($tables['Sources'] as $s) {
            if(isset($sources[$s['source_id']])||!preg_match('/^[a-f0-9]{64}$/',$s['original_sha256']))throw new \InvalidArgumentException('Invalid/duplicate original provenance.');
            $sources[$s['source_id']]=$s;
        }
        foreach(['snapshot','roster','conflicts','exam1','exam2','environment'] as $id)if(!isset($sources[$id]))throw new \InvalidArgumentException('Missing required provenance source.');
        $controls=[];foreach($tables['ExtractionControl'] as $c) {
            $key=$c['source_id'].'|'.$c['source_sheet'];if(isset($controls[$key])||!isset($sources[$c['source_id']]))throw new \InvalidArgumentException('Duplicate/unknown extraction control.');
            $controls[$key]=['expected'=>$c,'hash'=>hash_init('sha256'),'count'=>0,'first'=>null,'last'=>null];
        }
        $out=['identities'=>[],'observations'=>[],'conflicts'=>[],'unidentified_source_rows'=>[],'catalog_evidence'=>[]];$ids=[];$uses=[];$rawReviews=[];
        foreach($reader->records('SourceRows') as $r) {
            $id=$r['record_id'];$source=$r['source_id'];$sheet=$r['source_sheet'];$n=$r['source_row'];$key=$source.'|'.$sheet;
            if(!is_int($n)||$n<1||$id!==$source.':'.$sheet.':'.$n||isset($ids[$id]))throw new \InvalidArgumentException('Invalid or repeated source record ID.');$ids[$id]=true;
            if(!isset($controls[$key])||!hash_equals($r['row_sha256'],ConsolidatedHandoff::sourceHash($id,$r['values_json'],$r['formulas_json'])))throw new \InvalidArgumentException('Source-row integrity differs: '.$id);
            $control=&$controls[$key];if($control['last']!==null&&$n<=$control['last'])throw new \InvalidArgumentException('Original source row order changed.');
            if($control['count'])hash_update($control['hash'],"\n");hash_update($control['hash'],$r['row_sha256']);$control['count']++;$control['first']??=$n;$control['last']=$n;unset($control);
            $v=json_decode($r['values_json'],true,flags:JSON_THROW_ON_ERROR);$f=json_decode($r['formulas_json'],true,flags:JSON_THROW_ON_ERROR);
            foreach($v as $col=>$value)if(!preg_match('/^[A-Z]{1,3}$/',$col)||is_array($value)||is_object($value))throw new \InvalidArgumentException('Invalid cached source cell.');
            foreach($f as $col=>$value)if(!preg_match('/^[A-Z]{1,3}$/',$col)||!is_string($value))throw new \InvalidArgumentException('Invalid formula evidence.');
            $ref=['file'=>$source,'sheet'=>$sheet,'row'=>$n];$raw=['cells'=>$v,'formulas'=>$f,'row_sha256'=>$r['row_sha256'],'handoff_row'=>$r['_row']];$use='notes_or_headers';$row=null;$kind=null;
            if($source==='roster'&&$sheet==='بيانات الطلاب'&&$n>=4) {
                if($n===4)$use='documented_example_excluded';
                else {
                    $kind='identities';$row=['number'=>CompletionData::text($v['A']??null),'first_name'=>CompletionData::text($v['B']??null),'last_name'=>CompletionData::text($v['C']??null),
                        'father_name'=>CompletionData::text($v['D']??null),'full_name'=>CompletionData::text(($v['B']??'').' '.($v['C']??'')),'program'=>CompletionData::text($v['O']??null),
                        'college'=>CompletionData::text($v['N']??null),'enrollment_date'=>CompletionData::date($v['S']??null),'cohort'=>$v['R']??null,'status'=>null,'reference'=>$ref,
                        'original'=>['number'=>$v['A']??null,'first_name'=>$v['B']??null,'last_name'=>$v['C']??null,'father_name'=>$v['D']??null,'program'=>$v['O']??null,'enrollment_date'=>$v['S']??null]+$raw];
                    $use='roster_identity';
                }
            } elseif($source==='exam1'&&$sheet==='Data'&&$n>1) {
                $kind='identities';$row=['number'=>CompletionData::text($v['P']??null),'full_name'=>CompletionData::text($v['C']??null),'first_name'=>null,'last_name'=>null,'father_name'=>CompletionData::text($v['D']??null),
                    'program'=>CompletionData::text($v['R']??null),'college'=>CompletionData::text($v['Q']??null),'enrollment_date'=>CompletionData::date($v['L']??null),'cohort'=>null,'status'=>($v['S']??null)===true?'active':null,
                    'source_role'=>'identity_master','reference'=>$ref,'original'=>['number'=>$v['P']??null,'full_name'=>$v['C']??null,'father_name'=>$v['D']??null,'program'=>$v['R']??null,'enrollment_date'=>$v['L']??null]+$raw];$use='exam_identity';
            } elseif(($source==='exam1'&&$sheet==='OutcomeofMater'||$source==='exam2'&&$sheet==='علامات')&&$n>1) {
                $kind='observations';$row=['number'=>CompletionData::text($v['H']??null),'name'=>CompletionData::text($v['I']??null),'program'=>CompletionData::text($v['C']??null),'college'=>CompletionData::text($v['B']??null),
                    'course_code'=>CompletionData::text($v['E']??null),'course_name'=>CompletionData::text($v['F']??null),'plan_term'=>$v['D']??null,'year'=>$v['R']??null,'cohort'=>$v['S']??null,
                    'attempt'=>$v['P']??null,'exam_mark'=>$v['K']??null,'outcome'=>$v['M']??null,'supplementary'=>$v['Q']??null,'kind'=>'regular','reference'=>$ref,
                    'original'=>['number'=>$v['H']??null,'name'=>$v['I']??null,'program'=>$v['C']??null,'course_code'=>$v['E']??null,'course_name'=>$v['F']??null]+$raw];$use='regular_observation';
            } elseif(($source==='exam1'&&$sheet==='supplementary'||$source==='exam2'&&$sheet==='تكميلي')&&$n>1) {
                $kind='observations';$row=['number'=>CompletionData::text($v['F']??null),'name'=>CompletionData::text($v['G']??null),'program'=>CompletionData::text($v['I']??null),'college'=>CompletionData::text($v['H']??null),
                    'course_code'=>CompletionData::text($v['B']??null),'course_name'=>CompletionData::text($v['C']??null),'plan_term'=>$v['A']??null,'year'=>null,'cohort'=>null,'attempt'=>$v['E']??null,
                    'exam_mark'=>$v['J']??null,'outcome'=>null,'supplementary'=>true,'kind'=>'undated_supplementary','reference'=>$ref,
                    'original'=>['number'=>$v['F']??null,'name'=>$v['G']??null,'program'=>$v['I']??null,'course_code'=>$v['B']??null,'course_name'=>$v['C']??null]+$raw];$use='supplementary_observation';
            } elseif($source==='conflicts'&&$n>=6&&CompletionData::text($v['A']??null)!=='') {
                $cells=[];foreach(['A','B','C','D','E'] as $col)$cells[$col]=$v[$col]??null;
                $out['conflicts'][]=['number'=>CompletionData::text($v['A']),'problem'=>$v['D']??null,'solution'=>$v['E']??null,'reference'=>$ref,'cells'=>$cells];$rawReviews[$id]=$cells;$use='administrative_review';
            } elseif($source==='environment'||$source==='exam1'&&$sheet==='DBMater') {$out['catalog_evidence'][]=$r;$use='catalog_evidence';}
            if($row) {
                $name=$row['full_name']??$row['name'];
                if($row['number']!=='')$out[$kind][]=$row;
                elseif($name!==''){$out['unidentified_source_rows'][]=['reference'=>$ref,'name'=>$name,'blocker'=>'student_number_missing','original'=>$raw];$use='unidentified_student';}
                else $use='notes_or_empty_identity';
            }
            $uses[$use]=($uses[$use]??0)+1;
        }
        $totals=[];foreach($controls as $key=>$c) {
            $e=$c['expected'];if($e['retained_rows']!==$c['count']||$e['first_source_row']!==$c['first']||$e['last_source_row']!==$c['last']||!hash_equals($e['row_hashes_sha256'],hash_final($c['hash'])))throw new \InvalidArgumentException('Extraction control differs: '.$key);
            $totals[$e['source_id']]=($totals[$e['source_id']]??0)+$c['count'];
        }
        foreach($sources as $s)if($s['role']==='original_workbook') {
            if(($totals[$s['source_id']]??0)!==$s['extracted_rows_or_pages'])throw new \InvalidArgumentException('Source extraction count differs.');
            $expected=json_decode($s['extracted_sheets_json'],true,flags:JSON_THROW_ON_ERROR);$actual=array_column(array_map(fn($c)=>$c['expected'],array_filter($controls,fn($c)=>$c['expected']['source_id']===$s['source_id'])),'source_sheet');sort($expected);sort($actual);
            if($expected!==$actual)throw new \InvalidArgumentException('Source extraction worksheet set differs.');
        }
        $seen=[];foreach($tables['ConflictReview'] as $r) {
            if(isset($seen[$r['record_id']]))throw new \InvalidArgumentException('Duplicate administrative mirror.');$seen[$r['record_id']]=true;
            $cells=['A'=>$r['source_number'],'B'=>$r['exam_office_column_B'],'C'=>$r['student_affairs_column_C'],'D'=>$r['problem_column_D'],'E'=>$r['solution_column_E']];
            if(($rawReviews[$r['record_id']]??null)!==$cells)throw new \InvalidArgumentException('Administrative mirror differs from its original row.');
        }
        if(count($seen)!==count($rawReviews))throw new \InvalidArgumentException('Missing administrative mirror.');
        return $out+['tables'=>$tables,'original_sources'=>$sources,'extraction'=>['retained_rows'=>count($ids),'uses'=>$uses,'source_totals'=>$totals,'row_hash_encoding'=>'canonical_utf8_json_preserve_float_objects_v1','students_index_used_for_authority'=>false]];
    }
}
