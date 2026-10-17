<?php

namespace App\Services\StudentCompletion;

/** Pure reconciliation over a scoped live projection or an explicitly labelled offline snapshot. */
final class CompletionProjection
{
    public function build(array $bundle,array $state,string $basis,?array $numbers=null): array
    {
        $state['official_evidence']=(new CompletionOfficialEvidence)->read($state);
        $config=$bundle['config']; $groups=[];
        foreach(array_keys($bundle['approved_identities']??[]) as $number)$groups[$number]=[];
        foreach (['identities','observations'] as $kind) foreach ($bundle[$kind] as $row) {
            $number=$this->number($row,$config); $row['source_number']=$row['original']['number']??$row['number'];
            $row['number']=$number; $groups[$number][$kind][]=$row;
        }
        $existing=[];
        foreach ($state['students'] as $student) {
            $number=$student['student_number'];
            if (isset($existing[$number])) throw new \InvalidArgumentException('Duplicate canonical student number in database.');
            $existing[$number]=$student; $groups[$number]??=[];
        }
        ksort($groups,SORT_STRING); $rows=[];
        foreach ($groups as $number=>$group) {
            if ($numbers!==null && !in_array((string)$number,$numbers,true)) continue;
            $rows[]=$this->student((string)$number,$group,$existing[$number]??null,$state,$config,$bundle['conflicts'],$bundle['approved_identities'][$number]??null,($bundle['completion_approvals']??[])+['catalog_profile'=>$bundle['catalog_followup']??null]);
        }
        $counts=['existing'=>0,'archived'=>0,'missing'=>0,'ready_create'=>0,'ready_status'=>0,'blocked_create'=>0];
        $classifications=[];$examinations=[];
        foreach ($rows as $row) {
            $counts[$row['exists']?'existing':'missing']++;
            if ($row['archived']) $counts['archived']++;
            if ($row['creation']['ready']) $counts['ready_create']++;
            if ($row['status_change']['ready']) $counts['ready_status']++;
            if (!$row['exists'] && !$row['creation']['ready']) $counts['blocked_create']++;
            $classifications[$row['classification']]=($classifications[$row['classification']]??0)+1;
            $examinations[$row['exam_classification']]=($examinations[$row['exam_classification']]??0)+1;
        }
        $studentIds=array_filter(array_column($rows,'student_id'),fn($id)=>$id!==null);
        $unassignedObservations=count(array_filter($bundle['unassigned_source_records']??[],fn($r)=>isset($r['kind'])));
        $unassignedIdentities=count($bundle['unassigned_source_records']??[])-$unassignedObservations;
        $records=['students'=>count($rows),
            'registrations'=>count(array_filter($state['student_course_registrations'],fn($r)=>in_array((int)$r['student_id'],$studentIds,true))),
            'eligible_expected_registrations'=>array_sum(array_map(fn($r)=>array_sum(array_map(fn($p)=>count($p['expected']),$r['periods'])),$rows)),
            'source_identity_rows'=>array_sum(array_map(fn($r)=>count($r['source_identities']),$rows))+$unassignedIdentities,
            'source_observation_rows'=>array_sum(array_column($rows,'source_observation_count'))+$unassignedObservations,
            'unassigned_observation_rows'=>$unassignedObservations,'unassigned_identity_rows'=>$unassignedIdentities,
            'deduplicated_observations'=>array_sum(array_column($rows,'observation_count')),
            'unidentified_source_rows'=>count($bundle['unidentified_source_rows']??[])];
        return ['contract'=>'student-completion.report.v1','basis'=>$basis,'writes_performed'=>false,
            'counts'=>$counts,'record_counts'=>$records,'classifications'=>$classifications,'examination_classifications'=>$examinations,
            'unidentified_source_rows'=>$bundle['unidentified_source_rows']??[],'unassigned_source_records'=>$bundle['unassigned_source_records']??[],
            'resolution_controls'=>$bundle['resolution_controls']??null,'reconciliation'=>$this->summarize($rows),'students'=>$rows,
            'followup_controls'=>$bundle['followup_controls']??null,
            'population_groups'=>['missing_with_observations'=>count(array_filter($rows,fn($r)=>!$r['exists']&&$r['source_observation_count']>0)),
                'missing_without_observations'=>count(array_filter($rows,fn($r)=>!$r['exists']&&$r['source_observation_count']===0)),
                'existing_without_observations'=>count(array_filter($rows,fn($r)=>$r['exists']&&$r['source_observation_count']===0))]];
    }

