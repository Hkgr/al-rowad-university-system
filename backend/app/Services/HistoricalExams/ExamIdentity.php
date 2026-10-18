<?php
namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\{CompletionData,CompletionProgramIdentity,ConsolidatedCompletionCatalog};
use App\Services\CatalogCompletion\FollowupCourseIdentity;

/** Approved number-and-person or exact-row decisions. Never a global number/name substitution. */
final class ExamIdentity
{
    public const PROFILE='historical-exam-identities.2026-10-18.v1';
    private array $policy;
    public function __construct() {$this->policy=self::approvals();}
    public static function approvals(): array
    {
        $p=json_decode(file_get_contents(dirname(__DIR__,3).'/resources/historical-exams/ownership-policy.v1.json'),true,flags:JSON_THROW_ON_ERROR);
        if(($p['profile']??null)!==self::PROFILE)throw new \InvalidArgumentException('Unsupported approved ownership profile.');return $p;
    }
    public static function text(mixed $v): string {return CompletionData::text($v);}
    private function name(string $v): string {return strtr(self::text($v),['أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي']);}
    private function approvedSpelling(string $v): string {return strtr($this->name($v),['ة'=>'ه']);}
    private function at(array $reference,array $rule): bool {return $reference['file']===$rule['file']&&$reference['sheet']===$rule['sheet']&&in_array($reference['row'],$rule['rows'],true);}
    public function student(array $r,array $state): array
    {
        $v=$r['values'];$n=self::text($v['number']);$name=$this->name(self::text($v['name']));$original=$n;$decision=null;$targetHash=null;$blockers=[];$p=$this->policy;
        if($this->at($r['reference'],$p['deferred']))return ['student'=>null,'number'=>null,'blockers'=>['deferred_identity:'.$r['id']],'decision'=>'explicit_identity_deferral','source_number'=>$original];
        foreach($p['number_person_rules'] as $rule)if(in_array(CompletionData::hash([$n,$this->approvedSpelling($name)]),$rule['source_pair_hashes'],true)){$targetHash=$rule['target_hash'];$decision=$rule['decision'];break;}
        if($this->at($r['reference'],$p['missing_number'])) {
            if($n!==''||!in_array(CompletionData::hash($this->approvedSpelling($name)),$p['missing_number']['source_name_hashes'],true))$blockers[]='approved_source_context_changed';
            else {$targetHash=$p['missing_number']['target_hash'];$decision='approved_missing_number_exact_rows';}
        }
        foreach($p['row_rules'] as $rule)if($this->at($r['reference'],$rule)) {
            $valid=hash_equals($rule['source_name_hash'],CompletionData::hash($this->approvedSpelling($name)))
                &&(!isset($rule['source_number_hash'])||hash_equals($rule['source_number_hash'],CompletionData::hash($original)))
                &&(!isset($rule['course_code'])||(preg_replace('/\\s+/u','',self::text($v['code']))===$rule['course_code']&&self::text($v['course'])===$rule['course_name']));
            if(!$valid)$blockers[]='approved_source_context_changed';else {$targetHash=$rule['target_hash'];$decision=$rule['decision'];}
        }
        $separate=$p['separation'];
        if(hash_equals($separate['source_number_hash'],CompletionData::hash($original))&&in_array(CompletionData::hash($this->approvedSpelling($name)),$separate['source_name_hashes'],true)
            &&str_contains(self::text($v['college']),'المعهد')&&str_contains(self::text($v['program']),'مدني')){$targetHash=$separate['target_hash'];$decision='approved_qualified_person_separation';}
        // The repository stores fingerprints, never a student-number/name roster. Resolve only the approved target hash.
        $matches=$targetHash?array_values(array_filter($state['students'],fn($s)=>hash_equals($targetHash,CompletionData::hash($s['student_number'])))):
            ($n===''?[]:array_values(array_filter($state['students'],fn($s)=>$s['student_number']===$n)));
        $s=count($matches)===1?$matches[0]:null;if($targetHash)$n=$s['student_number']??null;
        if(!$s)$blockers[]=$targetHash?'approved_target_missing_or_ambiguous':'student_missing_or_ambiguous';
        elseif(!empty($s['deleted_at']))$blockers[]='archived_student_requires_review';
        elseif($decision==='approved_missing_number_exact_rows') {
            if(!hash_equals($p['missing_number']['target_identity_hash'],CompletionData::hash(array_map($this->approvedSpelling(...),[$s['first_name'],$s['last_name'],$s['father_name']??'']))))$blockers[]='approved_target_context_changed';
            if((collect($state['academic_programs'])->firstWhere('academic_program_id',$s['academic_program_id'])['program_code']??null)!==$p['missing_number']['program_code'])$blockers[]='approved_target_program_context_changed';
        } elseif($targetHash) {
            $expected=$p['target_name_hashes'][$targetHash]??null;
            if(!$expected||!hash_equals($expected,CompletionData::hash(array_map($this->approvedSpelling(...),[$s['first_name'],$s['last_name']]))))$blockers[]='approved_target_context_changed';
        } else {
            $labels=[$s['first_name'].' '.$s['last_name'],$s['first_name'].' '.($s['father_name']??'').' '.$s['last_name']];
            if(!in_array($name,array_map($this->name(...),$labels),true))$blockers[]='student_number_person_conflict';
        }
        return ['student'=>$s,'number'=>$n,'source_number'=>$original,'decision'=>$decision,'target_number_hash'=>$targetHash,'blockers'=>array_values(array_unique($blockers))];
    }
    public function program(array $v,array $state): array
    {
        $catalog=new ConsolidatedCompletionCatalog;$aliases=$catalog->collegeAliases();
        foreach($state['colleges'] as $college) {
            $label=preg_replace('/^كلية\s+/u','',self::text($college['college_name']));
            foreach([$label,strtr($label,['أ'=>'ا','إ'=>'ا','آ'=>'ا'])] as $alias)if(!isset($aliases[$alias]))$aliases[$alias]=$college['college_code'];
        }
        return (new CompletionProgramIdentity)->resolve(['value'=>self::text($v['program']),'contexts'=>[['college'=>self::text($v['college'])]]],$state,
            ['program_aliases'=>$catalog->aliases(),'college_aliases'=>$aliases]);
    }
    /** Number/person ownership is resolved BEFORE consulting Data. Names alone never select a master record. */
    public function sourceProgram(array $row,array $owner,array $state,array $master): array
    {
        $v=$row['values'];$explicit=self::text($v['program'])!=='';$resolved=$this->program($v,$state);$evidence=[];$ids=[];$errors=[];
        if($owner['student']&&!$owner['blockers'])foreach($master as $record) {
            $number=self::text($record['values']['number']);
            if($number===''||!in_array($number,[$owner['source_number'],$owner['number']],true))continue;
            $person=$this->student($record,$state);$entry=['record'=>$record,'ownership'=>$person,'resolution'=>null];
            if($person['student']&&(int)$person['student']['student_id']!==(int)$owner['student']['student_id']&&!$person['blockers']) {
                $entry['basis']='different_original_number_owner_not_program_evidence';$evidence[]=$entry;continue;
            }
            if(!$person['student']||$person['blockers']||(int)$person['student']['student_id']!==(int)$owner['student']['student_id']) {
                $entry['basis']='student_master_ownership_unproven';$errors[]='student_master_ownership_unproven';$evidence[]=$entry;continue;
            }
            $father=self::text($record['values']['father']??null);
            if($father!==''&&$this->name($father)!==$this->name($owner['student']['father_name']??'')) {
                $entry['basis']='student_master_father_conflict';$errors[]='student_master_father_conflict';$evidence[]=$entry;continue;
            }
            $p=$this->program($record['values'],$state);$entry['resolution']=$p;$entry['basis']='number_person_and_college_qualified_Data';
            if(!$p['program_id'])$errors[]='student_master_program_unresolved';
            else {
                $ids[$p['program_id']]=$p;
                if(self::text($v['college'])!=='') {
                    $qualified=$this->program(array_replace($record['values'],['college'=>$v['college']]),$state);
                    if(!$qualified['program_id']||(int)$qualified['program_id']!==(int)$p['program_id']||(int)$qualified['college_id']!==(int)$p['college_id'])$errors[]='student_master_college_conflict';
                }
            }
            $evidence[]=$entry;
        }
        if(count($ids)>1)$errors[]='student_master_program_conflict';
        if($explicit&&$ids&&(!$resolved['program_id']||!isset($ids[$resolved['program_id']])))$errors[]='explicit_exam_and_student_master_program_conflict';
        if(!$explicit&&count($ids)===1&&!$errors)$resolved=array_values($ids)[0];
        return array_replace($resolved,['basis'=>$explicit?'explicit_qualified_examination_program':(count($ids)===1&&!$errors?'unique_qualified_student_master':'program_evidence_missing_or_conflicting'),
            'student_master_evidence'=>$evidence,'evidence_conflict'=>$errors!==[],'blockers'=>array_values(array_unique([...($errors?[]:$resolved['blockers']),...$errors])),
            'program_id'=>$errors?null:$resolved['program_id']]);
    }
    public function course(array $v,int $program,array $state,?int $studentId=null): array
    {
        $identity=new FollowupCourseIdentity;$codes=[];$code=preg_replace('/\s+/u','',self::text($v['code']));$label=self::text($v['course']);$p=collect($state['academic_programs'])->firstWhere('academic_program_id',$program);
        // Reuse the catalog's exact approved facts, including original curriculum references and pinned origin 409.
        $approval=$identity->historicalMapping($p['program_code']??'',$code,$label,(int)$v['credits']);if($approval)$codes[]=$approval['system_code'];
        if(!$approval&&in_array($p['program_code']??'', ['RU1-TI-CE','RU1-TI-E'],true)&&in_array($code,['TI112','TI113'],true))return ['course'=>null,'mapping'=>['original_code'=>$code,'program_id'=>$program,'catalog_approval'=>null],'blockers'=>['approved_course_subject_or_credits_conflict']];
        if(!$codes)$codes=[$code];$matches=[];
        foreach($state['courses'] as $c)if(in_array($c['course_code'],$codes,true)&&(string)$c['credit_hours']===(string)$v['credits']) {
            if(isset($approval['course_id'])&&(int)$c['course_id']!==$approval['course_id'])continue;
            $contexts=$identity->contexts($state,(int)$c['course_id']);$university=collect($contexts)->contains(fn($x)=>$x['kind']==='university_membership');
            // Pinned university reuse requires its approved active university scope even if an old registration exists.
            if(isset($approval['course_id'])&&(!$university||!(bool)($c['is_active']??false)))continue;
            if(($approval['authority']??null)==='engineering-distinct-codes'&&(!(bool)($c['is_active']??false)||!collect($contexts)->contains(fn($x)=>(int)$x['department_id']===(int)$p['department_id'])))continue;
            $row=['system_code'=>$c['course_code'],'course_name'=>$label,'credit_hours'=>(int)$v['credits'],'requirement_scope'=>$university?'university':'college'];
            $historic=[];
            if($studentId!==null) {
                $offerings=array_column($state['course_offerings'],null,'course_offering_id');$years=array_column($state['academic_years'],null,'academic_year_id');
                foreach($state['_student_course_registrations'][$studentId]??$state['student_course_registrations'] as $reg)if((int)$reg['student_id']===$studentId) {
                    $o=$offerings[$reg['course_offering_id']]??null;
                    if($o&&(int)$o['course_id']===(int)$c['course_id']&&(int)$o['academic_program_id']===$program&&CompletionData::year($years[$o['academic_year_id']]['year_name'])===CompletionData::year($v['year']??null))$historic[]=$reg['student_course_registration_id'];
                }
            }
            if($identity->nameMatches($row,$c)&&($identity->contextMatches($row,$p,$contexts)||$historic))$matches[]=$c;
        }
        return ['course'=>count($matches)===1?$matches[0]:null,'mapping'=>['original_code'=>$code,'candidate_codes'=>$codes,'program_id'=>$program,'catalog_approval'=>$approval], 'blockers'=>count($matches)===1?[]:['course_subject_credit_context_unresolved']];
    }
}
