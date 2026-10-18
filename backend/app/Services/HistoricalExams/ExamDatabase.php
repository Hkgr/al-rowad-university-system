<?php
namespace App\Services\HistoricalExams;
use App\Models\{Course,CourseOffering,Student,User};
use App\Services\DataScopeService;
use App\Services\StudentCompletion\CompletionSnapshot;
use Illuminate\Support\Facades\{DB,Schema};

/** Fixed-table bulk reads, independently scoped students, courses and offerings; no source SQL execution. */
final class ExamDatabase
{
    public function read(User $actor): array
    {
        if(\App\Support\HistoricalExamSchema::installed())\App\Support\HistoricalExamSchema::requireReady();
        $scope=app(DataScopeService::class);
        $columns=array_values(array_intersect(['student_id','student_number','first_name','last_name','father_name','academic_program_id','current_academic_level_id','student_status_id','enrollment_date','deleted_at','updated_at'],Schema::getColumnListing('students')));
        $students=$scope->scopeManualGradeStudents(Student::query(),$actor)->select($columns)->orderBy('student_id')->get()->map->getAttributes()->all();
        $studentIds=array_column($students,'student_id');$courseIds=$scope->scopeManualGradeCourses(Course::query(),$actor)->pluck('course_id');
        $offerings=$scope->scopeManualGradeOfferings(CourseOffering::query(),$actor)->whereIn('course_id',$courseIds)->orderBy('course_offering_id')->get()->map->getAttributes()->all();$offeringIds=array_column($offerings,'course_offering_id');
        $registrations=DB::table('student_course_registrations')->whereIn('student_id',$studentIds)->whereIn('course_offering_id',$offeringIds)->orderBy('student_course_registration_id')->get()->map(fn($r)=>(array)$r)->all();$regIds=array_column($registrations,'student_course_registration_id');
        $state=['students'=>$students,'course_offerings'=>$offerings,'student_course_registrations'=>$registrations];
        foreach(array_unique([...CompletionSnapshot::TABLES,...CompletionSnapshot::FOLLOWUP_TABLES,...CompletionSnapshot::EXAM_TABLES]) as $table) {
            if(isset($state[$table]))continue;
            if(!Schema::hasTable($table)){ $state[$table]=[];continue; }
            $q=DB::table($table);
            if($table==='courses')$q->whereIn('course_id',$courseIds);
            elseif(in_array($table,['student_course_results','student_grade_components','student_course_incomplete_cases','student_course_incomplete_events','student_exam_deferrals','supplementary_exam_registrations'],true)&&Schema::hasColumn($table,'student_course_registration_id'))$q->whereIn('student_course_registration_id',$regIds);
            elseif(in_array($table,['grade_components','grade_approvals','grade_part_approvals'],true))$q->whereIn('course_offering_id',$offeringIds);
            elseif($table==='grade_audit_logs')$q->whereIn('student_grade_component_id',array_column($state['student_grade_components']??[],'student_grade_component_id'));
            elseif($table==='grade_part_approval_events')$q->whereIn('grade_part_approval_id',array_column($state['grade_part_approvals']??[],'grade_part_approval_id'));
            elseif(Schema::hasColumn($table,'student_id'))$q->whereIn('student_id',$studentIds);
            $state[$table]=$q->orderByRaw('1')->get()->map(fn($r)=>(array)$r)->all();
        }
        foreach(array_keys(\App\Support\HistoricalExamSchema::columns()) as $table) {
            if(!Schema::hasTable($table)){$state[$table]=[];continue;}
            $q=DB::table($table);
            if($table===\App\Support\HistoricalExamSchema::WORK)$q->whereIn('student_id',$studentIds)->whereIn('course_id',$courseIds)
                ->where(fn($q)=>$q->whereNull('course_offering_id')->orWhereIn('course_offering_id',$offeringIds));
            elseif(in_array($table,[\App\Support\HistoricalExamSchema::CREDITS,\App\Support\HistoricalExamSchema::OUTCOMES],true))$q->whereIn('student_course_registration_id',$regIds);
            $state[$table]=$q->orderByRaw('1')->get()->map(fn($r)=>(array)$r)->all();
        }
        return $state;
    }
}