    private function number(array $row,array $config): string
    {
        if(isset($row['ownership']))return $row['ownership']['number'];
        $original=CompletionData::text($row['number']);
        $name=CompletionData::text($row['full_name']??$row['name']??'');
        foreach ($config['identity_decisions']??[] as $decision) {
            if ($decision['source_number']!==$original || !in_array($name,$decision['names'],true)) continue;
            if (isset($decision['father_name']) && !empty($row['father_name']) && $decision['father_name']!==$row['father_name']) continue;
            return $decision['canonical_number'];
        }
        return $original;
    }

    private function student(string $number,array $group,?array $student,array $state,array $config,array $conflicts,?array $decision=null,array $followup=[]): array
    {
        $identities=$group['identities']??[]; $observations=$this->deduplicate($group['observations']??[]);
        $identityBlockers=[]; $approved=$config['approved_students'][$number]??[];
        foreach ($conflicts as $conflict) if (!$decision && $conflict['number']===$number && !in_array($number,$config['resolved_conflict_numbers']??[],true))
            $identityBlockers[]='identity_review_unresolved';
        // A reviewed alias is number AND name scoped; an unrecognized name cannot inherit its authority.
        $names=array_values(array_unique(array_filter(array_map(fn($r)=>CompletionData::text($r['full_name']??$r['name']??''),[...$identities,...$observations]))));
        if ($student) $names[]=CompletionData::text(($student['first_name']??'').' '.($student['last_name']??''));
        $accepted=$approved['names']??[];
        if($decision) {
            $identityBlockers=$decision['blockers'];
            $acceptedReferences=array_map(CompletionData::hash(...),$decision['accepted_records']);
            foreach([...$identities,...$observations] as $source)if(!in_array(CompletionData::hash($source['reference']),$acceptedReferences,true))$identityBlockers[]='unreviewed_source_identity';
        } elseif (count(array_unique($names))>1 && (!$accepted || array_diff($names,$accepted))) $identityBlockers[]='identity_names_differ';
        $approvedFields=array_map(fn($field)=>$field['value'],$decision['fields']??[]);
        $programs=[];$programResolution=null;
        if(isset($approvedFields['program'])) {
            $programResolution=(new CompletionProgramIdentity)->resolve($decision['fields']['program'],$state,$config);
            $identityBlockers=array_merge($identityBlockers,$programResolution['blockers']);
            $programs=array_values(array_filter($state['academic_programs'],fn($p)=>(int)$p['academic_program_id']===($programResolution['program_id']??null)));
        } elseif (isset($approved['program_code'])) {
            $programs=array_values(array_filter($state['academic_programs'],fn($p)=>$p['program_code']===$approved['program_code']));
        } else {
            $contexts=$identities?:$observations;
            $labels=array_values(array_unique(array_filter(array_column($contexts,'program'))));
            foreach ($labels as $label) {
                $colleges=array_values(array_unique(array_filter(array_column(array_filter($contexts,fn($r)=>$r['program']===$label),'college'))));
                $codes=array_values(array_unique(array_filter(array_map(fn($college)=>$config['program_aliases'][$college.'|'.$label]??null,$colleges))));
                $code=count($codes)===1?$codes[0]:($config['program_aliases'][$label]??null);
                $matches=array_values(array_filter($state['academic_programs'],fn($p)=>$code!==null ? $p['program_code']===$code : CompletionData::text($p['program_name'])===$label));
                if (count($matches)!==1) {$identityBlockers[]='program_missing_or_ambiguous';continue;}
                $programs[$matches[0]['academic_program_id']]=$matches[0];
            }
            $programs=array_values($programs);
            if (!$labels && $student) $programs=array_values(array_filter($state['academic_programs'],fn($p)=>(int)$p['academic_program_id']===(int)$student['academic_program_id']));
        }
        $program=count($programs)===1?$programs[0]:null;
        if (!$program) $identityBlockers[]='program_missing_or_ambiguous';
        if ($student && $program && (int)$program['academic_program_id']!==(int)$student['academic_program_id']) $identityBlockers[]='existing_program_transfer_required';
        $dates=array_values(array_unique(array_filter(array_map(fn($r)=>CompletionData::date($r['enrollment_date']??null),$identities))));
        if(isset($approvedFields['enrollment_date']))$dates=[CompletionData::date($approvedFields['enrollment_date'])];
        $enrollment=$student ? CompletionData::date($student['enrollment_date']??null) : (count($dates)===1?$dates[0]:null);
        $dateBlockers=count($dates)>1?['enrollment_dates_conflict']:[];
        if ($student && $dates && $enrollment && !in_array($enrollment,$dates,true)) $dateBlockers[]='existing_enrollment_date_differs';
        $years=array_values(array_unique(array_filter(array_map(fn($o)=>CompletionData::year($o['year']),$observations),fn($y)=>$y!==null)));
        $dateAuthority=$enrollment?['kind'=>'documented','references'=>array_column(array_filter($identities,fn($r)=>CompletionData::date($r['enrollment_date']??null)===$enrollment),'reference')]:null;
        if(!$student&&!$enrollment&&!$dateBlockers) {
            $explicit=$followup['dates'][$number]??null;
            $regular=array_values(array_filter($observations,fn($o)=>$o['kind']==='regular'));
            $evidenced=array_values(array_unique(array_filter(array_map(fn($o)=>CompletionData::year($o['year']),$regular),fn($y)=>$y!==null)));
            $ambiguous=array_filter($regular,fn($o)=>CompletionData::year($o['year'])===null);
            if($explicit) {$enrollment=$explicit['value'];$dateAuthority=$explicit;}
            elseif(isset($followup['missing_date'])&&$evidenced&&!$ambiguous) {
                $earliest=min($evidenced);$enrollment=sprintf('%04d-09-01',$earliest);
                $dateAuthority=['kind'=>'administratively_approved_estimate','value'=>$enrollment,'reference'=>$followup['missing_date']['reference'],
                    'earliest_exam_year'=>$earliest,'supporting_examination_references'=>array_column(array_filter($regular,fn($o)=>CompletionData::year($o['year'])===$earliest),'references')];
            } elseif(isset($followup['missing_date']))$dateBlockers[]=$ambiguous?'enrollment_estimate_exam_year_ambiguous':'enrollment_estimate_exam_year_missing';
        }
        $cohorts=array_values(array_unique(array_filter(array_map(fn($r)=>CompletionData::year($r['cohort']??null),[...$identities,...$observations]),fn($y)=>$y!==null)));
        $enrollmentYears=$enrollment?array_values(array_filter($state['academic_years'],fn($y)=>$y['start_date']<=$enrollment&&$y['end_date']>=$enrollment)):[];
        $start=count($enrollmentYears)===1?CompletionData::year($enrollmentYears[0]['year_name']):(!$enrollment&&count($cohorts)===1?$cohorts[0]:null);
        $reference=$years?max($years):null;
        $derived=$start!==null&&$reference!==null&&$reference>=$start?$reference-$start+1:null;
        $levelBasis=$enrollment?'exact_enrollment_academic_boundaries':'source_cohort';
        $levelReasons=$derived===null?['academic_year_or_cohort_unresolved']:[];
        if (!$enrollment && count($cohorts)>1) $levelReasons[]='source_cohorts_conflict';
        if ($dateBlockers) {$levelReasons=array_merge($levelReasons,$dateBlockers);$derived=null;}
        $levels=$derived===null?[]:array_values(array_filter($state['academic_levels'],fn($l)=>(int)$l['level_order']===$derived && ($l['is_active']??true)));
        $level=count($levels)===1?$levels[0]:null;
        if ($derived!==null && !$level) $levelReasons[]='derived_level_not_installed';
        $periods=$this->periods($student,$observations,$state,$config);
        $undated=array_values(array_filter($observations,fn($o)=>$o['kind']==='undated_supplementary' && $o['exam_mark']!==null && $o['exam_mark']!==''));
        $complete=array_values(array_filter($periods,fn($p)=>$p['classification']==='confirmed_full_absence'));
        $hasLater=false;
        $latestAbsence=$complete?end($complete):null;
        if($latestAbsence)foreach($periods as $later)if($later['order']>$latestAbsence['order'])$hasLater=true;
        if($latestAbsence&&$reference!==null&&$reference>$latestAbsence['year_start'])$hasLater=true;
        $classification=!$observations?'no_examination_data':($complete?($hasLater?'historical_absence_with_later_activity':'confirmed_full_absence'):
            (array_filter($periods,fn($p)=>$p['classification']==='partial_absence')?'partial_absence':
                ($periods&&collect($periods)->every(fn($p)=>$p['classification']==='no_full_absence')?'no_full_absence':'possible_absence_incomplete_evidence')));
        $examClassification=$classification;
        if ($identityBlockers) $classification='identity_or_program_conflict';
        $last=$periods?end($periods):null;
        $status=$student?$this->lookup($state['student_statuses'],'student_status_id',$student['student_status_id']??null):null;
        $statusBlockers=$identityBlockers;
        if (!$student) $statusBlockers[]='student_missing';
        if ($student && !empty($student['deleted_at'])) $statusBlockers[]='student_archived';
        if ($student && ($status['status_code']??null)!=='active') $statusBlockers[]='existing_status_protected';
        $administrative=($approvedFields['status']??null)==='frozen';
        if (!$administrative&&(!$last || $last['classification']!=='confirmed_full_absence' || $hasLater)) $statusBlockers[]='current_full_absence_not_proven';
        if (!$administrative&&$undated) $statusBlockers[]='supplementary_chronology_unknown';
        if (!$administrative&&array_filter($observations,fn($o)=>$o['kind']==='regular' && CompletionData::year($o['year'])===null)) $statusBlockers[]='examination_year_missing';
        // Annual observations beyond a classified period are later activity even when their semester is unknown.
        if (!$administrative&&$last && $reference!==null && $reference>$last['year_start']) $statusBlockers[]='later_examination_period_unresolved';
        $operations=$this->operationBlockers($student,$state);
        $statusBlockers=array_merge($statusBlockers,$operations['reasons']);
        if ($student) $statusBlockers=array_merge($statusBlockers,$state['lifecycle_blockers'][$student['student_id']]??[]);
        $frozen=array_values(array_filter($state['student_statuses'],fn($s)=>$s['status_code']==='frozen' && ($s['is_active']??true)));
        if (count($frozen)!==1) $statusBlockers[]='discontinuation_status_unavailable';
        $fields=['student_number'=>$number,'first_name'=>null,'last_name'=>null,'father_name'=>null,
            'academic_program_id'=>$program?(int)$program['academic_program_id']:null,
            'enrollment_date'=>$enrollment,'current_academic_level_id'=>$level?(int)$level['academic_level_id']:null,'student_status_id'=>null];
        foreach (['first_name','last_name','father_name'] as $key) {
            $values=array_values(array_unique(array_filter(array_map(fn($r)=>CompletionData::text($r[$key]??''),$identities))));
            $fields[$key]=$approvedFields[$key]??$approved[$key]??(count($values)===1?$values[0]:null);
        }
        $sourceStatuses=array_values(array_unique(array_filter(array_column($identities,'status'))));
        $creationStatus=count($sourceStatuses)===1?$sourceStatuses[0]:null;
        $statusAuthority=$creationStatus?['kind'=>'documented_source_status']:null;
        if(!$student&&!$sourceStatuses&&isset($followup['missing_status'])) {$creationStatus='active';$statusAuthority=['kind'=>'administratively_approved_missing_default']+$followup['missing_status'];}
        if($administrative)$creationStatus='frozen';
        if($administrative)$statusAuthority=['kind'=>'explicit_administrative_decision','decision'=>$decision['fields']['status']];
        $installedStatus=$creationStatus?$this->lookup($state['student_statuses'],'status_code',$creationStatus):null;
        if ($installedStatus&&($installedStatus['is_active']??true)&&(!$administrative||count($frozen)===1)) $fields['student_status_id']=(int)$installedStatus['student_status_id'];
        $creationBlockers=array_merge($identityBlockers,$dateBlockers,$levelReasons);
        if(count($sourceStatuses)>1&&!$administrative)$creationBlockers[]='creation_source_status_conflict';
        if($administrative&&count($frozen)!==1)$creationBlockers[]='discontinuation_status_unavailable';
        foreach(array_keys($decision['unresolved_fields']??[]) as $field)$creationBlockers[]='approved_field_unresolved:'.$field;
        foreach (['first_name','last_name','academic_program_id','enrollment_date','current_academic_level_id','student_status_id'] as $key)
            if ($fields[$key]===null || $fields[$key]==='') $creationBlockers[]='creation_field_missing:'.$key;
        foreach(['student_number'=>50,'first_name'=>100,'last_name'=>100,'father_name'=>100] as $key=>$max)
            if($fields[$key]!==null && mb_strlen($fields[$key])>$max)$creationBlockers[]='creation_field_too_long:'.$key;
        $creationName=CompletionData::text(($fields['first_name']??'').' '.($fields['last_name']??''));
        if(!$decision&&$creationName!==''&&!in_array($creationName,[...$names,...$accepted],true))$creationBlockers[]='creation_name_not_supported_by_evidence';
        if($program&&!empty($program['duration_years'])&&$derived!==null&&$derived>(int)$program['duration_years']) $creationBlockers[]='derived_level_exceeds_program_duration';
        $plan=$program?$this->plan($program,$state,$followup['catalog_profile']??null):['ready'=>false,'version_id'=>null,'basis'=>null,'blockers'=>['program_missing_or_ambiguous']];
        $creationBlockers=array_merge($creationBlockers,$plan['blockers']);
        $corrections=[];
        if($student&&$decision)foreach(['first_name','last_name','father_name'] as $field)if(isset($approvedFields[$field])&&CompletionData::text($student[$field]??'')!==$approvedFields[$field])
            $corrections[$field]=['before'=>$student[$field]??null,'after'=>$approvedFields[$field],'authority'=>$decision['fields'][$field]];
        $correctionBlockers=[];
        foreach($corrections as $field=>$change)if(!is_string($change['after'])||CompletionData::text($change['after'])===''||mb_strlen($change['after'])>100)
            $correctionBlockers[]='student_identity_field_invalid:'.$field;
        if($student&&!empty($student['deleted_at']))$correctionBlockers[]='student_archived';
        if($student&&($status['status_code']??null)!=='active')$correctionBlockers[]='existing_status_protected';
        return ['number'=>$number,'exists'=>$student!==null,'student_id'=>$student?(int)$student['student_id']:null,
            'archived'=>$student&&!empty($student['deleted_at']),'source_identities'=>$identities,'source_names'=>$names,'source_observations'=>$observations,
            'observation_count'=>count($observations),'source_observation_count'=>array_sum(array_column($observations,'duplicate_count')),
            'identity_blockers'=>array_values(array_unique($identityBlockers)),'program_id'=>$program?(int)$program['academic_program_id']:null,
            'program_resolution'=>$programResolution,
            'lock_program_id'=>$student?(int)$student['academic_program_id']:($program?(int)$program['academic_program_id']:null),
            'current_status'=>$status['status_code']??null,'current_level_id'=>$student['current_academic_level_id']??null,
            'derived_level'=>['value'=>$derived,'basis'=>$levelBasis,'reference_year'=>$reference,'blockers'=>array_values(array_unique($levelReasons))],
            'periods'=>$periods,'undated_supplementary_count'=>count($undated),'classification'=>$classification,'exam_classification'=>$examClassification,
            'historical_plan'=>$plan,'creation'=>['ready'=>!$student&&!$creationBlockers,'action'=>$student?'existing_noop':'create_historical',
                'fields'=>$fields,'blockers'=>$student?[]:array_values(array_unique($creationBlockers)),
                'provenance'=>['enrollment_date'=>$dateAuthority,'status'=>$statusAuthority]],
            'approved_identity'=>$decision,'identity_correction'=>['ready'=>(bool)$corrections&&!$correctionBlockers,'changes'=>$corrections,'blockers'=>$correctionBlockers],
            'status_change'=>['authority'=>$administrative?'administrative':'examination','decision'=>$administrative?$decision['fields']['status']:null,
                'ready'=>!$statusBlockers,'to_status_id'=>count($frozen)===1?(int)$frozen[0]['student_status_id']:null,
                'blockers'=>array_values(array_unique($statusBlockers)),'registration_blockers'=>$operations['registrations']],
            'baseline'=>$student?array_intersect_key($student,array_flip(['student_id','student_number','first_name','last_name','father_name','academic_program_id','student_status_id','current_academic_level_id','enrollment_date','deleted_at','updated_at'])):null];
    }

