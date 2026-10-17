<?php

namespace App\Services\StudentCompletion;

use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Support\Facades\{DB,Schema};

/** Fixed batch reads, no SQL from source files and no per-registration query. */
final class CompletionDatabase
{
    public function evidence(array $studentIds,array $programIds): string
    {
        $registrations=DB::table('student_course_registrations')->whereIn('student_id',$studentIds)->pluck('student_course_registration_id')->all();
        $offerings=DB::table('student_course_registrations')->whereIn('student_id',$studentIds)->pluck('course_offering_id')->unique()->all();
        $components=Schema::hasTable('student_grade_components')?DB::table('student_grade_components')->whereIn('student_course_registration_id',$registrations)->pluck('student_grade_component_id')->all():[];
        $progressions=DB::table('student_progression_decisions')->whereIn('student_id',$studentIds)->pluck('student_progression_decision_id')->all();
        $graduations=DB::table('student_graduation_decisions')->whereIn('student_id',$studentIds)->pluck('student_graduation_decision_id')->all();
        $cases=Schema::hasTable('student_course_incomplete_cases')?DB::table('student_course_incomplete_cases')->whereIn('student_id',$studentIds)->pluck('incomplete_case_id')->all():[];
        $data=[];
        foreach (['student_course_registrations','student_course_results','student_grade_components','grade_components','grade_approvals','grade_part_approvals',
            'grade_audit_logs','student_academic_terms','student_progression_decisions','student_progression_events','student_graduation_decisions','student_graduation_events',
            'student_course_incomplete_cases','student_course_incomplete_events','academic_plan_versions','academic_requirement_groups','program_courses','student_academic_plan_assignments'] as $table) {
            if (!Schema::hasTable($table)) {$data[$table]=['unavailable'=>true];continue;}
            $q=DB::table($table);
            if($table==='grade_audit_logs') {
                $relationships=[];
                foreach(['student_course_registration_id'=>$registrations,'student_grade_component_id'=>$components,'student_id'=>$studentIds] as $key=>$values)
                    if(Schema::hasColumn($table,$key))$relationships[$key]=$values;
                if(!$relationships)throw new CompletionException('student_completion_evidence_unavailable','Audit relation unavailable.',503);
                $q->where(function($q)use($relationships){foreach($relationships as $key=>$values)$q->orWhereIn($key,$values);});
            }
            elseif(in_array($table,['student_course_results','student_grade_components'],true))$q->whereIn('student_course_registration_id',$registrations);
            elseif($table==='grade_approvals')$q->whereIn('course_offering_id',$offerings);
            elseif (Schema::hasColumn($table,'student_id')) $q->whereIn('student_id',$studentIds);
            elseif (Schema::hasColumn($table,'student_course_registration_id')) $q->whereIn('student_course_registration_id',$registrations);
            elseif (Schema::hasColumn($table,'course_offering_id')) $q->whereIn('course_offering_id',$offerings);
            elseif (Schema::hasColumn($table,'academic_program_id')) $q->whereIn('academic_program_id',$programIds);
            elseif (Schema::hasColumn($table,'student_grade_component_id')) $q->whereIn('student_grade_component_id',$components);
            elseif (Schema::hasColumn($table,'student_progression_decision_id')) $q->whereIn('student_progression_decision_id',$progressions);
            elseif (Schema::hasColumn($table,'student_graduation_decision_id')) $q->whereIn('student_graduation_decision_id',$graduations);
            elseif (Schema::hasColumn($table,'incomplete_case_id')) $q->whereIn('incomplete_case_id',$cases);
            // Unknown relationship projection must fail closed rather than silently omit evidence.
            else throw new CompletionException('student_completion_evidence_unavailable','Unknown evidence relation: '.$table,503);
            $rows=$q->get()->map(fn($r)=>(array)$r)->all();
            usort($rows,fn($a,$b)=>strcmp(CompletionData::json($a),CompletionData::json($b))); $data[$table]=$rows;
        }
        return CompletionData::hash($data);
    }

