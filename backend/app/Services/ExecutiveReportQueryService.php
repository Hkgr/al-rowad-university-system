<?php

namespace App\Services;

use App\Models\StudentCourseResult;
use App\Support\ExecutiveReportRegistry;
use App\Support\OfficialGradeScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only coordinator. Every selectable SQL fragment comes from ExecutiveReportRegistry. */
final class ExecutiveReportQueryService
{
    public function __construct(private GradeService $grades) {}

    public function run(array $input): array
    {
        $this->validateContext($input);
        $this->validateSelections($input);
        $this->validateSort($input);
        $subject=$input['subject']; $metrics=$input['metrics']; $dimensions=$input['dimensions']??[];
        if($reason=$this->historyUnavailableReason($input))return[
            'report'=>['subject'=>$subject,'mode'=>$input['mode'],'label'=>ExecutiveReportRegistry::subject($subject)['label']],
            'scope'=>$this->safeScope($input['filters']??[]),'period'=>$input['period'],'grouping'=>['dimensions'=>$dimensions,'groups_may_overlap'=>false],
            'metrics'=>collect($metrics)->map(fn($code)=>ExecutiveReportRegistry::metric($code))->all(),'summary'=>[],'series'=>[],'rows'=>[],'comparison'=>null,
            'history_availability'=>['available'=>false,'reason'=>$reason,'data'=>[]],'pagination'=>['current_page'=>1,'per_page'=>(int)($input['per_page']??25),'total'=>0,'last_page'=>1],'generated_at'=>CarbonImmutable::now('UTC')->toIso8601String(),
        ];
        $result=$this->execute($subject,$metrics,$dimensions,$input);
        $comparison=$this->comparison($input,$metrics,$dimensions);
        return [
            'report'=>['subject'=>$subject,'mode'=>$input['mode'],'label'=>ExecutiveReportRegistry::subject($subject)['label']],
            'scope'=>$this->safeScope($input['filters']??[]), 'period'=>$input['period']??null,
            'grouping'=>['dimensions'=>$dimensions,'groups_may_overlap'=>(bool)(ExecutiveReportRegistry::subject($subject)['groups_may_overlap']??false)],
            'metrics'=>collect($metrics)->map(fn($code)=>ExecutiveReportRegistry::metric($code))->all(),
            'summary'=>$result['summary'], 'series'=>$result['series'], 'rows'=>$result['rows'],
            'comparison'=>$comparison, 'history_availability'=>$this->history($subject,$input),
            'pagination'=>$result['pagination'], 'generated_at'=>CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    public function overview(): array
    {
        $years=DB::table('academic_years')->where('is_current',1)->where('is_active',1)->where('calendar_lifecycle_status','active')->get();
        $students=(int)DB::table('students')->whereNull('deleted_at')->count();
        $faculty=(int)DB::table('faculty_members')->where('is_active',1)->count();
        if ($years->count()!==1) return ['current_snapshots'=>['students'=>$students,'active_faculty'=>$faculty],'academic_sections'=>['available'=>false,'reason'=>'current_academic_year_unavailable'],'generated_at'=>CarbonImmutable::now('UTC')->toIso8601String()];
        $year=$years->first();
        $registered=(int)DB::table('student_course_registrations as oscr')->join('registration_statuses as ors','ors.registration_status_id','=','oscr.registration_status_id')->join('course_offerings as oco','oco.course_offering_id','=','oscr.course_offering_id')->where('oco.academic_year_id',$year->academic_year_id)->where('ors.status_code','registered')->distinct()->count('oscr.student_id');
        $official=(int)$this->grades->scopeOfficialApprovedResults(StudentCourseResult::query())
            ->whereHas('studentCourseRegistration',fn($q)=>$q
                ->whereHas('registrationStatus',fn($status)=>$status->whereIn('status_code',['registered','completed']))
                ->whereHas('courseOffering',fn($offering)=>$offering->where('academic_year_id',$year->academic_year_id)))
            ->count();
        return ['current_snapshots'=>['students'=>$students,'active_faculty'=>$faculty], 'academic_sections'=>[
            'available'=>true,'academic_year_id'=>(int)$year->academic_year_id,
            'semester_ids'=>DB::table('semesters')->where('is_active',1)->orderBy('semester_order')->pluck('semester_id')->map(fn($id)=>(int)$id)->all(),
            'offerings'=>(int)DB::table('course_offerings')->where('academic_year_id',$year->academic_year_id)->count(),
            'registered_students'=>$registered,'official_results'=>$official,
        ],'generated_at'=>CarbonImmutable::now('UTC')->toIso8601String()];
    }

    private function execute(string $subject,array $metrics,array $dimensions,array $input): array
    {
        [$summaryFacts]=$this->facts($subject,$input);
        $sqlMetrics=array_values(array_diff($metrics,['official_gpa']));
        foreach($sqlMetrics as $metric)$summaryFacts->addSelect(DB::raw($this->metricSql($subject,$metric,$input)." as {$metric}"));
        foreach($this->rateDependencies($metrics) as $metric)$summaryFacts->addSelect(DB::raw($this->metricSql($subject,$metric,$input)." as {$metric}"));
        if($sqlMetrics===[]&&$this->rateDependencies($metrics)===[])$summaryFacts->addSelect(DB::raw('COUNT(*) as __gpa_rows'));
        $summaryRecord=(array)($summaryFacts->first()??new \stdClass());
        $summary=[];
        $gpa=in_array('official_gpa',$metrics,true)?$this->officialGpa($subject,$input,$dimensions):['summary'=>['value'=>null,'contributing_students'=>0],'groups'=>[]];
        foreach($metrics as $metric)$summary[$metric]=$metric==='official_gpa'?$gpa['summary']:$this->normalizeMetric($metric,$summaryRecord[$metric]??null,$summaryRecord);
        if($input['mode']==='details'){
            $details=$this->detailRows($subject,$input);
            return['summary'=>$summary,'series'=>[],'rows'=>$details['rows'],'pagination'=>$details['pagination']];
        }

        [$facts]=$this->facts($subject,$input);
        $dimensionMap=$this->dimensionMap($subject,$input);
        $selected=[];
        foreach($dimensions as $dimension){$column=$dimensionMap[$dimension]??null;if(!$column)continue;$facts->addSelect(DB::raw("{$column} as {$dimension}"));$facts->groupBy(DB::raw($column));$selected[]=$dimension;}
        foreach($sqlMetrics as $metric)$facts->addSelect(DB::raw($this->metricSql($subject,$metric,$input)." as {$metric}"));
        foreach($this->rateDependencies($metrics) as $metric)$facts->addSelect(DB::raw($this->metricSql($subject,$metric,$input)." as {$metric}"));
        if($sqlMetrics===[]&&$this->rateDependencies($metrics)===[])$facts->addSelect(DB::raw('COUNT(*) as __gpa_rows'));
        if($selected===[])$facts->addSelect(DB::raw('1 as aggregate_key'));
        $sort=$input['sort']??null;$allowed=array_merge($selected,$metrics);
        if($sort&&(!in_array($sort['field'],$allowed,true)||$sort['field']==='official_gpa'))throw ValidationException::withMessages(['sort'=>'Sort field is not an allowlisted SQL-sortable selected output.']);
        $this->applyAggregateSort($facts,$subject,$input,$selected,$sqlMetrics);
        $dependencies=$this->rateDependencies($metrics);
        $normalize=function($row)use($metrics,$dimensions,$gpa,$dependencies){$item=(array)$row;foreach($metrics as $metric)$item[$metric]=$metric==='official_gpa'?($dimensions===[]?$gpa['summary']:($gpa['groups'][$this->groupKey($item,$dimensions)]??['value'=>null,'contributing_students'=>0])):$this->normalizeMetric($metric,$item[$metric]??null,$item);foreach($dependencies as $dependency)if(!in_array($dependency,$metrics,true))unset($item[$dependency]);unset($item['aggregate_key'],$item['__gpa_rows']);return$item;};
        $series=[];
        if($selected!==[]){$seriesRows=(clone$facts)->limit(ExecutiveReportRegistry::LIMITS['points']+1)->get();if($seriesRows->count()>ExecutiveReportRegistry::LIMITS['points'])throw ValidationException::withMessages(['dimensions'=>'The grouped report exceeds the 500-point safety limit.']);$series=$seriesRows->map($normalize)->all();}
        $page=$facts->paginate((int)($input['per_page']??25),['*'],'page',(int)($input['page']??1));
        $rows=collect($page->items())->map($normalize)->all();
        return ['summary'=>$summary,'series'=>$series, 'rows'=>$rows,
            'pagination'=>['current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'last_page'=>$page->lastPage()]];
    }

    private function facts(string $subject,array $input): array
    {
        return match($subject){
            'students'=>$this->studentFacts($input), 'enrollments'=>$this->enrollmentFacts($input),
            'course_offerings'=>$this->offeringFacts($input), 'academic_performance'=>$this->performanceFacts($input),
            'faculty'=>$this->facultyFacts($input), 'grade_workflow'=>$this->workflowFacts($input),
        };
    }

    private function baseOfferingJoins(Builder $q,string $offering='co'): void
    {
        $q->leftJoin('academic_programs as ap','ap.academic_program_id','=',"{$offering}.academic_program_id")
            ->leftJoin('departments as d','d.department_id','=','ap.department_id')
            ->leftJoin('colleges as c','c.college_id','=','d.college_id')
            ->join('academic_years as ay','ay.academic_year_id','=',"{$offering}.academic_year_id")
            ->join('semesters as sem','sem.semester_id','=',"{$offering}.semester_id")
            ->join('courses as crs','crs.course_id','=',"{$offering}.course_id");
    }

    private function studentFacts(array $input): array
    {
        $period=$input['period']??null;$filters=$input['filters']??[];
        $membership=DB::table('student_course_registrations as mscr')->join('registration_statuses as mrs','mrs.registration_status_id','=','mscr.registration_status_id')->join('course_offerings as mco','mco.course_offering_id','=','mscr.course_offering_id')->whereIn('mrs.status_code',['registered','completed'])->select('mscr.student_id','mco.academic_year_id','mco.semester_id')->distinct();
        if(($period['type']??null)==='academic')$membership->whereIn('mco.academic_year_id',$period['academic_year_ids'])->when(!empty($period['semester_ids']),fn($q)=>$q->whereIn('mco.semester_id',$period['semester_ids']));
        if(!empty($filters['academic_year_ids']))$membership->whereIn('mco.academic_year_id',$filters['academic_year_ids']);
        if(!empty($filters['semester_ids']))$membership->whereIn('mco.semester_id',$filters['semester_ids']);
        $official=$this->grades->scopeOfficialApprovedResults(StudentCourseResult::query())
            ->join('student_course_registrations as oscr','oscr.student_course_registration_id','=','student_course_results.student_course_registration_id')
            ->join('registration_statuses as ors','ors.registration_status_id','=','oscr.registration_status_id')->whereIn('ors.status_code',['registered','completed'])
            ->join('result_statuses as orst','orst.result_status_id','=','student_course_results.result_status_id')
            ->join('course_offerings as oco','oco.course_offering_id','=','oscr.course_offering_id')->join('courses as ocrs','ocrs.course_id','=','oco.course_id')
            ->select('oscr.student_id','student_course_results.student_course_result_id','student_course_results.final_mark','orst.status_code as result_status_code','ocrs.credit_hours','oco.academic_year_id','oco.semester_id');
        if(($period['type']??null)==='academic')$official->whereIn('oco.academic_year_id',$period['academic_year_ids'])->when(!empty($period['semester_ids']),fn($q)=>$q->whereIn('oco.semester_id',$period['semester_ids']));
        if(!empty($filters['academic_year_ids']))$official->whereIn('oco.academic_year_id',$filters['academic_year_ids']);
        if(!empty($filters['semester_ids']))$official->whereIn('oco.semester_id',$filters['semester_ids']);
        $newYearIds=array_values(array_unique(array_merge($filters['academic_year_ids']??[],($period['type']??null)==='academic'?($period['academic_year_ids']??[]):[])));
        $newStudents=DB::table('students as ns')->join('academic_years as nay',fn($join)=>$join->on('ns.enrollment_date','>=','nay.start_date')->on('ns.enrollment_date','<=','nay.end_date'))->select('ns.student_id')->distinct();
        if($newYearIds!==[])$newStudents->whereIn('nay.academic_year_id',$newYearIds);else$newStudents->where('nay.is_current',1)->where('nay.is_active',1)->where('nay.calendar_lifecycle_status','active');
        $q=$this->currentStudentBase()->leftJoinSub($membership,'sm','sm.student_id','=','s.student_id')->leftJoinSub($official,'offr',fn($join)=>$join->on('offr.student_id','=','s.student_id')->on('offr.academic_year_id','=','sm.academic_year_id')->on('offr.semester_id','=','sm.semester_id'))->leftJoinSub($newStudents,'news','news.student_id','=','s.student_id');
        if(($period['type']??null)==='academic'||!empty($filters['academic_year_ids'])||!empty($filters['semester_ids']))$q->whereNotNull('sm.student_id');
        $this->studentFilters($q,$input['filters']??[]); return [$q,['student'=>'s.student_id']];
    }
    private function enrollmentFacts(array $input): array { $q=$this->currentStudentBase()->leftJoin('academic_years as ay',fn($j)=>$j->on('s.enrollment_date','>=','ay.start_date')->on('s.enrollment_date','<=','ay.end_date'));$f=$input['filters']??[];$this->studentFilters($q,$f);if(!empty($f['academic_year_ids']))$q->whereIn('ay.academic_year_id',$f['academic_year_ids']);$p=$input['period']??null;if(($p['type']??null)==='date_range')$q->whereBetween('s.enrollment_date',[$p['date_from'],$p['date_to']]);if(($p['type']??null)==='academic')$q->whereIn('ay.academic_year_id',$p['academic_year_ids']);return[$q,[]]; }
    private function currentStudentBase(): Builder { return DB::table('students as s')->whereNull('s.deleted_at')->leftJoin('academic_programs as ap','ap.academic_program_id','=','s.academic_program_id')->leftJoin('departments as d','d.department_id','=','ap.department_id')->leftJoin('colleges as c','c.college_id','=','d.college_id')->leftJoin('academic_levels as al','al.academic_level_id','=','s.current_academic_level_id')->leftJoin('student_statuses as ss','ss.student_status_id','=','s.student_status_id'); }
    private function offeringFacts(array $input): array { $q=DB::table('course_offerings as co');$this->baseOfferingJoins($q);$q->leftJoin('student_course_registrations as scr','scr.course_offering_id','=','co.course_offering_id')->leftJoin('registration_statuses as rs','rs.registration_status_id','=','scr.registration_status_id');$this->offeringFilters($q,$input);if(($input['period']['type']??null)==='date_range')$q->whereBetween('co.created_at',[$input['period']['date_from'].' 00:00:00',$input['period']['date_to'].' 23:59:59']);return[$q,[]]; }
    private function performanceFacts(array $input): array
    {
        $eloquent=$this->grades->scopeOfficialApprovedResults(StudentCourseResult::query());
        $official=$eloquent->select('student_course_results.*');
        $q=DB::query()->fromSub($official,'r')->join('student_course_registrations as scr','scr.student_course_registration_id','=','r.student_course_registration_id')->join('registration_statuses as rs','rs.registration_status_id','=','scr.registration_status_id')->whereIn('rs.status_code',['registered','completed'])->join('students as s','s.student_id','=','scr.student_id')->join('result_statuses as rst','rst.result_status_id','=','r.result_status_id')->join('course_offerings as co','co.course_offering_id','=','scr.course_offering_id');$this->baseOfferingJoins($q);$q->leftJoin('academic_levels as al','al.academic_level_id','=','s.current_academic_level_id');$this->offeringFilters($q,$input);if($v=$input['filters']['academic_level_ids']??[])$q->whereIn('s.current_academic_level_id',$v);if($v=$input['filters']['result_status_codes']??[])$q->whereIn('rst.status_code',$v);return[$q,[]];
    }
    private function facultyFacts(array $input): array
    {
        if (($input['period']['type'] ?? null) === 'date_range') {
            $q=DB::table('teaching_assignment_events as tae')
                ->join('teaching_assignment_requests as tar','tar.teaching_assignment_request_id','=','tae.teaching_assignment_request_id')
                ->join('faculty_members as fm','fm.faculty_member_id','=','tar.faculty_member_id')
                ->join('employees as e','e.employee_id','=','fm.employee_id')
                ->join('course_offerings as co','co.course_offering_id','=','tar.course_offering_id');
            $this->baseOfferingJoins($q);
            $q->whereIn('tae.event_type',['effective_assignment_created','effective_assignment_changed'])
                ->whereBetween('tae.created_at',[$input['period']['date_from'].' 00:00:00',$input['period']['date_to'].' 23:59:59']);
            $this->offeringFilters($q,$input);
            return [$q,[]];
        }
        $primary=DB::table('employees as me')->join('colleges as mc','mc.organizational_unit_id','=','me.organizational_unit_id')->select('me.employee_id','mc.college_id');
        $assigned=DB::table('employee_unit_assignments as mua')->join('colleges as muc','muc.organizational_unit_id','=','mua.organizational_unit_id')->where('mua.is_active',1)->where(fn($x)=>$x->whereNull('mua.end_date')->orWhere('mua.end_date','>=',CarbonImmutable::now('UTC')->toDateString()))->select('mua.employee_id','muc.college_id');
        $membership=$primary->union($assigned);
        $q=DB::table('faculty_members as fm')->join('employees as e','e.employee_id','=','fm.employee_id')->leftJoinSub($membership,'membership','membership.employee_id','=','e.employee_id')->leftJoin('colleges as c','c.college_id','=','membership.college_id')->leftJoin('course_offering_instructors as coi',fn($j)=>$j->on('coi.faculty_member_id','=','fm.faculty_member_id')->where('coi.is_active',1))->leftJoin('course_offerings as co','co.course_offering_id','=','coi.course_offering_id')->leftJoin('academic_years as ay','ay.academic_year_id','=','co.academic_year_id')->leftJoin('semesters as sem','sem.semester_id','=','co.semester_id')->leftJoin('courses as crs','crs.course_id','=','co.course_id')->leftJoin('student_course_registrations as fscr','fscr.course_offering_id','=','co.course_offering_id')->leftJoin('registration_statuses as frs','frs.registration_status_id','=','fscr.registration_status_id');
        $required=DB::table('grade_components')->where('is_required',1)->select('course_offering_id','component_type')->distinct();
        $q->leftJoinSub($required,'gc','gc.course_offering_id','=','co.course_offering_id')->leftJoin('grade_part_approvals as gpa',fn($j)=>$j->on('gpa.course_offering_id','=','co.course_offering_id')->on('gpa.component_type','=','gc.component_type'));
        $this->simpleOfferingFilters($q,$input,'co');if(!empty($input['filters']['college_ids']))$q->whereIn('c.college_id',$input['filters']['college_ids']);return[$q,[]];
    }

    private function workflowFacts(array $input): array
    {
        if (($input['period']['type'] ?? null) === 'date_range') {
            $q=DB::table('grade_part_approval_events as gpe')
                ->join('grade_part_approvals as gpa','gpa.grade_part_approval_id','=','gpe.grade_part_approval_id')
                ->join('course_offerings as co','co.course_offering_id','=','gpa.course_offering_id');
            $this->baseOfferingJoins($q);
            $q->whereIn('gpe.action',['submitted','returned','approved'])
                ->whereBetween('gpe.performed_at',[$input['period']['date_from'].' 00:00:00',$input['period']['date_to'].' 23:59:59']);
            $this->offeringFilters($q,$input);
            if(!empty($input['filters']['grade_workflow_statuses']))$q->whereIn('gpe.action',$input['filters']['grade_workflow_statuses']);
            return[$q,[]];
        }
        $q=DB::table('course_offerings as co');$this->baseOfferingJoins($q);$required=DB::table('grade_components')->where('is_required',1)->select('course_offering_id','component_type')->distinct();$q->joinSub($required,'gc',fn($j)=>$j->on('gc.course_offering_id','=','co.course_offering_id'))->leftJoin('grade_part_approvals as gpa',fn($j)=>$j->on('gpa.course_offering_id','=','co.course_offering_id')->on('gpa.component_type','=','gc.component_type'));$latest=DB::table('grade_approvals')->selectRaw('course_offering_id, MAX(grade_approval_id) AS grade_approval_id')->groupBy('course_offering_id');$q->leftJoinSub($latest,'latest_ga','latest_ga.course_offering_id','=','co.course_offering_id')->leftJoin('grade_approvals as ga','ga.grade_approval_id','=','latest_ga.grade_approval_id')->leftJoin('approval_statuses as gas','gas.approval_status_id','=','ga.approval_status_id');$this->offeringFilters($q,$input);if(!empty($input['filters']['grade_workflow_statuses']))$q->whereIn(DB::raw("COALESCE(gpa.status,'draft')"),$input['filters']['grade_workflow_statuses']);return[$q,[]];
    }

    private function metricSql(string $subject,string $metric,array $input=[]): string
    {
        $map=[
            'student_count'=>'COUNT(DISTINCT s.student_id)','active_student_count'=>"COUNT(DISTINCT CASE WHEN ss.status_code='active' THEN s.student_id END)",'inactive_student_count'=>"COUNT(DISTINCT CASE WHEN ss.status_code<>'active' THEN s.student_id END)",'new_student_count'=>'COUNT(DISTINCT news.student_id)','enrollment_count'=>'COUNT(DISTINCT s.student_id)',
            'course_offering_count'=>'COUNT(DISTINCT co.course_offering_id)','open_offering_count'=>"COUNT(DISTINCT CASE WHEN co.status='open' THEN co.course_offering_id END)",'closed_offering_count'=>"COUNT(DISTINCT CASE WHEN co.status='closed' THEN co.course_offering_id END)",'registered_student_count'=>"COUNT(DISTINCT CASE WHEN rs.status_code='registered' THEN scr.student_id END)",
            'faculty_count'=>'COUNT(DISTINCT fm.faculty_member_id)','active_faculty_count'=>'COUNT(DISTINCT CASE WHEN fm.is_active=1 THEN fm.faculty_member_id END)','active_assigned_faculty_count'=>'COUNT(DISTINCT CASE WHEN fm.is_active=1 AND coi.course_offering_instructor_id IS NOT NULL THEN fm.faculty_member_id END)','assigned_sections_count'=>'COUNT(DISTINCT coi.course_offering_instructor_id)','offerings_count'=>'COUNT(DISTINCT co.course_offering_id)','registered_students_count'=>"COUNT(DISTINCT CASE WHEN frs.status_code='registered' THEN fscr.student_id END)",'students_per_assigned_faculty'=>"COUNT(DISTINCT CASE WHEN frs.status_code='registered' THEN fscr.student_id END)",
            'official_result_count'=>'COUNT(DISTINCT r.student_course_result_id)','passed_count'=>"COUNT(DISTINCT CASE WHEN rst.status_code='passed' THEN r.student_course_result_id END)",'failed_count'=>"COUNT(DISTINCT CASE WHEN rst.status_code='failed' THEN r.student_course_result_id END)",'deprived_count'=>"COUNT(DISTINCT CASE WHEN rst.status_code='deprived' THEN r.student_course_result_id END)",'incomplete_count'=>"COUNT(DISTINCT CASE WHEN rst.status_code='incomplete' THEN r.student_course_result_id END)",'official_average'=>'AVG(r.final_mark)','attempted_credit_hours'=>'SUM(crs.credit_hours)','earned_credit_hours'=>"SUM(CASE WHEN rst.status_code='passed' THEN crs.credit_hours ELSE 0 END)",'official_gpa'=>'AVG('.OfficialGradeScale::pointsSql('r.final_mark','rst.status_code').')','pass_rate'=>"SUM(CASE WHEN rst.status_code='passed' THEN 1 ELSE 0 END)",'failure_rate'=>"SUM(CASE WHEN rst.status_code='failed' THEN 1 ELSE 0 END)",
            'required_parts_count'=>"COUNT(DISTINCT CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END)",'draft_parts_count'=>"COUNT(DISTINCT CASE WHEN COALESCE(gpa.status,'draft')='draft' THEN CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END END)",'submitted_parts_count'=>"COUNT(DISTINCT CASE WHEN gpa.status='submitted' THEN CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END END)",'returned_parts_count'=>"COUNT(DISTINCT CASE WHEN gpa.status='returned' THEN CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END END)",'approved_parts_count'=>"COUNT(DISTINCT CASE WHEN gpa.status='approved' THEN CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END END)",'completed_offerings_count'=>"COUNT(DISTINCT CASE WHEN gas.status_code='approved' THEN co.course_offering_id END)",'pending_offerings_count'=>"COUNT(DISTINCT CASE WHEN COALESCE(gas.status_code,'pending')<>'approved' THEN co.course_offering_id END)",'completion_rate'=>"COUNT(DISTINCT CASE WHEN gpa.status='approved' THEN CASE WHEN gc.component_type='theoretical' THEN 2 * co.course_offering_id ELSE 2 * co.course_offering_id + 1 END END)",
        ];
        if (($input['period']['type'] ?? null) === 'date_range' && $subject === 'faculty') $map=array_merge($map,[
            'assigned_sections_count'=>'COUNT(DISTINCT tae.teaching_assignment_event_id)',
            'offerings_count'=>'COUNT(DISTINCT co.course_offering_id)',
        ]);
        if (($input['period']['type'] ?? null) === 'date_range' && $subject === 'grade_workflow') $map=array_merge($map,[
            'submitted_parts_count'=>"COUNT(DISTINCT CASE WHEN gpe.action='submitted' THEN gpe.grade_part_approval_event_id END)",
            'returned_parts_count'=>"COUNT(DISTINCT CASE WHEN gpe.action='returned' THEN gpe.grade_part_approval_event_id END)",
            'approved_parts_count'=>"COUNT(DISTINCT CASE WHEN gpe.action='approved' THEN gpe.grade_part_approval_event_id END)",
        ]);
        if($subject==='students')$map=array_merge($map,[
            'official_result_count'=>'COUNT(DISTINCT offr.student_course_result_id)','official_average'=>'AVG(offr.final_mark)',
            'official_gpa'=>'AVG('.OfficialGradeScale::pointsSql('offr.final_mark','offr.result_status_code').')',
            'attempted_credit_hours'=>'SUM(offr.credit_hours)','earned_credit_hours'=>"SUM(CASE WHEN offr.result_status_code='passed' THEN offr.credit_hours ELSE 0 END)",
        ]);
        return $map[$metric]??'0';
    }

    private function dimensionMap(string $subject,array $input): array
    {
        $historical=($input['period']['type']??null)==='date_range';
        $date=match($subject){'course_offerings'=>'co.created_at','grade_workflow'=>$historical?'gpe.performed_at':'gpa.created_at','faculty'=>$historical?'tae.created_at':'co.created_at',default=>'s.enrollment_date'};
        $sqlite=DB::connection()->getDriverName()==='sqlite';
        $map=['college'=>'c.college_id','department'=>'d.department_id','program'=>'ap.academic_program_id','academic_level'=>'al.academic_level_id','student_status'=>'ss.status_code','academic_year'=>'ay.academic_year_id','semester'=>'sem.semester_id','course'=>'crs.course_id','offering_status'=>'co.status','result_status'=>'rst.status_code','faculty_member'=>'fm.faculty_member_id','grade_workflow_status'=>$historical?'gpe.action':"COALESCE(gpa.status,'draft')",'day'=>"DATE({$date})",'week'=>$sqlite?"strftime('%Y-W%W', {$date})":"DATE_FORMAT({$date}, '%x-W%v')",'month'=>$sqlite?"strftime('%Y-%m', {$date})":"DATE_FORMAT({$date}, '%Y-%m')"];
        if($subject==='students'){$map['academic_year']='sm.academic_year_id';$map['semester']='sm.semester_id';}
        return$map;
    }
    private function studentFilters($q,array $f): void { foreach(['college_ids'=>'c.college_id','department_ids'=>'d.department_id','program_ids'=>'s.academic_program_id','academic_level_ids'=>'s.current_academic_level_id','student_status_codes'=>'ss.status_code'] as $k=>$c)if(!empty($f[$k]))$q->whereIn($c,$f[$k]); }
    private function offeringFilters($q,array $input): void { $this->simpleOfferingFilters($q,$input,'co');$f=$input['filters']??[];foreach(['college_ids'=>'c.college_id','department_ids'=>'d.department_id','program_ids'=>'co.academic_program_id','course_ids'=>'co.course_id','offering_ids'=>'co.course_offering_id','offering_statuses'=>'co.status'] as $k=>$c)if(!empty($f[$k]))$q->whereIn($c,$f[$k]); }
    private function simpleOfferingFilters($q,array $input,string $a): void { $f=$input['filters']??[];$p=$input['period']??[];foreach(['academic_year_ids'=>'academic_year_id','semester_ids'=>'semester_id'] as $k=>$c){if(!empty($f[$k]))$q->whereIn("{$a}.{$c}",$f[$k]);if(($p['type']??null)==='academic'&&!empty($p[$k]))$q->whereIn("{$a}.{$c}",$p[$k]);}if(!empty($f['course_ids']))$q->whereIn("{$a}.course_id",$f['course_ids']);if(!empty($f['offering_ids']))$q->whereIn("{$a}.course_offering_id",$f['offering_ids']); }
    private function normalizeMetric(string $metric,$value,array $row): array|int|float|null { if(in_array($metric,['pass_rate','failure_rate','completion_rate','students_per_assigned_faculty'],true)){ $denominator=match($metric){'pass_rate','failure_rate'=>(int)($row['official_result_count']??0),'completion_rate'=>(int)($row['required_parts_count']??0),default=>(int)($row['active_assigned_faculty_count']??0)};$numerator=(int)$value;$factor=$metric==='students_per_assigned_faculty'?1:100;return['numerator'=>$numerator,'denominator'=>$denominator,'value'=>$denominator?round($numerator/$denominator*$factor,2):null]+($denominator?[]:['reason'=>'zero_denominator']); } return is_numeric($value)?(float)$value:$value; }
    private function rateDependencies(array $metrics): array { $dependencies=[];foreach($metrics as $metric){$dependency=match($metric){'pass_rate','failure_rate'=>'official_result_count','completion_rate'=>'required_parts_count','students_per_assigned_faculty'=>'active_assigned_faculty_count',default=>null};if($dependency&&!in_array($dependency,$metrics,true))$dependencies[]=$dependency;}return array_values(array_unique($dependencies)); }
    private function applyAggregateSort(Builder $query,string $subject,array $input,array $dimensions,array $sqlMetrics): void
    {
        $sort=$input['sort']??null;
        $field=$sort['field']??($dimensions[0]??($sqlMetrics[0]??'aggregate_key'));
        $direction=$sort['direction']??'asc';
        if(in_array($field,['pass_rate','failure_rate','completion_rate','students_per_assigned_faculty'],true))$query->orderBy(DB::raw($this->rateSortSql($subject,$field,$input)),$direction);
        else$query->orderBy($field,$direction);
        foreach($dimensions as$dimension)if($dimension!==$field)$query->orderBy($dimension,'asc');
    }
    private function rateSortSql(string $subject,string $metric,array $input): string
    {
        $denominator=match($metric){'pass_rate','failure_rate'=>'official_result_count','completion_rate'=>'required_parts_count','students_per_assigned_faculty'=>'active_assigned_faculty_count'};
        return '(1.0 * ('.$this->metricSql($subject,$metric,$input).') / NULLIF(('.$this->metricSql($subject,$denominator,$input).'), 0))';
    }
    private function safeScope(array $filters): array { return collect($filters)->map(fn($v)=>is_array($v)?array_values($v):$v)->all(); }
    private function history(string $subject,array $input): array
    {
        if($reason=$this->historyUnavailableReason($input))return['available'=>false,'reason'=>$reason,'data'=>[]];
        if(($input['period']['type']??null)==='date_range')return['available'=>true,'capability'=>'append_only_events_or_persisted_timestamp','metrics'=>$input['metrics']];
        return['available'=>true,'capability'=>in_array($subject,['students','faculty'],true)?'current_snapshot':'academic_or_current'];
    }

    private function historyUnavailableReason(array $input): ?string
    {
        if (($input['period']['type'] ?? null) !== 'date_range') return null;
        $supported=ExecutiveReportRegistry::historicalMetrics((string)$input['subject']);
        return array_diff($input['metrics']??[],$supported)!==[]?'historical_data_unavailable':null;
    }

    private function validateSort(array $input): void
    {
        $sort=$input['sort']['field']??null;
        $sortable=($input['mode']??null)==='details'?ExecutiveReportRegistry::detailSortable((string)$input['subject']):ExecutiveReportRegistry::sortable((string)$input['subject']);
        if($sort!==null&&!in_array($sort,$sortable,true))throw ValidationException::withMessages(['sort.field'=>'Unsupported sort field.']);
        if($sort!==null&&!in_array($sort,array_merge($input['metrics']??[],$input['dimensions']??[]),true))throw ValidationException::withMessages(['sort.field'=>'Sort field must be one of the selected metrics or dimensions.']);
    }
    private function comparison(array $input,array $metrics,array $dimensions): ?array
    {
        if(empty($input['comparison']))return null;
        $type=$input['comparison']['type'];
        if($type==='selected_scopes'){ $selected=$this->execute($input['subject'],$metrics,$dimensions,array_diff_key($input,['comparison'=>true]));return['type'=>$type,'available'=>true,'scope'=>$this->safeScope($input['filters']??[]),'period'=>$input['period']??null,'grouping'=>['dimensions'=>$dimensions],'summary'=>$selected['summary'],'series'=>$selected['series']]; }
        $baseline=array_diff_key($input,['comparison'=>true]);
        if($type==='custom'){
            if(empty($input['comparison']['baseline']))return['type'=>$type,'available'=>false,'reason'=>'comparison_baseline_required'];
            $baseline['filters']=$input['comparison']['baseline']['filters']??[];
            $baseline['period']=$input['comparison']['baseline']['period']??null;
        }elseif($type==='previous_period'&&($input['period']['type']??null)==='date_range'){
            $from=CarbonImmutable::parse($input['period']['date_from'],'UTC');$to=CarbonImmutable::parse($input['period']['date_to'],'UTC');$days=$from->diffInDays($to)+1;
            $baseline['period']=['type'=>'date_range','date_from'=>$from->subDays($days)->toDateString(),'date_to'=>$from->subDay()->toDateString()];
        }elseif($type==='previous_academic_year'&&count($input['period']['academic_year_ids']??[])===1){
            $year=DB::table('academic_years')->where('academic_year_id',$input['period']['academic_year_ids'][0])->first();$previous=$year?DB::table('academic_years')->where('start_date','<',$year->start_date)->orderByDesc('start_date')->first():null;
            if(!$previous)return['type'=>$type,'available'=>false,'reason'=>'comparison_baseline_unavailable'];unset($baseline['filters']['academic_year_ids'],$baseline['filters']['semester_ids']);$baseline['period']['academic_year_ids']=[(int)$previous->academic_year_id];$baseline['period']['semester_ids']=[];
        }elseif($type==='previous_semester'&&count($input['period']['academic_year_ids']??[])===1&&count($input['period']['semester_ids']??[])===1){
            $current=DB::table('semesters')->where('semester_id',$input['period']['semester_ids'][0])->first();$previous=$current?DB::table('semesters')->where('semester_order','<',$current->semester_order)->orderByDesc('semester_order')->first():null;
            unset($baseline['filters']['academic_year_ids'],$baseline['filters']['semester_ids']);if($previous)$baseline['period']['semester_ids']=[(int)$previous->semester_id];else{$year=DB::table('academic_years')->where('academic_year_id',$input['period']['academic_year_ids'][0])->first();$prior=$year?DB::table('academic_years')->where('start_date','<',$year->start_date)->orderByDesc('start_date')->first():null;$last=DB::table('semesters')->orderByDesc('semester_order')->first();if(!$prior||!$last)return['type'=>$type,'available'=>false,'reason'=>'comparison_baseline_unavailable'];$baseline['period']['academic_year_ids']=[(int)$prior->academic_year_id];$baseline['period']['semester_ids']=[(int)$last->semester_id];}
        }else return['type'=>$type,'available'=>false,'reason'=>'comparison_baseline_unavailable'];
        $this->validateContext($baseline);$this->validateSelections($baseline);$this->validateSort($baseline);
        if($reason=$this->historyUnavailableReason($baseline))return['type'=>$type,'available'=>false,'reason'=>$reason,'scope'=>$this->safeScope($baseline['filters']??[]),'period'=>$baseline['period']??null];
        $baselineResult=$this->execute($baseline['subject'],$metrics,$dimensions,$baseline);
        return['type'=>$type,'available'=>true,'scope'=>$this->safeScope($baseline['filters']??[]),'period'=>$baseline['period']??null,'grouping'=>['dimensions'=>$dimensions],'summary'=>$baselineResult['summary'],'series'=>$baselineResult['series']];
    }
    private function validateContext(array $input): void
    {
        $p=$input['period']??null;$dims=$input['dimensions']??[];
        $subject=(string)($input['subject']??'');
        if(!in_array((string)($input['mode']??''),ExecutiveReportRegistry::modes($subject),true))throw ValidationException::withMessages(['mode'=>'The selected subject does not support this report mode.']);
        if(!in_array($p['type']??'none',ExecutiveReportRegistry::periods($subject),true))throw ValidationException::withMessages(['period.type'=>'The selected subject does not support this period type.']);
        $timeDimensions=array_values(array_intersect($dims,['day','week','month']));
        if($timeDimensions!==[]&&(($p['type']??null)!=='date_range'||count($timeDimensions)!==1))throw ValidationException::withMessages(['dimensions'=>'Time dimensions require a date range and exactly one day, week, or month dimension.']);
        if($input['mode']==='trend'&&(($p['type']??null)!=='date_range'||count(array_intersect($dims,['day','week','month']))!==1))throw ValidationException::withMessages(['period'=>'Trend reports require a date range and one matching time dimension.']);
        if(($p['type']??null)==='date_range'){
            if(empty($p['date_from'])||empty($p['date_to']))throw ValidationException::withMessages(['period'=>'Date-range period requires both boundaries.']);
            $days=CarbonImmutable::parse($p['date_from'],'UTC')->diffInDays(CarbonImmutable::parse($p['date_to'],'UTC'))+1;
            $max=in_array('day',$dims,true)?366:(in_array('week',$dims,true)?(366*5):(366*10));
            if($days>$max)throw ValidationException::withMessages(['period'=>'Selected historical range exceeds the supported reporting limit.']);
        }
        if(($p['type']??null)==='academic'&&empty($p['academic_year_ids']))throw ValidationException::withMessages(['period.academic_year_ids'=>'Academic period requires an academic year.']);
        if(!empty($p['semester_ids'])&&empty($p['academic_year_ids']))throw ValidationException::withMessages(['period.semester_ids'=>'Semester selection requires an academic year.']);
        if($input['subject']==='enrollments'&&!empty($p['semester_ids']))throw ValidationException::withMessages(['period.semester_ids'=>'Enrollment history has no authoritative semester date boundary.']);
        if($input['subject']==='grade_workflow'&&($p['type']??null)==='date_range'&&in_array('draft',$input['filters']['grade_workflow_statuses']??[],true))throw ValidationException::withMessages(['filters.grade_workflow_statuses'=>'Draft is a current-state value, not an append-only workflow event.']);
        if(($input['comparison']['type']??null)==='selected_scopes'){$filterKey=['college'=>'college_ids','department'=>'department_ids','program'=>'program_ids','academic_level'=>'academic_level_ids','academic_year'=>'academic_year_ids','semester'=>'semester_ids','course'=>'course_ids'][$dims[0]??'']??null;if(count($dims)!==1||!$filterKey||count($input['filters'][$filterKey]??[])<2)throw ValidationException::withMessages(['comparison'=>'Selected-scope comparison requires one grouped dimension with at least two selected values.']);}
    }

    private function validateSelections(array $input): void
    {
        $filters=$input['filters']??[];$period=$input['period']??[];
        foreach(['academic_year_ids','semester_ids']as$key){$a=array_values(array_unique($filters[$key]??[]));$b=array_values(array_unique($period[$key]??[]));sort($a);sort($b);if($a!==[]&&$b!==[]&&$a!==$b)throw ValidationException::withMessages([$key=>'Filter and period selections conflict.']);}
        if(array_unique(array_merge($filters['semester_ids']??[],$period['semester_ids']??[]))!==[]&&array_unique(array_merge($filters['academic_year_ids']??[],$period['academic_year_ids']??[]))===[])throw ValidationException::withMessages(['semester_ids'=>'Semester selection requires an academic year context.']);
        if(($input['period']['type']??null)==='academic')foreach(['academic_year_ids','semester_ids']as$key)$filters[$key]=array_values(array_unique(array_merge($filters[$key]??[],$input['period'][$key]??[])));
        $tables=['college_ids'=>['colleges','college_id'],'department_ids'=>['departments','department_id'],'program_ids'=>['academic_programs','academic_program_id'],'academic_year_ids'=>['academic_years','academic_year_id'],'semester_ids'=>['semesters','semester_id'],'academic_level_ids'=>['academic_levels','academic_level_id'],'course_ids'=>['courses','course_id'],'offering_ids'=>['course_offerings','course_offering_id']];
        foreach($tables as $key=>[$table,$column]){if(empty($filters[$key]))continue;$found=DB::table($table)->whereIn($column,$filters[$key])->pluck($column)->map(fn($v)=>(int)$v)->all();if(array_diff(array_map('intval',$filters[$key]),$found)!==[])abort(403);}
        foreach(['student_status_codes'=>['student_statuses','status_code'],'result_status_codes'=>['result_statuses','status_code']]as$key=>[$table,$column]){if(empty($filters[$key]))continue;$found=DB::table($table)->whereIn($column,$filters[$key])->pluck($column)->all();if(array_diff($filters[$key],$found)!==[])abort(403);}
        if(!empty($filters['department_ids'])&&!empty($filters['college_ids'])&&DB::table('departments')->whereIn('department_id',$filters['department_ids'])->whereNotIn('college_id',$filters['college_ids'])->exists())throw ValidationException::withMessages(['filters'=>'Department and college selections do not share the requested hierarchy.']);
        if(!empty($filters['program_ids'])&&!empty($filters['department_ids'])&&DB::table('academic_programs')->whereIn('academic_program_id',$filters['program_ids'])->whereNotIn('department_id',$filters['department_ids'])->exists())throw ValidationException::withMessages(['filters'=>'Program and department selections do not share the requested hierarchy.']);
        if(!empty($filters['program_ids'])&&!empty($filters['college_ids'])&&DB::table('academic_programs as vap')->join('departments as vd','vd.department_id','=','vap.department_id')->whereIn('vap.academic_program_id',$filters['program_ids'])->whereNotIn('vd.college_id',$filters['college_ids'])->exists())throw ValidationException::withMessages(['filters'=>'Program and college selections do not share the requested hierarchy.']);
        if(!empty($filters['offering_ids'])){ $q=DB::table('course_offerings as vco')->leftJoin('academic_programs as vap','vap.academic_program_id','=','vco.academic_program_id')->leftJoin('departments as vd','vd.department_id','=','vap.department_id')->whereIn('vco.course_offering_id',$filters['offering_ids']); foreach(['program_ids'=>'vco.academic_program_id','academic_year_ids'=>'vco.academic_year_id','semester_ids'=>'vco.semester_id','course_ids'=>'vco.course_id','department_ids'=>'vap.department_id','college_ids'=>'vd.college_id'] as $key=>$column)if(!empty($filters[$key])&&(clone$q)->whereNotIn($column,$filters[$key])->exists())throw ValidationException::withMessages(['filters'=>'Offering selection conflicts with its authoritative academic context.']); }
    }

    private function detailRows(string $subject,array $input): array
    {
        $query=match($subject){
            'students','enrollments'=>$this->studentDetailQuery($input),
            'academic_performance'=>$this->performanceDetailQuery($input),
            'course_offerings'=>$this->offeringDetailQuery($input),
            'grade_workflow'=>$this->workflowDetailQuery($input),
            'faculty'=>$this->facultyDetailQuery($input),
        };
        $page=$query->paginate((int)($input['per_page']??25),['*'],'page',(int)($input['page']??1));
        $rows=collect($page->items())->map(fn($r)=>(array)$r);
        if($subject==='students'&&in_array('official_gpa',$input['metrics']??[],true)&&$rows->isNotEmpty()){
            $gpaInput=$input;$gpaInput['_student_ids']=$rows->pluck('student_id')->map(fn($id)=>(int)$id)->all();
            $gpas=$this->officialGpa('students',$gpaInput,['student'])['groups'];
            $rows=$rows->map(function(array $row)use($gpas){$row['official_gpa']=$gpas[(string)$row['student_id']]??['value'=>null,'contributing_students'=>0];return$row;});
        }
        return['rows'=>$rows->all(),'pagination'=>['current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'last_page'=>$page->lastPage()]];
    }

    private function studentDetailQuery(array $input): Builder
    {
        $q=$this->currentStudentBase()->select('s.student_id','s.student_number',DB::raw($this->fullNameSql('s').' AS full_name'),'c.college_name','ap.program_name','al.level_name','ss.status_code as student_status');
        $f=$input['filters']??[];$this->studentFilters($q,$f);$p=$input['period']??null;
        if($input['subject']==='students'&&(($p['type']??null)==='academic'||!empty($f['academic_year_ids'])||!empty($f['semester_ids'])))$q->whereExists(fn($x)=>$x->selectRaw('1')->from('student_course_registrations as dscr')->join('registration_statuses as drs','drs.registration_status_id','=','dscr.registration_status_id')->join('course_offerings as dco','dco.course_offering_id','=','dscr.course_offering_id')->whereColumn('dscr.student_id','s.student_id')->whereIn('drs.status_code',['registered','completed'])->when(($p['type']??null)==='academic',fn($z)=>$z->whereIn('dco.academic_year_id',$p['academic_year_ids'])->when(!empty($p['semester_ids']),fn($w)=>$w->whereIn('dco.semester_id',$p['semester_ids'])))->when(!empty($f['academic_year_ids']),fn($z)=>$z->whereIn('dco.academic_year_id',$f['academic_year_ids']))->when(!empty($f['semester_ids']),fn($z)=>$z->whereIn('dco.semester_id',$f['semester_ids'])));
        if($input['subject']==='enrollments'){if(($p['type']??null)==='date_range')$q->whereBetween('s.enrollment_date',[$p['date_from'],$p['date_to']]);$yearIds=array_values(array_unique(array_merge($f['academic_year_ids']??[],($p['type']??null)==='academic'?($p['academic_year_ids']??[]):[])));if($yearIds!==[])$q->whereExists(fn($x)=>$x->selectRaw('1')->from('academic_years as eay')->whereColumn('s.enrollment_date','>=','eay.start_date')->whereColumn('s.enrollment_date','<=','eay.end_date')->whereIn('eay.academic_year_id',$yearIds));$q->addSelect('s.enrollment_date');}
        if($input['subject']==='students'){
            $official=$this->studentOfficialAggregates($input);
            $q->leftJoinSub($official,'detail_official','detail_official.student_id','=','s.student_id');
            foreach(array_intersect($input['metrics']??[],['official_result_count','official_average','attempted_credit_hours','earned_credit_hours'])as$metric){
                if(in_array($metric,['official_result_count','attempted_credit_hours','earned_credit_hours'],true))$q->addSelect(DB::raw("COALESCE(detail_official.{$metric}, 0) AS {$metric}"));
                else$q->addSelect("detail_official.{$metric}");
            }
        }
        $sortMap=['student_count'=>'s.student_id','active_student_count'=>'s.student_id','inactive_student_count'=>'s.student_id','new_student_count'=>'s.enrollment_date','enrollment_count'=>'s.enrollment_date','official_result_count'=>'detail_official.official_result_count','official_average'=>'detail_official.official_average','attempted_credit_hours'=>'detail_official.attempted_credit_hours','earned_credit_hours'=>'detail_official.earned_credit_hours','college'=>'c.college_id','department'=>'d.department_id','program'=>'s.academic_program_id','academic_level'=>'s.current_academic_level_id','student_status'=>'ss.status_code','academic_year'=>'s.enrollment_date'];
        return$this->applyDetailSort($q,$input,$sortMap,'s.student_id','s.student_number');
    }
    private function performanceDetailQuery(array $input): Builder
    {
        [$q]=$this->performanceFacts($input);$map=['official_result_count'=>'r.student_course_result_id','passed_count'=>'rst.status_code','failed_count'=>'rst.status_code','deprived_count'=>'rst.status_code','incomplete_count'=>'rst.status_code','official_average'=>'r.final_mark','attempted_credit_hours'=>'crs.credit_hours','earned_credit_hours'=>'crs.credit_hours','college'=>'c.college_id','department'=>'d.department_id','program'=>'co.academic_program_id','academic_year'=>'co.academic_year_id','semester'=>'co.semester_id','academic_level'=>'s.current_academic_level_id','result_status'=>'rst.status_code','course'=>'crs.course_id'];return$this->applyDetailSort($q->select('r.student_course_result_id','s.student_id','s.student_number',DB::raw($this->fullNameSql('s').' AS full_name'),'c.college_name','ap.program_name','al.level_name','rst.status_code as result_status','crs.course_code','crs.course_name','crs.credit_hours','r.final_mark'),$input,$map,'r.student_course_result_id','s.student_number');
    }
    private function offeringDetailQuery(array $input): Builder
    {
        $q=DB::table('course_offerings as co');$this->baseOfferingJoins($q);$this->offeringFilters($q,$input);if(($input['period']['type']??null)==='date_range')$q->whereBetween('co.created_at',[$input['period']['date_from'].' 00:00:00',$input['period']['date_to'].' 23:59:59']);$map=['course_offering_count'=>'co.course_offering_id','open_offering_count'=>'co.status','closed_offering_count'=>'co.status','college'=>'c.college_id','department'=>'d.department_id','program'=>'co.academic_program_id','academic_year'=>'ay.academic_year_id','semester'=>'sem.semester_id','course'=>'crs.course_id','offering_status'=>'co.status'];return$this->applyDetailSort($q->select('co.course_offering_id','crs.course_code','crs.course_name','ap.program_name','d.department_name','c.college_name','ay.year_name','sem.semester_name','co.status','co.capacity','co.available_seats','co.created_at')->distinct(),$input,$map,'co.course_offering_id','ay.start_date');
    }
    private function workflowDetailQuery(array $input): Builder
    {
        [$q]=$this->workflowFacts($input);
        if(($input['period']['type']??null)==='date_range'){
            $map=['submitted_parts_count'=>'gpe.action','returned_parts_count'=>'gpe.action','approved_parts_count'=>'gpe.action','college'=>'c.college_id','department'=>'d.department_id','program'=>'co.academic_program_id','academic_year'=>'co.academic_year_id','semester'=>'co.semester_id','course'=>'crs.course_id','grade_workflow_status'=>'gpe.action','day'=>'gpe.performed_at','week'=>'gpe.performed_at','month'=>'gpe.performed_at'];
            return$this->applyDetailSort($q->select('gpe.grade_part_approval_event_id','co.course_offering_id','crs.course_code','crs.course_name','gpa.component_type','gpe.action as grade_workflow_status','gpe.performed_at'),$input,$map,['gpe.performed_at','gpe.grade_part_approval_event_id'],'gpe.performed_at');
        }
        $currentStatus=DB::raw("COALESCE(gpa.status,'draft')");
        $map=['required_parts_count'=>'gc.component_type','draft_parts_count'=>$currentStatus,'submitted_parts_count'=>'gpa.status','returned_parts_count'=>'gpa.status','approved_parts_count'=>'gpa.status','college'=>'c.college_id','department'=>'d.department_id','program'=>'co.academic_program_id','academic_year'=>'co.academic_year_id','semester'=>'co.semester_id','grade_workflow_status'=>$currentStatus,'course'=>'crs.course_id'];
        $projection=$q->select('co.course_offering_id','crs.course_code','crs.course_name','gc.component_type',DB::raw("COALESCE(gpa.status,'draft') as grade_workflow_status"),'gas.status_code as final_approval_status')
            ->groupBy('co.course_offering_id','crs.course_code','crs.course_name','gc.component_type','gpa.status','gas.status_code');
        return$this->applyDetailSort($projection,$input,$map,['co.course_offering_id','gc.component_type'],'crs.course_code');
    }
    private function facultyDetailQuery(array $input): Builder
    {
        [$q]=$this->facultyFacts($input);$map=['faculty_count'=>'fm.faculty_member_id','active_faculty_count'=>'fm.is_active','faculty_member'=>'fm.faculty_member_id','college'=>'c.college_id','academic_year'=>'co.academic_year_id','semester'=>'co.semester_id','course'=>'co.course_id'];if(($input['period']['type']??null)==='date_range')return$this->applyDetailSort($q->select('tae.teaching_assignment_event_id','fm.faculty_member_id','e.employee_number',DB::raw($this->fullNameSql('e').' AS full_name'),'tae.event_type','tae.created_at','co.course_offering_id','crs.course_code'),$input,$map,['tae.created_at','tae.teaching_assignment_event_id'],'tae.created_at');
        $projection=$q->select('fm.faculty_member_id','e.employee_number',DB::raw($this->fullNameSql('e').' AS full_name'),'fm.academic_rank','fm.is_active','c.college_id','c.college_name')
            ->groupBy('fm.faculty_member_id','e.employee_number','e.first_name','e.last_name','fm.academic_rank','fm.is_active','c.college_id','c.college_name');
        return$this->applyDetailSort($projection,$input,$map,['fm.faculty_member_id','c.college_id'],'e.employee_number');
    }

    private function applyDetailSort(Builder $query,array $input,array $map,array|string $tieBreakers,string $default): Builder
    {
        $sort=$input['sort']??null;$column=$sort?$map[$sort['field']]??null:null;
        if($sort&&$column===null)throw ValidationException::withMessages(['sort.field'=>'The selected sort is not available for this detail projection.']);
        $query->orderBy($column??$default,$sort['direction']??'asc');
        foreach((array)$tieBreakers as$tieBreaker)if(($column??$default)!==$tieBreaker)$query->orderBy($tieBreaker,'asc');
        return$query;
    }

    private function studentOfficialAggregates(array $input): Builder
    {
        $q=$this->grades->scopeOfficialApprovedResults(StudentCourseResult::query())
            ->join('student_course_registrations as dascr','dascr.student_course_registration_id','=','student_course_results.student_course_registration_id')
            ->join('registration_statuses as dars','dars.registration_status_id','=','dascr.registration_status_id')->whereIn('dars.status_code',['registered','completed'])
            ->join('result_statuses as darst','darst.result_status_id','=','student_course_results.result_status_id')
            ->join('course_offerings as daco','daco.course_offering_id','=','dascr.course_offering_id')->join('courses as dacrs','dacrs.course_id','=','daco.course_id');
        $f=$input['filters']??[];$p=$input['period']??[];foreach(['academic_year_ids'=>'daco.academic_year_id','semester_ids'=>'daco.semester_id']as$key=>$column){if(!empty($f[$key]))$q->whereIn($column,$f[$key]);if(($p['type']??null)==='academic'&&!empty($p[$key]))$q->whereIn($column,$p[$key]);}
        return$q->select('dascr.student_id')->selectRaw("COUNT(DISTINCT student_course_results.student_course_result_id) AS official_result_count, AVG(student_course_results.final_mark) AS official_average, SUM(dacrs.credit_hours) AS attempted_credit_hours, SUM(CASE WHEN darst.status_code='passed' THEN dacrs.credit_hours ELSE 0 END) AS earned_credit_hours")->groupBy('dascr.student_id')->toBase();
    }
    private function fullNameSql(string $alias): string { return DB::connection()->getDriverName()==='sqlite'?"TRIM(COALESCE({$alias}.first_name,'') || ' ' || COALESCE({$alias}.last_name,''))":"TRIM(CONCAT(COALESCE({$alias}.first_name,''), ' ', COALESCE({$alias}.last_name,'')))"; }

    private function officialGpa(string $subject,array $input,array $dimensions): array
    {
        if(!in_array($subject,['students','academic_performance'],true))return['summary'=>['value'=>null,'contributing_students'=>0],'groups'=>[]];
        $required=DB::table('grade_components')->where('is_required',1)->selectRaw("course_offering_id, COUNT(*) AS required_count, MAX(CASE WHEN component_type='theoretical' THEN 1 ELSE 0 END) AS requires_theoretical, MAX(CASE WHEN component_type='practical' THEN 1 ELSE 0 END) AS requires_practical")->groupBy('course_offering_id');
        $base=$this->grades->scopeOfficialApprovedResults(StudentCourseResult::query())
            ->join('student_course_registrations as gscr','gscr.student_course_registration_id','=','student_course_results.student_course_registration_id')
            ->join('registration_statuses as grs','grs.registration_status_id','=','gscr.registration_status_id')->whereIn('grs.status_code',['registered','completed'])
            ->join('result_statuses as grst','grst.result_status_id','=','student_course_results.result_status_id')
            ->join('course_offerings as gco','gco.course_offering_id','=','gscr.course_offering_id')->join('courses as gcrs','gcrs.course_id','=','gco.course_id')->leftJoinSub($required,'greq','greq.course_offering_id','=','gco.course_offering_id')
            ->join('students as gs','gs.student_id','=','gscr.student_id')->leftJoin('academic_levels as gal','gal.academic_level_id','=','gs.current_academic_level_id')->leftJoin('student_statuses as gss','gss.student_status_id','=','gs.student_status_id')
            ->leftJoin('academic_programs as gap','gap.academic_program_id','=','gco.academic_program_id')->leftJoin('departments as gd','gd.department_id','=','gap.department_id')->leftJoin('colleges as gc','gc.college_id','=','gd.college_id')
            ->leftJoin('academic_programs as gsap','gsap.academic_program_id','=','gs.academic_program_id')->leftJoin('departments as gsd','gsd.department_id','=','gsap.department_id')->leftJoin('colleges as gsc','gsc.college_id','=','gsd.college_id');
        $f=$input['filters']??[];$p=$input['period']??[];
        foreach(['academic_year_ids'=>'gco.academic_year_id','semester_ids'=>'gco.semester_id','college_ids'=>$subject==='students'?'gsc.college_id':'gc.college_id','department_ids'=>$subject==='students'?'gsd.department_id':'gd.department_id','program_ids'=>$subject==='students'?'gs.academic_program_id':'gco.academic_program_id','academic_level_ids'=>'gs.current_academic_level_id','student_status_codes'=>'gss.status_code','course_ids'=>'gco.course_id','offering_ids'=>'gco.course_offering_id','result_status_codes'=>'grst.status_code']as$k=>$c){if(!empty($f[$k]))$base->whereIn($c,$f[$k]);if(in_array($k,['academic_year_ids','semester_ids'],true)&&($p['type']??null)==='academic'&&!empty($p[$k]))$base->whereIn($c,$p[$k]);}
        $points=OfficialGradeScale::pointsSql('student_course_results.final_mark','grst.status_code');
        if(!empty($input['_student_ids']))$base->whereIn('gscr.student_id',$input['_student_ids']);
        $requiresTheory="CASE WHEN COALESCE(greq.required_count,0)>0 THEN COALESCE(greq.requires_theoretical,0) WHEN COALESCE(gcrs.theoretical_hours,0)>0 AND COALESCE(gcrs.practical_hours,0)<=0 THEN 1 WHEN COALESCE(gcrs.practical_hours,0)>0 AND COALESCE(gcrs.theoretical_hours,0)<=0 THEN 0 ELSE 1 END";
        $requiresPractical="CASE WHEN COALESCE(greq.required_count,0)>0 THEN COALESCE(greq.requires_practical,0) WHEN COALESCE(gcrs.practical_hours,0)>0 AND COALESCE(gcrs.theoretical_hours,0)<=0 THEN 1 WHEN COALESCE(gcrs.theoretical_hours,0)>0 AND COALESCE(gcrs.practical_hours,0)<=0 THEN 0 ELSE 1 END";
        $base->whereNotIn('grst.status_code',OfficialGradeScale::EXCLUDED_STATUSES)->whereNotNull('student_course_results.final_mark')->whereRaw("({$requiresTheory}=0 OR student_course_results.theoretical_total IS NOT NULL)")->whereRaw("({$requiresPractical}=0 OR student_course_results.practical_total IS NOT NULL)");
        $dimensionSql=['student'=>'gscr.student_id','college'=>$subject==='students'?'gsc.college_id':'gc.college_id','department'=>$subject==='students'?'gsd.department_id':'gd.department_id','program'=>$subject==='students'?'gs.academic_program_id':'gco.academic_program_id','academic_level'=>'gs.current_academic_level_id','student_status'=>'gss.status_code','academic_year'=>'gco.academic_year_id','semester'=>'gco.semester_id','course'=>'gco.course_id','result_status'=>'grst.status_code'];
        $gpaDimensions=array_values(array_filter($dimensions,fn($d)=>isset($dimensionSql[$d])));
        $summaryRanked=(clone$base)->selectRaw("gscr.student_id, gco.course_id, gscr.student_course_registration_id, gcrs.credit_hours, {$points} AS grade_points, ROW_NUMBER() OVER (PARTITION BY gscr.student_id, gco.course_id ORDER BY {$points} DESC, student_course_results.final_mark DESC, gscr.student_course_registration_id DESC) AS attempt_rank");
        $studentGpaSql='1.0 * SUM(grade_points * credit_hours) / NULLIF(SUM(credit_hours), 0) AS student_gpa';
        $perStudentAll=DB::query()->fromSub($summaryRanked,'ranked_all')->where('attempt_rank',1)->select('student_id')->selectRaw($studentGpaSql)->groupBy('student_id');
        $partition=['gscr.student_id','gco.course_id'];foreach($gpaDimensions as$d)$partition[]=$dimensionSql[$d];
        $groupRanked=(clone$base)->selectRaw("gscr.student_id, gco.course_id, gscr.student_course_registration_id, gcrs.credit_hours, {$points} AS grade_points, ROW_NUMBER() OVER (PARTITION BY ".implode(', ',$partition)." ORDER BY {$points} DESC, student_course_results.final_mark DESC, gscr.student_course_registration_id DESC) AS attempt_rank");
        foreach($gpaDimensions as$d)$groupRanked->addSelect(DB::raw($dimensionSql[$d]." AS {$d}"));
        $perStudent=DB::query()->fromSub($groupRanked,'ranked')->where('attempt_rank',1)->select('student_id')->selectRaw($studentGpaSql)->groupBy('student_id');
        foreach($gpaDimensions as$d){$perStudent->addSelect($d);$perStudent->groupBy($d);}
        $grouped=DB::query()->fromSub($perStudent,'student_gpas')->selectRaw('AVG(student_gpa) AS value, COUNT(student_gpa) AS contributing_students');
        foreach($gpaDimensions as$d){$grouped->addSelect($d);$grouped->groupBy($d);}
        $records=$grouped->limit(ExecutiveReportRegistry::LIMITS['points']+1)->get()->map(fn($r)=>(array)$r);
        if($records->count()>ExecutiveReportRegistry::LIMITS['points'])throw ValidationException::withMessages(['dimensions'=>'Grouped GPA exceeds the 500-point safety limit.']);
        $all=DB::query()->fromSub($perStudentAll,'all_student_gpas')->selectRaw('AVG(student_gpa) AS value, COUNT(student_gpa) AS contributing_students')->first();
        return [
            'summary' => ['value' => $all?->value === null ? null : round((float) $all->value, 2), 'contributing_students' => (int) ($all?->contributing_students ?? 0)],
            'groups' => $records->mapWithKeys(fn ($r) => [
                $this->groupKey($r, $dimensions) => ['value' => $r['value'] === null ? null : round((float) $r['value'], 2), 'contributing_students' => (int) $r['contributing_students']],
            ])->all(),
        ];
    }
    private function groupKey(array $row,array $dimensions): string { return implode('|',array_map(fn($d)=>(string)($row[$d]??''),$dimensions)); }
}