    private function periods(?array $student,array $observations,array $state,array $config): array
    {
        $periods=[]; $registrations=[];
        if ($student) foreach ($state['student_course_registrations'] as $r) if ((int)$r['student_id']===(int)$student['student_id']) {
            $rs=$this->lookup($state['registration_statuses'],'registration_status_id',$r['registration_status_id']);
            if (!in_array($rs['status_code']??null,['registered','completed'],true)) continue;
            $o=$this->lookup($state['course_offerings'],'course_offering_id',$r['course_offering_id']);
            $c=$o?$this->lookup($state['courses'],'course_id',$o['course_id']):null;
            $y=$o?$this->lookup($state['academic_years'],'academic_year_id',$o['academic_year_id']):null;
            $s=$o?$this->lookup($state['semesters'],'semester_id',$o['semester_id']):null;
            if (!$o||!$c||!$y||!$s) continue;
            $key=$y['academic_year_id'].':'.$s['semester_id'];
            $periods[$key]??=['year_id'=>(int)$y['academic_year_id'],'semester_id'=>(int)$s['semester_id'],
                'year_start'=>CompletionData::year($y['year_name']),'order'=>$y['start_date'].':'.sprintf('%02d',$s['semester_order']),
                'expected'=>[],'observations'=>[],'coverage_basis'=>null,'blockers'=>[]];
            $r['course']=$c; $r['year']=$y; $r['key']=$key;$r['order']=$periods[$key]['order']; $registrations[]=$r;
            $periods[$key]['expected'][]=['registration_id'=>(int)$r['student_course_registration_id'],'course_id'=>(int)$c['course_id'],
                'course_code'=>$c['course_code'],'credit_hours'=>$c['credit_hours'],'offering_id'=>(int)$o['course_offering_id']];
        }
        usort($registrations,fn($a,$b)=>[$a['order'],(int)$a['student_course_registration_id']]<=>[$b['order'],(int)$b['student_course_registration_id']]);
        $attempts=[];
        foreach($registrations as &$registration) $registration['attempt']=($attempts[$registration['course']['course_id']]=($attempts[$registration['course']['course_id']]??0)+1);
        unset($registration);$unmapped=[];
        foreach ($observations as $obs) {
            if ($obs['kind']!=='regular') continue;
            $year=CompletionData::year($obs['year']);
            $matches=array_values(array_filter($registrations,function($r)use($obs,$year,$config) {
                $code=$config['course_aliases'][$obs['course_code'].'|'.$obs['course_name']]??$obs['course_code'];
                $nameMatches=CompletionData::text($r['course']['course_name'])===CompletionData::text($obs['course_name'])
                    || isset($config['course_aliases'][$obs['course_code'].'|'.$obs['course_name']]);
                $attempt=$obs['attempt'];
                $attemptMatches=$attempt===null||$attempt===''||(ctype_digit((string)$attempt)&&(int)$attempt===$r['attempt']);
                return $nameMatches&&$attemptMatches&&$r['course']['course_code']===$code&&CompletionData::year($r['year']['year_name'])===$year;
            }));
            // An annual row cannot choose between two actual semester/attempt contexts.
            if (count($matches)!==1) {$unmapped[]=$obs;continue;}
            $r=$matches[0]; $periods[$r['key']]['observations'][(int)$r['student_course_registration_id']][]=$obs;
        }
        foreach ($periods as &$period) {
            $terms=array_values(array_filter($state['student_academic_terms'],fn($t)=>(int)$t['student_id']===(int)$student['student_id'] && (int)$t['academic_year_id']===$period['year_id'] && (int)$t['semester_id']===$period['semester_id']));
            $hours=array_sum(array_column($period['expected'],'credit_hours'));
            // A finalized canonical term certifies the roster; the presence of some imported rows does not.
            $knownHours=!array_filter($period['expected'],fn($e)=>$e['credit_hours']===null||!is_numeric($e['credit_hours'])||$e['credit_hours']<0);
            $complete=count($terms)===1 && in_array($terms[0]['is_finalized'],[true,1,'1'],true) && isset($terms[0]['total_registered_hours'])
                && (int)$terms[0]['total_registered_hours']===$hours && count($period['expected'])>0 && $knownHours;
            if ($complete) $period['coverage_basis']=['kind'=>'canonical_finalized_term','id'=>(int)$terms[0]['student_academic_term_id']];
            else $period['blockers'][]='expected_roster_completeness_unproven';
            $absent=0;$present=0;
            foreach ($period['expected'] as $expected) {
                $observed=$period['observations'][$expected['registration_id']]??[];
                if (count($observed)!==1) {$period['blockers'][]=$observed?'conflicting_examination_copies':'examination_coverage_missing';continue;}
                $obs=$observed[0];
                $official=$state['official_evidence'][$expected['registration_id']]??null;
                if($obs['exam_mark']==='-'&&($official['status']??null)==='passed') {
                    $period['blockers'][]='official_success_conflicts_with_absence';
                    $period['evidence_conflicts'][]=['source_references'=>$obs['references'],'official'=>$official];continue;
                }
                if (in_array(CompletionData::text($obs['outcome']),['I','W','غير مكتمل','منسحب'],true)) {$period['blockers'][]='examination_outcome_unresolved';continue;}
                if ($obs['exam_mark']==='-' && in_array(CompletionData::text($obs['outcome']),['passed','ناجح','ناجح شرطي'],true)) {$period['blockers'][]='examination_conflicting_evidence';continue;}
                if ($obs['exam_mark']==='-') $absent++;
                elseif (is_numeric($obs['exam_mark'])) $present++;
                else $period['blockers'][]='examination_outcome_unknown';
            }
            foreach ($unmapped as $obs) if (CompletionData::year($obs['year'])===$period['year_start']) $period['blockers'][]='annual_examination_mapping_unresolved';
            $period['absent_examinations']=$absent; $period['nonabsence_examinations']=$present;
            $period['classification']=$complete&&!$period['blockers']&&$absent===count($period['expected'])?'confirmed_full_absence':
                ($absent&&$present?'partial_absence':($present&&!$absent&&!$period['blockers']?'no_full_absence':'possible_absence_incomplete_evidence'));
            $period['blockers']=array_values(array_unique($period['blockers']));
        } unset($period);
        uasort($periods,fn($a,$b)=>strcmp($a['order'],$b['order']));
        return array_values($periods);
    }