    public function read(User $actor,?array $numbers=null): array
    {
        $scope=app(DataScopeService::class);
        $programIds=$scope->scopeProgramsForMutation(\App\Models\AcademicProgram::query(),$actor)->pluck('academic_program_id')->all();
        $query=DB::table('students')->whereIn('academic_program_id',$programIds);
        if ($numbers!==null) $query->whereIn('student_number',$numbers);
        $students=$query->orderBy('student_id')->get(['student_id','student_number','first_name','last_name','father_name',
            'academic_program_id','current_academic_level_id','enrollment_date','student_status_id','deleted_at','updated_at'])->map(fn($r)=>(array)$r)->all();
        $ids=array_column($students,'student_id');
        $registrations=DB::table('student_course_registrations')->whereIn('student_id',$ids)->get(['student_course_registration_id','course_offering_id']);
        $registrationIds=$registrations->pluck('student_course_registration_id')->all();$offeringIds=$registrations->pluck('course_offering_id')->unique()->all();
        $courseIds=DB::table('course_offerings')->whereIn('course_offering_id',$offeringIds)->pluck('course_id')->unique()->all();
        $departmentIds=DB::table('academic_programs')->whereIn('academic_program_id',$programIds)->pluck('department_id')->unique()->all();
        $collegeIds=DB::table('departments')->whereIn('department_id',$departmentIds)->pluck('college_id')->unique()->all();
        $supplementaryIds=DB::table('supplementary_exam_registrations')->whereIn('student_id',$ids)->pluck('supplementary_exam_registration_id')->all();
        $state=[];
        foreach (CompletionSnapshot::TABLES as $table) {
            if (!Schema::hasTable($table)) throw new CompletionException('student_completion_schema_unavailable','Required academic table unavailable: '.$table,503);
            $q=DB::table($table);
            if ($table==='students') {$state[$table]=$students;continue;}
            if($table==='student_course_results')$q->whereIn('student_course_registration_id',$registrationIds);
            elseif($table==='supplementary_exam_materializations')$q->whereIn('supplementary_exam_registration_id',$supplementaryIds);
            elseif($table==='grade_approvals')$q->whereIn('course_offering_id',$offeringIds);
            elseif (Schema::hasColumn($table,'student_id')) $q->whereIn('student_id',$ids);
            elseif (in_array($table,['academic_programs','academic_plan_versions'],true)) $q->whereIn('academic_program_id',$programIds);
            elseif($table==='course_offerings')$q->whereIn('course_offering_id',$offeringIds);
            elseif($table==='courses')$q->whereIn('course_id',$courseIds);
            elseif($table==='departments')$q->whereIn('department_id',$departmentIds);
            elseif($table==='colleges')$q->whereIn('college_id',$collegeIds);
            $state[$table]=$q->get()->map(fn($r)=>(array)$r)->all();
        }
        $state['lifecycle_blockers']=[];
        if (\App\Support\IncompleteOutcomeGuard::installed()) {
            if (!\App\Support\IncompleteOutcomeGuard::ready()) foreach ($ids as $id) $state['lifecycle_blockers'][$id]=['incomplete_schema_unavailable'];
            else \App\Models\StudentCourseRegistration::whereIn('student_id',$ids)->with('courseOffering')->orderBy('student_course_registration_id')->chunkById(100,function($registrations)use(&$state) {
                $evidence=\App\Support\IncompleteOutcomeGuard::readPreservedOutcomes($registrations);
                foreach($registrations as $registration) {
                    $e=$evidence[$registration->getKey()];
                    if($e['state']==='active' || $e['reason']) $state['lifecycle_blockers'][$registration->student_id][]=$e['reason']??'incomplete_active';
                }
            },'student_course_registration_id');
        }
        return $state;
    }
}
