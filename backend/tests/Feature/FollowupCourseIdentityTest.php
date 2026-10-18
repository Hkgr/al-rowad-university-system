<?php

namespace Tests\Feature;

use App\Services\CatalogCompletion\FollowupCourseIdentity;
use App\Services\StudentCompletion\CompletionData;
use PHPUnit\Framework\TestCase;

final class FollowupCourseIdentityTest extends TestCase
{
    private function input(): array
    {
        $rows=[];$sources=[];$origins=[];
        foreach([833=>['CE211','علم السكون – استاتيك',4,'مقاومة المواد (1)',3,856],834=>['CE212','جيولوجيا هندسية',3,'علم التحريك- ديناميك',4,857],846=>['CE222','منشأ بيتون مسلح (2)',3,'تصميم وإنشاء مباني',4,859]] as $n=>$v) {
            [$code,$name,$credits,$oldName,$oldCredits,$id]=$v;$s=['record_id'=>'exam1:DBMater:'.$n,'program_key'=>'engineering_civil','source_code'=>$code,'course_name'=>$name,'credits'=>$credits,'prerequisite_source'=>null];$sources[]=$s;
            $rows[]=['record_id'=>$s['record_id'],'source_hash'=>CompletionData::hash($s),'system_code'=>$code,'course_name'=>$name,'credit_hours'=>$credits,
                'academic_level_id'=>2,'recommended_semester_id'=>$n===846?2:1,'theoretical_hours'=>null,'practical_hours'=>null,'requirement_scope'=>'college','course_type'=>'mandatory',
                'prerequisite_codes'=>['WRONG-INHERITED'],'existing_prerequisites'=>[['course_code'=>'WRONG-INHERITED','minimum_result_status_id'=>null]]];
            $origins[]=['course_id'=>$id,'course_code'=>$code,'course_name'=>$oldName,'credit_hours'=>$oldCredits,'is_active'=>1,'theoretical_hours'=>2,'practical_hours'=>2];
        }
        $config=['identity_mapping_revision'=>FollowupCourseIdentity::REVISION,'approvals'=>[],'amendments'=>[],'programs'=>[['key'=>'engineering_civil','college_code'=>'INST-ENG','department_id'=>43,'courses'=>$rows,'blockers'=>[]]]];
        return [$config,['courses'=>$origins,'course_prerequisites'=>[['course_id'=>856,'prerequisite_course_id'=>857,'minimum_result_status_id'=>null]],'course_departments'=>[],
            'program_courses'=>[],'academic_programs'=>[],'academic_requirement_groups'=>[],'program_course_requirement_groups'=>[]],['Curriculum'=>$sources]];
    }
    public function test_three_exact_collisions_are_new_institute_identities_without_adopting_old_origins_or_prerequisites(): void
    {
        [$c,$state,$tables]=$this->input();$before=CompletionData::hash($state);$r=(new FollowupCourseIdentity)->revise($c,$state,$tables);$rows=$r['programs'][0]['courses'];
        self::assertSame(['TI-CE-211','TI-CE-212','TI-CE-222'],array_column($rows,'system_code'));self::assertSame([4,3,3],array_column($rows,'credit_hours'));
        self::assertSame(['CE211','CE212','CE222'],array_column($rows,'original_source_code'));self::assertSame([1,1,2],array_column($rows,'recommended_semester_id'));
        foreach($rows as $i=>$row){self::assertSame($tables['Curriculum'][$i]['record_id'],$row['record_id']);self::assertSame(CompletionData::hash($tables['Curriculum'][$i]),$row['source_hash']);self::assertArrayNotHasKey('existing',$row);self::assertSame([],$row['prerequisite_codes']);self::assertSame([],$row['existing_prerequisites']);self::assertNotEmpty($row['identity_audit']['mapping']['reason']);}
        self::assertSame($before,CompletionData::hash($state));self::assertSame([],$r['programs'][0]['blockers']);
        self::assertSame(CompletionData::hash($r),CompletionData::hash((new FollowupCourseIdentity)->revise($r,$state,$tables)));
    }
    public function test_proposed_system_code_cannot_adopt_an_unrelated_same_credit_course(): void
    {
        [$c,$state,$t]=$this->input();$state['courses'][]=['course_id'=>999,'course_code'=>'TI-CE-211','course_name'=>'Different subject','credit_hours'=>4,'is_active'=>true];
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertContains('course_identity_unverified:exam1:DBMater:833',$r['programs'][0]['blockers']);
        self::assertSame(999,$r['programs'][0]['courses'][0]['identity_audit']['candidate']['course_id']);self::assertArrayNotHasKey('existing',$r['programs'][0]['courses'][0]);
    }
    public function test_exact_subject_at_a_proposed_code_still_requires_the_intended_department_context(): void
    {
        [$c,$state,$t]=$this->input();$state['courses'][]=['course_id'=>999,'course_code'=>'TI-CE-211','course_name'=>'علم السكون – استاتيك','credit_hours'=>4,'is_active'=>true];
        $s=new FollowupCourseIdentity;self::assertContains('course_identity_unverified:exam1:DBMater:833',$s->revise($c,$state,$t)['programs'][0]['blockers']);
        $state['course_departments']=[['course_department_id'=>10,'course_id'=>999,'department_id'=>43]];
        $r=$s->revise($c,$state,$t);self::assertSame(999,$r['programs'][0]['courses'][0]['existing']['course_id']);self::assertSame([],$r['programs'][0]['blockers']);
    }
    public function test_exact_documented_shared_alias_requires_independent_university_membership_evidence(): void
    {
        [$c,$state,$t]=$this->input();$row=$c['programs'][0]['courses'][0];$row=array_replace($row,['record_id'=>'synthetic:shared','system_code'=>'RU111','course_name'=>'اللغة العربية (1)','credit_hours'=>2,'requirement_scope'=>'university']);
        $t['Curriculum']=[['record_id'=>$row['record_id'],'source_code'=>'RU111','prerequisite_source'=>null]];$row['source_hash']=CompletionData::hash($t['Curriculum'][0]);$c['programs'][0]['courses']=[$row];
        $state['courses'][]=['course_id'=>403,'course_code'=>'RU111','course_name'=>'لغة عربية (1)','credit_hours'=>2,'is_active'=>true];
        $s=new FollowupCourseIdentity;$r=$s->revise($c,$state,$t);self::assertContains('course_identity_unverified:synthetic:shared',$r['programs'][0]['blockers']);
        $state['academic_programs']=[['academic_program_id'=>1,'department_id'=>99]];$state['program_courses']=[['program_course_id'=>1,'academic_program_id'=>1,'course_id'=>403,'is_active'=>1]];
        $state['academic_requirement_groups']=[['requirement_group_id'=>1,'requirement_scope'=>'university','is_active'=>1]];$state['program_course_requirement_groups']=[['program_course_id'=>1,'requirement_group_id'=>1]];
        $r=$s->revise($c,$state,$t);self::assertSame('VERIFIED_REUSE',$r['programs'][0]['courses'][0]['identity_audit']['state']);self::assertSame(403,$r['programs'][0]['courses'][0]['existing']['course_id']);
        self::assertSame([],$r['programs'][0]['blockers']);$state['courses'][3]['course_name']='Arabic methods: unrelated';self::assertContains('course_identity_unverified:synthetic:shared',$s->revise($c,$state,$t)['programs'][0]['blockers']);
    }
    public function test_prerequisites_resolve_by_program_source_identity_not_the_colliding_system_origin(): void
    {
        [$c,$state,$t]=$this->input();$t['Curriculum'][1]['prerequisite_source']='CE211';$c['programs'][0]['courses'][1]['source_hash']=CompletionData::hash($t['Curriculum'][1]);
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertSame(['TI-CE-211'],$r['programs'][0]['courses'][1]['prerequisite_codes']);self::assertSame([],$r['programs'][0]['courses'][0]['prerequisite_codes']);
        $t['Curriculum'][0]['prerequisite_source']='CE212';$r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertContains('source_prerequisite_cycle',$r['programs'][0]['blockers']);
        $t['Curriculum'][1]['prerequisite_source']='NOT-SUPPLIED';$r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertContains('source_prerequisite_identity_unresolved:exam1:DBMater:834',$r['programs'][0]['blockers']);
    }
    public function test_verified_origin_with_unstated_prerequisites_is_blocked_instead_of_inheriting_them(): void
    {
        [$c,$state,$t]=$this->input();$state['courses'][]=['course_id'=>999,'course_code'=>'TI-CE-211','course_name'=>'علم السكون – استاتيك','credit_hours'=>4,'is_active'=>true];
        $state['course_departments']=[['course_department_id'=>10,'course_id'=>999,'department_id'=>43]];
        $state['course_prerequisites'][]=['course_id'=>999,'prerequisite_course_id'=>857,'minimum_result_status_id'=>null];$before=CompletionData::hash($state);
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertContains('source_prerequisite_evidence_missing:exam1:DBMater:833',$r['programs'][0]['blockers']);
        self::assertSame([],$r['programs'][0]['courses'][0]['prerequisite_codes']);self::assertSame($before,CompletionData::hash($state));
    }
    public function test_equal_credit_unapproved_subjects_are_reported_not_automatically_split(): void
    {
        [$c,$state,$t]=$this->input();$t['Curriculum'][0]=array_replace($t['Curriculum'][0],['record_id'=>'exam1:DBMater:839','source_code'=>'CE213','course_name'=>'Source subject','credits'=>3]);
        $c['programs'][0]['courses']=[array_replace($c['programs'][0]['courses'][0],['record_id'=>'exam1:DBMater:839','system_code'=>'CE213','course_name'=>'Source subject','credit_hours'=>3,'source_hash'=>CompletionData::hash($t['Curriculum'][0])])];
        $state['courses'][]=['course_id'=>778,'course_code'=>'CE213','course_name'=>'Different same-credit subject','credit_hours'=>3,'is_active'=>true];
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);self::assertSame('CE213',$r['programs'][0]['courses'][0]['system_code']);self::assertContains('course_identity_unverified:exam1:DBMater:839',$r['programs'][0]['blockers']);
    }
    public function test_different_code_same_subject_candidate_is_not_automatically_merged_or_duplicated(): void
    {
        [$c,$state,$t]=$this->input();$s=['record_id'=>'synthetic:alias','source_code'=>'NEW-CODE','prerequisite_source'=>null];$t['Curriculum']=[$s];
        $c['programs'][0]['courses']=[array_replace($c['programs'][0]['courses'][0],['record_id'=>$s['record_id'],'system_code'=>'NEW-CODE','course_name'=>'Synthetic subject','credit_hours'=>2,'requirement_scope'=>'university','source_hash'=>CompletionData::hash($s)])];
        $state['courses'][]=['course_id'=>409,'course_code'=>'OLD-CODE','course_name'=>'Synthetic subject','credit_hours'=>2,'is_active'=>true];
        $state['academic_programs']=[['academic_program_id'=>1,'department_id'=>99]];$state['program_courses']=[['program_course_id'=>1,'academic_program_id'=>1,'course_id'=>409,'is_active'=>1]];
        $state['academic_requirement_groups']=[['requirement_group_id'=>1,'requirement_scope'=>'university','is_active'=>1]];$state['program_course_requirement_groups']=[['program_course_id'=>1,'requirement_group_id'=>1]];
        $r=(new FollowupCourseIdentity)->revise($c,$state,$t);$row=$r['programs'][0]['courses'][0];self::assertSame('NEW-CODE',$row['system_code']);self::assertArrayNotHasKey('existing',$row);
        self::assertSame('BLOCKED',$row['identity_audit']['state']);self::assertSame('OLD-CODE',$row['identity_audit']['alternate_candidates'][0]['origin']['course_code']);
    }
}