    private function plan(array $program,array $state,?array $profile=null): array
    {
        $blockers=[]; $version=null; $basis='legacy_current_context';$context=null;
        if (!empty($program['archived_at'])) $blockers[]='academic_program_archived';
        $status=$program['plan_state']??null;
        if ($status==='ready') {
            $default=$this->lookup($state['academic_plan_versions'],'academic_plan_version_id',$program['default_academic_plan_version_id']);
            if (!$default || $default['status']!=='approved' || empty($default['fixed_at'])) $blockers[]='current_admission_context_unavailable';
            $sources=array_values(array_filter($state['academic_plan_versions'],fn($v)=>(int)$v['academic_program_id']===(int)$program['academic_program_id'] && $v['status']==='transitional' && !empty($v['fixed_at'])));
            if (count($sources)!==1) {
                $approved=$profile?collect($profile['programs'])->first(fn($p)=>$p['program_code']===$program['program_code']&&(int)$p['department_id']===(int)$program['department_id']):null;
                $events=array_values(array_filter($state['academic_plan_events']??[],fn($e)=>(int)$e['academic_program_id']===(int)$program['academic_program_id']
                    &&(int)$e['academic_plan_version_id']===(int)$program['default_academic_plan_version_id']&&$e['action']==='catalog_completion_published'));
                foreach($events as $e) {
                    $proof=json_decode($e['context'],true,flags:JSON_THROW_ON_ERROR);
                    if($approved&&($proof['manifest_hash']??null)===$profile['hash']&&($proof['program_key']??null)===$approved['key']
                        &&($proof['new_program_created']??false)===true&&($proof['source_version_id']??null)===null
                        &&($proof['reference_kind']??null)==='approved_now_from_recorded_source_curriculum_not_historical_approval')
                        $context=['kind'=>$proof['reference_kind'],'profile_hash'=>$profile['hash'],'program_key'=>$approved['key'],'event_id'=>(int)$e['academic_plan_event_id']];
                }
                if(!$context)$blockers[]='historical_plan_context_unproven';
                else {$version=(int)$program['default_academic_plan_version_id'];$basis=$context['kind'];}
            }
            else {$version=(int)$sources[0]['academic_plan_version_id'];$basis='fixed_transitional_reference_not_historical_approval';}
        } elseif ($status!=='legacy') $blockers[]='academic_plan_initialization_incomplete';
        $result=['ready'=>!$blockers,'version_id'=>$version,'basis'=>$basis,'blockers'=>$blockers];
        if($context)$result['completion_context']=$context;
        return $result;
    }

