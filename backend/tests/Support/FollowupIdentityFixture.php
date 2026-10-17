<?php

namespace Tests\Support;

use App\Services\CatalogCompletion\{ApprovedFollowupCatalog,FollowupCourseIdentity};
use App\Services\StudentCompletion\CompletionData;
use Illuminate\Support\Facades\DB;

/** Synthetic four-program publication through the real revised manifest compiler. */
final class FollowupIdentityFixture
{
    public static function bundle(array $departments,array $colleges,string $prefix='SYN-ID',bool $approved=false): array
    {
        $tables=['Programs'=>[],'Curriculum'=>[],'CourseMappings'=>[],'SourceRows'=>[]];$programs=[];
        foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
            $courses=[];
            $subjects=$i===0?[833=>['CE211','علم السكون – استاتيك',4],834=>['CE212','جيولوجيا هندسية',3],846=>['CE222','منشأ بيتون مسلح (2)',3]]:[1000+$i=>[$prefix.'-SHARED','Synthetic shared subject',3]];
            if($approved&&$i===0)$subjects += [838=>['RUS211','أصول البحث العلمي',2],839=>['CE213','منشأ بيتون مسلح (1)',3],847=>['CE223','ميكانيك الإنشاءات (2)',3]];
            if($approved&&$i<3)$subjects[[830,859,802][$i]]=['RUS111','مدخل إلى الحياة الجامعية',2];
            foreach($subjects as $n=>[$code,$name,$credits]) {
                $s=['record_id'=>$i===0||$code==='RUS111'?'exam1:DBMater:'.$n:'synthetic:'.$n,'program_key'=>$key,'source_code'=>$code,'course_name'=>$name,'credits'=>$credits,'prerequisite_source'=>null,'row_kind'=>'COURSE'];$tables['Curriculum'][]=$s;
                $courses[]=['record_id'=>$s['record_id'],'source_hash'=>CompletionData::hash($s),'system_code'=>$code,'course_name'=>$name,'credit_hours'=>$credits,'theoretical_hours'=>null,'practical_hours'=>null,
                    'academic_level_id'=>1,'recommended_semester_id'=>1,'requirement_scope'=>str_starts_with($code,'RUS')?'university':'college','course_type'=>str_starts_with($code,'RUS')?'elective':'mandatory','prerequisite_codes'=>[],'existing_prerequisites'=>[]];
            }
            $hours=array_sum(array_column($courses,'credit_hours'));$groups=[];foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)$groups[]=['requirement_scope'=>$scope,'requirement_type'=>$type,
                'required_credit_hours'=>array_sum(array_column(array_filter($courses,fn($r)=>$r['requirement_scope']===$scope&&$r['course_type']===$type),'credit_hours')),'is_active'=>true];
            $p=['key'=>$key,'college_code'=>$colleges[$i],'department_id'=>$departments[$i],'program_code'=>$prefix.'-P'.$i,'program_name'=>'Synthetic identity '.$i,'degree_level'=>'Diploma','duration_years'=>2,
                'total_credit_hours'=>$hours,'requirements'=>['total_credit_hours'=>$hours,'groups'=>$groups],'courses'=>$courses,'blockers'=>[]];
            if($i===3)foreach(range(1,4) as $n) {
                $e=['record_id'=>'synthetic:elective:'.$n,'values_json'=>json_encode(['B'=>'Synthetic elective '.$n])];$tables['SourceRows'][]=$e;
                $p['elective_alternatives'][]=['record_id'=>$e['record_id'],'source_hash'=>CompletionData::hash($e),'system_code'=>'ESI-UE-0'.$n,'course_name'=>'Synthetic elective '.$n,'credit_hours'=>2,
                    'theoretical_hours'=>null,'practical_hours'=>null,'academic_level_id'=>null,'recommended_semester_id'=>null,'requirement_scope'=>'university','course_type'=>'elective',
                    'prerequisite_codes'=>[],'existing_prerequisites'=>[],'approval_reference'=>['decision_id'=>'synthetic-explicit','approved_at'=>'2026-10-18']];
            }
            $programs[]=$p;
        }
        $state=[];foreach(['courses','course_departments','course_prerequisites','program_courses','academic_programs','academic_requirement_groups','program_course_requirement_groups'] as $t)$state[$t]=DB::table($t)->get()->map(fn($r)=>(array)$r)->all();
        $config=['identity_mapping_revision'=>$approved?FollowupCourseIdentity::APPROVED_REVISION:FollowupCourseIdentity::REVISION,'programs'=>$programs,'approvals'=>[],'amendments'=>[],'table_hashes'=>array_map(CompletionData::hash(...),$tables)];
        $config=(new FollowupCourseIdentity)->revise($config,$state,$tables);$b=['contract'=>$approved?'student-completion.sources.v6':'student-completion.sources.v5','sources'=>[],'snapshot'=>$state,'config'=>['followup'=>['catalog'=>$config]]];
        $b['catalog_followup']=(new ApprovedFollowupCatalog)->compile($b,$tables);return $b;
    }
}
