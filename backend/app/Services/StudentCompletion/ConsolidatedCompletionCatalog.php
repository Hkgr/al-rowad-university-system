<?php

namespace App\Services\StudentCompletion;

/** The academic amendments explicitly approved for PR #174, not inferred workbook instructions. */
final class ConsolidatedCompletionCatalog
{
    private const PROGRAMS=['engineering_civil'=>['INST-ENG','RU1-TI-CE',85,4,69], 'engineering_survey'=>['INST-ENG','RU1-TI-E',80,2,66],
        'engineering_drawing'=>['INST-ENG','RU1-TI-AE',78,2,64], 'environment'=>['INST-CD','RU1-CDI-ESI',74,4,58]];
    public function aliases(): array
    {
        return ['إدارة الأعمال'=>'RU2-FMF-MAN','إرشاد نفسي'=>'RU-FED-PSC','الإرشاد النفسي'=>'RU-FED-PSC','الإعلام والاتصال'=>'RU2-FM','الاعلام والاتصال'=>'RU2-FM',
            'الاقتصاد'=>'RU2-FMF-ECO','الاقتصاد والتنمية'=>'RU2-FMF-ECO','التدريب الرياضي'=>'RU2-ES-TS','التربية البدنية والرياضية'=>'RU2-ES-TS',
            'التربية الخاصة'=>'RU-FED-SPB','الحقوق'=>'RU2-FL','المحاسبة والتمويل'=>'RU2-FMF-ACC','المعهد التقاني للعلوم الإدارية والمالية|إدارة الأعمال'=>'RU1-TIF-TIFM',
            'المعهد التقاني للعلوم الإدارية والمالية|المحاسبة والتمويل'=>'RU1-TIF-TIFA','الهندسة المدنية والمشروعات العامة'=>'RU2-FBER-CE','رياض الأطفال'=>'RU2-FED-KIN',
            'معلم الصف'=>'RU2-FED-TCH','معلم صف'=>'RU2-FED-TCH','هندسة البرمجيات'=>'RU2-FIE-BR','هندسة الحواسيب والذكاء الصنعي'=>'RU-FIE-CEAI','برمجيات'=>'RU2-FIE-BR','الإعلام'=>'RU2-FM'];
    }
    public function collegeAliases(): array
    {
        return ['العلوم الإدارية والمالية'=>'FMF','العلوم الادارية والمالية'=>'FMF','كلية العلوم الادارية والمالية'=>'FMF','كلية العلوم الإدارية والمالية'=>'FMF',
            'المعهد التقاني للعلوم الادارية'=>'INST-AF','المعهد التقاني للعلوم الإدارية والمالية'=>'INST-AF',
            'هندسة البناء وإعادة الإعمار'=>'FBER','كلية هندسة البناء وإعادة الإعمار'=>'FBER','الهندسة المعلوماتية وتقاناتها'=>'FIE',
            'التربية والتنمية المجتمعية'=>'FED','الحقوق'=>'FL','التربية البدنية والرياضية'=>'ES','كلية التربية البدنية والرياضية'=>'ES'];
    }
    private function departmentName(string $s): string {return str_replace(['قسم','العمارة والرسم الهندسي'],['','المدني والرسم الهندسي'],CompletionData::text($s));}
    public function configure(array &$bundle,array $tables,array $evidence,?string $identityRevision=null): array
    {
        $state=$bundle['snapshot'];$profiles=[];$amendments=[];$evidence=array_values(array_filter($evidence,fn($r)=>$r['source_id']==='environment'&&$r['source_sheet']==='RU- CDI- ESI    '&&in_array($r['source_row'],[43,44,45,46],true)));
        $ref=fn($id,$description)=>['decision_id'=>$id,'authority'=>'USER_EXPLICIT_CHAT_APPROVAL','approved_at'=>'2026-10-17','decision'=>$description];
        $approvals=[$ref('environment-two-credit-courses','Only ESI112 and ESI114 become two credits.'),$ref('environment-project-code','Graduation project is ESI228, three credits; ESI224 remains social projects.'),
            $ref('six-explicit-budgets','12 university mandatory each; electives 4/2/2/4; institute mandatory 69/66/64/58; other groups explicitly zero; totals 85/80/78/74.'),
            $ref('environment-elective-alternatives','Four extracted alternatives, ESI-UE-01..04, two credits each; required budget stays four.')];
        $origins=array_column($state['courses'],null,'course_code');$originIds=array_column($state['courses'],null,'course_id');
        $levels=array_column($state['academic_levels'],'academic_level_id','level_order');$semesters=array_column($state['semesters'],'semester_id','semester_order');
        foreach($tables['Programs'] as $s) {
            $key=$s['program_key'];if(!isset(self::PROGRAMS[$key]))throw new \InvalidArgumentException('Program scope is not approved.');
            [$unit,$code,$total,$elective,$institute]=self::PROGRAMS[$key];$colleges=array_column(array_filter($state['colleges'],fn($c)=>$c['college_code']===$unit),null,'college_id');
            $names=array_map($this->departmentName(...),[$s['approved_program_label'],$s['source_program_label']]);
            $departments=array_values(array_filter($state['departments'],fn($d)=>isset($colleges[$d['college_id']])&&in_array(trim($this->departmentName($d['department_name'])),array_map('trim',$names),true)));
            if(count($departments)!==1)throw new \InvalidArgumentException('Approved department missing or ambiguous: '.$key);
            $d=$departments[0];$existing=array_values(array_filter($state['academic_programs'],fn($p)=>(int)$p['department_id']===(int)$d['department_id']));
            $p=['key'=>$key,'department_id'=>(int)$d['department_id'],'department_identity'=>array_intersect_key($d,array_flip(['department_code','department_name'])),
                'expected_program'=>count($existing)===1?array_intersect_key($existing[0],array_flip(['academic_program_id','program_code','program_name'])):null,
                'college_code'=>$unit,'program_code'=>$code,'program_name'=>$s['approved_program_label'],'degree_level'=>'دبلوم تقاني','duration_years'=>2,'total_credit_hours'=>$total,
                'courses'=>[],'requirements'=>['total_credit_hours'=>$total,'groups'=>[]],'blockers'=>[]];
            foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)$p['requirements']['groups'][]=['requirement_scope'=>$scope,'requirement_type'=>$type,
                'required_credit_hours'=>$scope==='university'?($type==='mandatory'?12:$elective):($scope==='college'&&$type==='mandatory'?$institute:0),'is_active'=>true];
            foreach($tables['Curriculum'] as $r)if($r['program_key']===$key&&$r['row_kind']!=='CHOICE_SLOT') {
                $original=CompletionData::text($r['source_code']);$system=$original;$credits=(int)$r['credits'];$term=(int)$r['plan_term'];
                if(in_array($key,['engineering_civil','engineering_survey'],true)&&in_array($original,['TI112','TI113'],true))$system=($key==='engineering_civil'?'TI-CE-':'TI-E-').substr($original,2);
                if($key==='environment'&&in_array($original,['ESI112','ESI114'],true))$credits=2;
                if($key==='environment'&&$r['record_id']==='environment:الخطة الإرشادية:26:G')$system='ESI228';
                if($system!==$original||$credits!==(int)$r['credits'])$amendments[$r['record_id']]=['before_code'=>$original,'before_credits'=>(int)$r['credits'],'system_code'=>$system,'credit_hours'=>$credits,
                    'approval_reference'=>$system==='ESI228'?$approvals[1]:($credits!==(int)$r['credits']?$approvals[0]:$ref('engineering-distinct-codes','Explicit program/subject-qualified TI112 and TI113 identities.'))];
                if(!isset($levels[(int)ceil($term/2)],$semesters[$term%2?1:2])||$term<1||$term>4)throw new \InvalidArgumentException('Advisory placement context unavailable.');
                $row=['record_id'=>$r['record_id'],'source_hash'=>CompletionData::hash($r),'system_code'=>$system,'course_name'=>CompletionData::text($r['course_name']),'credit_hours'=>$credits,
                    'theoretical_hours'=>$r['theoretical_hours'],'practical_hours'=>$r['practical_hours'],'requirement_scope'=>str_starts_with($system,'RU')?'university':'college',
                    'course_type'=>str_starts_with($system,'RUS')?'elective':'mandatory','academic_level_id'=>(int)$levels[(int)ceil($term/2)],'recommended_semester_id'=>(int)$semesters[$term%2?1:2],
                    'prerequisite_codes'=>[],'existing_prerequisites'=>[]];
                if($identityRevision===null&&isset($origins[$system])) {
                    $origin=$origins[$system];$model=new \App\Models\Course;$model->setRawAttributes($origin);$row['existing']=$model->only(['course_id','course_code','course_name','credit_hours','theoretical_hours','practical_hours','is_active']);
                    foreach($state['course_prerequisites'] as $edge)if((int)$edge['course_id']===(int)$origin['course_id'])$row['existing_prerequisites'][]=['course_code'=>$originIds[$edge['prerequisite_course_id']]['course_code'],'minimum_result_status_id'=>$edge['minimum_result_status_id']===null?null:(int)$edge['minimum_result_status_id']];
                    usort($row['existing_prerequisites'],fn($a,$b)=>strcmp($a['course_code'],$b['course_code']));$row['prerequisite_codes']=array_column($row['existing_prerequisites'],'course_code');
                } elseif($identityRevision===null&&CompletionData::text($r['prerequisite_source'])!=='')$p['blockers'][]='source_prerequisite_requires_explicit_resolution:'.$r['record_id'];
                $p['courses'][]=$row;
            }
            if($key==='environment')foreach($evidence as $i=>$r) {
                $values=json_decode($r['values_json'],true,flags:JSON_THROW_ON_ERROR);
                $p['elective_alternatives'][]=['record_id'=>$r['record_id'],'source_hash'=>CompletionData::hash($r),'system_code'=>'ESI-UE-0'.($i+1),'course_name'=>$values['B'],'credit_hours'=>2,
                    'theoretical_hours'=>null,'practical_hours'=>null,'requirement_scope'=>'university','course_type'=>'elective','academic_level_id'=>null,'recommended_semester_id'=>null,
                    'prerequisite_codes'=>[],'existing_prerequisites'=>[],'approval_reference'=>$approvals[3]];
            }
            $profiles[]=$p;
            foreach([$s['source_program_label'],$s['approved_program_label']] as $label){$bundle['config']['program_aliases'][$label]=$code;$bundle['config']['program_aliases'][$s['college_or_institute'].'|'.$label]=$code;}
        }
        $config=['table_hashes'=>array_map(CompletionData::hash(...),array_intersect_key($tables,array_flip(['Programs','Curriculum','CourseMappings'])))+['SourceRows'=>CompletionData::hash($evidence)],
            'programs'=>$profiles,'amendments'=>$amendments,'approvals'=>$approvals,'evidence_record_ids'=>array_column($evidence,'record_id'),'evidence'=>$evidence];
        if($identityRevision!==null){$config['identity_mapping_revision']=$identityRevision;$config=(new \App\Services\CatalogCompletion\FollowupCourseIdentity)->revise($config,$state,$tables);}
        return $config;
    }
}