    private function operationBlockers(?array $student,array $state): array
    {
        if (!$student) return ['reasons'=>[],'registrations'=>[]];
        $reasons=[];$registrations=[];$id=(int)$student['student_id'];
        foreach (['student_registration_requests','student_registration_modification_requests','student_registration_replacement_requests','student_registration_withdrawal_requests'] as $table)
            foreach ($state[$table] as $r) if ((int)$r['student_id']===$id && in_array($r['status'],['draft','submitted','returned'],true)) $reasons[]='pending_academic_operation';
        foreach (['student_progression_decisions','student_graduation_decisions'] as $table) foreach ($state[$table] as $r) if ((int)$r['student_id']===$id &&
            ((!empty($r['current_slot'])&&in_array($r['status'],['submitted','returned'],true))||($table==='student_graduation_decisions'&&$r['status']==='approved'&&!empty($r['materialized_at'])))) $reasons[]='protected_academic_decision';
        foreach ($state['grade_appeals'] as $r) if ((int)$r['student_id']===$id) {
            $status=$this->lookup($state['appeal_statuses'],'appeal_status_id',$r['appeal_status_id']);
            if (!in_array($status['status_code']??null,['closed','rejected'],true)) $reasons[]='unresolved_grade_appeal';
        }
        foreach ($state['supplementary_exam_registrations'] as $r) if ((int)$r['student_id']===$id && $r['status']==='registered'
            && !$this->lookup($state['supplementary_exam_materializations'],'supplementary_exam_registration_id',$r['supplementary_exam_registration_id'])) $reasons[]='supplementary_materialization_pending';
        foreach($state['student_course_registrations'] as $r) if((int)$r['student_id']===$id) {
            $registrationStatus=$this->lookup($state['registration_statuses'],'registration_status_id',$r['registration_status_id']);
            $work=$state['official_evidence'][$r['student_course_registration_id']]['work_blocker']??null;
            if(in_array($registrationStatus['status_code']??null,['registered','completed'],true)&&$work!==null) {
                $reasons[]='ongoing_registration:'.$work;
                $registrations[]=['registration_id'=>(int)$r['student_course_registration_id'],'offering_id'=>(int)$r['course_offering_id'],'reason'=>$work];
            }
            $result=$this->lookup($state['student_course_results'],'student_course_registration_id',$r['student_course_registration_id']);
            $status=$result?$this->lookup($state['result_statuses'],'result_status_id',$result['result_status_id']):null;
            if(in_array($status['status_code']??null,['incomplete','pending_approval'],true))$reasons[]='academic_outcome_unresolved';
        }
        return ['reasons'=>array_values(array_unique($reasons)),'registrations'=>$registrations];
    }
    private function lookup(array $rows,string $key,mixed $value): ?array {foreach($rows as $row) if((string)($row[$key]??'')===(string)$value)return $row;return null;}
    public function summarize(array $rows): array
    {
        $count=fn($f)=>count(array_filter($rows,$f));$blockers=[];
        foreach($rows as $r)foreach(array_unique([...$r['identity_blockers'],...$r['creation']['blockers'],...$r['status_change']['blockers'],
            ...($r['identity_correction']['changes']?$r['identity_correction']['blockers']:[])]) as $reason)$blockers[$reason]=($blockers[$reason]??0)+1;
        ksort($blockers);
        return ['exam_linked_existing'=>$count(fn($r)=>$r['exists']===true&&$r['source_observation_count']>0),
            'ready_missing'=>$count(fn($r)=>$r['creation']['ready']),'remaining_missing'=>$count(fn($r)=>$r['exists']===false&&!$r['creation']['ready']),
            'reviewed_identity_resolutions'=>$count(fn($r)=>$r['approved_identity']!==null),
            'identity_correction_students'=>$count(fn($r)=>$r['identity_correction']['ready']),
            'identity_correction_fields'=>array_sum(array_map(fn($r)=>$r['identity_correction']['ready']?count($r['identity_correction']['changes']):0,$rows)),
            'required_program_transfers'=>$count(fn($r)=>in_array('existing_program_transfer_required',$r['identity_blockers'],true)),
            'administrative_discontinued'=>$count(fn($r)=>$r['status_change']['authority']==='administrative'),
            'examination_proved_current'=>$count(fn($r)=>$r['exam_classification']==='confirmed_full_absence'),
            'examination_insufficient_evidence'=>$count(fn($r)=>$r['exam_classification']==='possible_absence_incomplete_evidence'),
            'remaining_blockers'=>$blockers];
    }
    private function deduplicate(array $observations): array
    {
        $unique=[];
        foreach ($observations as $observation) {
            $body=$observation;unset($body['reference'],$body['source_number'],$body['original'],$body['ownership'],$body['ownership_history'],$body['approved_owner_mapping']);$key=CompletionData::hash($body);
            if (!isset($unique[$key])) $unique[$key]=$observation+['references'=>[],'original_copies'=>[],'duplicate_count'=>0];
            $unique[$key]['references'][]=$observation['reference']; $unique[$key]['duplicate_count']++;
            $copy=['reference'=>$observation['reference'],'values'=>$observation['original']??$observation];
            foreach(['ownership','ownership_history','approved_owner_mapping'] as $provenance)if(isset($observation[$provenance]))$copy[$provenance]=$observation[$provenance];
            $unique[$key]['original_copies'][]=$copy;
        }
        return array_values($unique);
    }
}
