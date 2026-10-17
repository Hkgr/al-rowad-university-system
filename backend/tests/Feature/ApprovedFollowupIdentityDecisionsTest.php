<?php

namespace Tests\Feature;

use App\Services\CatalogCompletion\FollowupCourseIdentity;
use App\Services\StudentCompletion\CompletionData;
use PHPUnit\Framework\TestCase;

final class ApprovedFollowupIdentityDecisionsTest extends TestCase
{
    private function input(string $revision=FollowupCourseIdentity::APPROVED_REVISION): array
    {
        $programs=[];$sources=[];
        foreach(['engineering_civil'=>830,'engineering_survey'=>859,'engineering_drawing'=>802] as $key=>$life) {
            $subjects=[$life=>['RUS111','مدخل إلى الحياة الجامعية',2]];
            if($key==='engineering_civil')$subjects += [838=>['RUS211','أصول البحث العلمي',2],839=>['CE213','منشأ بيتون مسلح (1)',3],847=>['CE223','ميكانيك الإنشاءات (2)',3]];
            $rows=[];foreach($subjects as $n=>[$code,$name,$credits]) {
                $s=['record_id'=>'exam1:DBMater:'.$n,'program_key'=>$key,'source_code'=>$code,'course_name'=>$name,'credits'=>$credits,'prerequisite_source'=>null];$sources[]=$s;
                $rows[]=['record_id'=>$s['record_id'],'source_hash'=>CompletionData::hash($s),'system_code'=>$code,'course_name'=>$name,'credit_hours'=>$credits,'theoretical_hours'=>null,'practical_hours'=>null,
                    'requirement_scope'=>str_starts_with($code,'RUS')?'university':'college','course_type'=>str_starts_with($code,'RUS')?'elective':'mandatory','academic_level_id'=>2,'recommended_semester_id'=>$n===847?2:1,
                    'prerequisite_codes'=>['WRONG'],'existing_prerequisites'=>[]];
            }
            $programs[]=['key'=>$key,'college_code'=>'INST-ENG','department_id'=>43+count($programs),'courses'=>$rows,'blockers'=>['unrelated_evidence_blocker']];
        }
        $origins=[];foreach([409=>['RUS211','مدخل إلى الحياة الجامعية',2],778=>['CE213','مواد البناء',3],860=>['CE223','ميكانيك الموائع1 (هيدروليك1)',3]] as $id=>[$code,$name,$credits])
            $origins[]=['course_id'=>$id,'course_code'=>$code,'course_name'=>$name,'credit_hours'=>$credits,'theoretical_hours'=>2,'practical_hours'=>0,'is_active'=>true];
        return [['identity_mapping_revision'=>$revision,'programs'=>$programs,'approvals'=>[],'amendments'=>[]],
            ['courses'=>$origins,'course_prerequisites'=>[['course_id'=>778,'prerequisite_course_id'=>860,'minimum_result_status_id'=>null]],'course_departments'=>[],
             'academic_programs'=>[['academic_program_id'=>1,'department_id'=>99]],'academic_requirement_groups'=>[['requirement_group_id'=>1,'requirement_scope'=>'university','is_active'=>true]],
             'program_courses'=>[['program_course_id'=>1,'academic_program_id'=>1,'course_id'=>409,'is_active'=>true]],'program_course_requirement_groups'=>[['program_course_id'=>1,'requirement_group_id'=>1]]],
            ['Curriculum'=>$sources]];
    }
    public function test_exact_approved_subjects_reuse_409_and_keep_research_separate_without_mutating_source_or_old_revision(): void
    {
        [$c,$state,$t]=$this->input();$before=CompletionData::hash([$state,$t]);$s=new FollowupCourseIdentity;$r=$s->revise($c,$state,$t);
        foreach($r['programs'] as $p) {
            $life=$p['courses'][0];self::assertSame(409,$life['existing']['course_id']);self::assertSame('RUS211',$life['system_code']);self::assertSame('RUS111',$life['original_source_code']);
            self::assertSame(['unrelated_evidence_blocker'],$p['blockers']);self::assertSame(2,$life['credit_hours']);self::assertSame('VERIFIED_REUSE',$life['identity_audit']['state']);
        }
        $civil=$r['programs'][0]['courses'];self::assertSame(['RUS211','TI-CE-RES211','TI-CE-213','TI-CE-223'],array_column($civil,'system_code'));
        self::assertSame(['RUS111','RUS211','CE213','CE223'],array_column($civil,'original_source_code'));self::assertSame([2,2,3,3],array_column($civil,'credit_hours'));
        foreach($civil as $i=>$row){self::assertSame(CompletionData::hash($t['Curriculum'][$i]),$row['source_hash']);self::assertSame($t['Curriculum'][$i]['record_id'],$row['record_id']);self::assertNull($row['theoretical_hours']);self::assertNull($row['practical_hours']);self::assertSame([],$row['prerequisite_codes']);
            if($i>0){self::assertArrayNotHasKey('existing',$row);self::assertSame(43,$row['identity_department_id']);self::assertNotEmpty($row['identity_audit']['mapping']['approval_reference']);}}
        self::assertSame('university',$civil[1]['requirement_scope']);self::assertSame('elective',$civil[1]['course_type']);self::assertSame([1,1,1,2],array_column($civil,'recommended_semester_id'));
        self::assertSame($before,CompletionData::hash([$state,$t]));self::assertSame(CompletionData::hash($r),CompletionData::hash($s->revise($r,$state,$t)));
        [$old,$state,$t]=$this->input(FollowupCourseIdentity::REVISION);$legacy=$s->revise($old,$state,$t);
        self::assertSame('RUS111',$legacy['programs'][0]['courses'][0]['system_code']);self::assertSame('RUS211',$legacy['programs'][0]['courses'][1]['system_code']);
        self::assertContains('different_code_identity_requires_review:exam1:DBMater:830',$legacy['programs'][0]['blockers']);
    }
    public function test_life_equivalence_requires_exact_target_id_subject_activity_credits_and_university_scope(): void
    {
        foreach(['missing','id','code','name','credits','activity','scope','source'] as $damage) {
            [$c,$state,$t]=$this->input();
            match($damage){'missing'=>$state['courses']=array_slice($state['courses'],1),'id'=>$state['courses'][0]['course_id']=999,'code'=>$state['courses'][0]['course_code']='OTHER',
                'name'=>$state['courses'][0]['course_name']='Different subject','credits'=>$state['courses'][0]['credit_hours']=3,'activity'=>$state['courses'][0]['is_active']=false,
                'scope'=>$state['academic_requirement_groups'][0]['requirement_scope']='college','source'=>$t['Curriculum'][0]['course_name']='Unapproved source'};
            $r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertNotSame(['unrelated_evidence_blocker'],$r['programs'][0]['blockers'],$damage);
        }
        [$c,$state,$t]=$this->input();$c['programs'][0]['key']='not_approved';$r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertSame('RUS111',$r['programs'][0]['courses'][0]['system_code']);
    }
    public function test_additional_institute_targets_are_reused_only_with_verified_subject_and_department_context(): void
    {
        foreach(['TI-CE-RES211'=>[1,2],'TI-CE-213'=>[2,3],'TI-CE-223'=>[3,3]] as $code=>[$index,$hours]) {
            [$c,$state,$t]=$this->input();$state['courses'][]=['course_id'=>999,'course_code'=>$code,'course_name'=>'Unrelated','credit_hours'=>$hours,'is_active'=>true];
            $service=new FollowupCourseIdentity;$r=$service->revise($c,$state,$t);$record=$c['programs'][0]['courses'][$index]['record_id'];self::assertContains('course_identity_unverified:'.$record,$r['programs'][0]['blockers']);
            $state['courses'][3]['course_name']=$c['programs'][0]['courses'][$index]['course_name'];self::assertContains('course_identity_unverified:'.$record,$service->revise($c,$state,$t)['programs'][0]['blockers']);
            $state['course_departments']=[['course_department_id'=>10,'course_id'=>999,'department_id'=>43]];$r=$service->revise($c,$state,$t);self::assertSame(999,$r['programs'][0]['courses'][$index]['existing']['course_id']);
        }
    }
    public function test_prerequisite_source_codes_distinguish_life_from_research_and_never_adopt_colliding_edges(): void
    {
        [$c,$state,$t]=$this->input();$t['Curriculum'][2]['prerequisite_source']='RUS111';$t['Curriculum'][3]['prerequisite_source']='RUS211';
        foreach([2,3] as $i)$c['programs'][0]['courses'][$i]['source_hash']=CompletionData::hash($t['Curriculum'][$i]);
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);$rows=$r['programs'][0]['courses'];
        self::assertSame(['RUS211'],$rows[2]['prerequisite_codes']);self::assertSame(['TI-CE-RES211'],$rows[3]['prerequisite_codes']);self::assertSame([],$rows[2]['existing_prerequisites']);
        self::assertSame(['unrelated_evidence_blocker'],$r['programs'][0]['blockers']);
    }
}
