<?php

namespace App\Services\StudentCompletion;

use App\Models\{ApprovalStatus,CourseOffering,GradeApproval,RegistrationStatus,ResultStatus,StudentCourseRegistration,StudentCourseResult};
use App\Services\GradeService;

/** Hydrates already batched/snapshot data for the canonical official-status reader; no queries. */
final class CompletionOfficialEvidence
{
    public function read(array $state): array
    {
        $by=fn($table,$key)=>array_column($state[$table]??[],null,$key);
        $results=$by('student_course_results','student_course_registration_id');$statuses=$by('result_statuses','result_status_id');
        $rs=$by('registration_statuses','registration_status_id');$as=$by('approval_statuses','approval_status_id');$offerings=[];
        foreach($state['course_offerings'] as $o) {
            $model=new CourseOffering;$model->setRawAttributes($o);
            $approvals=[];
            foreach($state['grade_approvals']??[] as $a)if((int)$a['course_offering_id']===(int)$o['course_offering_id']) {
                $approval=new GradeApproval;$approval->setRawAttributes($a);$status=new ApprovalStatus;$status->setRawAttributes($as[$a['approval_status_id']]??[]);
                $approval->setRelation('approvalStatus',$status);$approvals[]=$approval;
            }
            $model->setRelation('gradeApprovals',collect($approvals));$offerings[$o['course_offering_id']]=$model;
        }
        $out=[];$service=app(GradeService::class);
        $readiness=app(\App\Services\CatalogCompletion\CompletionRegistrationReadiness::class);
        foreach($state['student_course_registrations'] as $r) {
            $result=$results[$r['student_course_registration_id']]??null;
            $registration=new StudentCourseRegistration;$registration->setRawAttributes($r);
            $registrationResultStatus=new ResultStatus;$registrationResultStatus->setRawAttributes($statuses[$r['result_status_id']??null]??[]);
            $registration->setRelation('resultStatus',$registrationResultStatus);
            $status=new RegistrationStatus;$status->setRawAttributes($rs[$r['registration_status_id']]??[]);
            $resultModel=new StudentCourseResult;$resultModel->setRawAttributes($result??[]);$resultStatus=new ResultStatus;$resultStatus->setRawAttributes($statuses[$result['result_status_id']??null]??[]);
            $resultModel->setRelation('resultStatus',$resultStatus);$registration->setRelation('registrationStatus',$status);
            $registration->setRelation('studentCourseResult',$result?$resultModel:null);$offering=$offerings[$r['course_offering_id']]??null;
            $registration->setRelation('courseOffering',$offering);
            $official=$service->officialAttemptResultStatus($registration);
            $out[$r['student_course_registration_id']]=['status'=>$official,'result_id'=>$result['student_course_result_id']??null,
                'registration_id'=>$r['student_course_registration_id'],'offering_id'=>$r['course_offering_id'],
                'approval_id'=>$offering?->gradeApprovals->sortByDesc('grade_approval_id')->first()?->grade_approval_id,
                'work_blocker'=>$readiness->historicalOutcomeReason($registration)];
        }
        return $out;
    }
}
