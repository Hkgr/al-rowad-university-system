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
        'grade_audit_logs'=>'grade_audit_log_id','grade_part_approval_events'=>'grade_part_approval_event_id','academic_plan_events'=>'academic_plan_event_id'];
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
        foreach($expected as $table=>$hashes) {
            $rows=array_column($state[$table]??[],null,self::KEYS[$table]);
            foreach($hashes as $id=>$hash)if(!isset($rows[$id])||!hash_equals($hash,CompletionData::hash($rows[$id])))return false;
        }return true;
    }
}
