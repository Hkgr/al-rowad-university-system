<?php
namespace App\Services\HistoricalExams;
use App\Models\User;
use App\Services\StudentCompletion\CompletionData;
use Illuminate\Support\Facades\{DB,Schema};

/** Content preservation of pre-existing evidence. New rows never substitute for a missing old row. */
final class ExamEvidence
{
    private const KEYS=['students'=>'student_id','courses'=>'course_id','academic_programs'=>'academic_program_id','course_offerings'=>'course_offering_id',
        'student_course_registrations'=>'student_course_registration_id','student_course_results'=>'student_course_result_id','student_grade_components'=>'student_grade_component_id',
        'grade_components'=>'grade_component_id','grade_approvals'=>'grade_approval_id','grade_part_approvals'=>'grade_part_approval_id',
        'student_course_incomplete_cases'=>'incomplete_case_id','student_course_incomplete_events'=>'incomplete_event_id',
        'academic_plan_versions'=>'academic_plan_version_id','student_academic_plan_assignments'=>'student_academic_plan_assignment_id',
        'student_progression_decisions'=>'student_progression_decision_id','student_graduation_decisions'=>'student_graduation_decision_id','student_academic_terms'=>'student_academic_term_id',
        'grade_audit_logs'=>'grade_audit_log_id','grade_part_approval_events'=>'grade_part_approval_event_id','academic_plan_events'=>'academic_plan_event_id',
        'historical_exam_work_items'=>'work_item_id','registration_credit_evidence'=>'credit_evidence_id','plan_course_credit_evidence'=>'credit_evidence_id','historical_exam_outcomes'=>'historical_outcome_id'];
    public function capture(User $actor,array $review): array
    {
        $state=app(ExamDatabase::class)->read($actor);$allowed=[];
        foreach($review['actions'] as $a)if($a['eligible'])foreach($a['baseline']['marks'] as $mark)if($mark['mark']===null&&collect($a['proposed_components'])->contains(fn($m)=>(string)$m['key']===(string)$mark['grade_component_id']))$allowed[$mark['student_grade_component_id']]=true;
        $out=[];
        foreach(self::KEYS as $table=>$key)foreach($state[$table]??[] as $row) {
            if($table==='student_grade_components'&&isset($allowed[$row[$key]]))continue;
            $out[$table][(string)$row[$key]]=CompletionData::hash($row);
        }
        return $out;
    }
    public function verify(User $actor,array $expected): bool
    {
        $state=app(ExamDatabase::class)->read($actor);
        $chains=[];
        foreach($expected as $table=>$hashes) {
            $rows=array_column($state[$table]??[],null,self::KEYS[$table]);
            foreach($hashes as $id=>$hash) {
                if(!isset($rows[$id]))return false;
                if(!hash_equals($hash,CompletionData::hash($rows[$id]))&&!$this->adoptedTransition($table,(string)$id,$hash,$rows[$id],$state,$chains))return false;
            }
        }return true;
    }

    /** Only an exact append-only, receipted before -> after chain can explain a changed target row. */
    private function adoptedTransition(string $table,string $id,string $expected,array $current,array $state,array &$chains): bool
    {
        if(!in_array($table,['student_course_registrations','student_course_results','student_grade_components'],true))return false;
        $registration=(int)($table==='student_course_registrations'?$id:$current['student_course_registration_id']);
        if(!array_key_exists($registration,$chains)) {
            $events=array_values(array_filter($state['historical_exam_outcomes']??[],fn($e)=>(int)$e['student_course_registration_id']===$registration));
            usort($events,fn($a,$b)=>(int)$a['revision']<=>(int)$b['revision']);$chain=[];
            foreach($events as $event) {
                $receipt=app(\App\Services\StudentCompletion\CompletionReceipts::class)->find('exam_correction',$event['request_id']);
                $before=json_decode($event['before_values'],true,flags:JSON_THROW_ON_ERROR);$after=json_decode($event['after_values'],true,flags:JSON_THROW_ON_ERROR);
                if(!$receipt||$receipt['actor_id']!==(int)$event['actor_user_id']||!hash_equals($receipt['value']['digest'],$event['request_hash'])
                    ||!hash_equals(CompletionData::hash($before),CompletionData::hash($receipt['value']['before']))||!hash_equals(CompletionData::hash($after),CompletionData::hash($receipt['value']['after']))) {$chain=[];break;}
                $chain[]=['before'=>$before,'after'=>$after];
            }
            if($chain) {
                // Current academic data must also equal the latest recorded settlement, not retained stale components.
                $r=\App\Models\StudentCourseRegistration::find($registration);
                try{if(!$r||!\App\Support\HistoricalOutcomeGuard::preservesOutcome($r))$chain=[];}catch(\Throwable){$chain=[];}
            }
            $chains[$registration]=$chain;
        }
        $tracked=$expected;$used=false;
        foreach($chains[$registration] as $event) {
            if($table==='student_course_registrations') {$before=$event['before']['registration_full'];$after=$event['after']['registration_full'];}
            else {
                $key=$table==='student_course_results'?'results':'marks';
                $before=array_column($event['before']['snapshot'][$key],null,self::KEYS[$table])[$id]??null;
                $after=array_column($event['after']['snapshot'][$key],null,self::KEYS[$table])[$id]??null;
            }
            if($before&&$after&&hash_equals($tracked,CompletionData::hash($before))) {$tracked=CompletionData::hash($after);$used=true;}
        }
        return $used&&hash_equals($tracked,CompletionData::hash($current));
    }
}
