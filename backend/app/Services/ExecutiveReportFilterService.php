<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ExecutiveReportFilterService
{
    private const MAP = [
        'colleges' => ['colleges','college_id','college_name','college_code'],
        'departments' => ['departments','department_id','department_name','department_code'],
        'programs' => ['academic_programs','academic_program_id','program_name','program_code'],
        'academic_years' => ['academic_years','academic_year_id','year_name',null],
        'semesters' => ['semesters','semester_id','semester_name','semester_code'],
        'academic_levels' => ['academic_levels','academic_level_id','level_name','level_code'],
        'courses' => ['courses','course_id','course_name','course_code'],
        'student_statuses' => ['student_statuses','student_status_id','status_name','status_code'],
        'result_statuses' => ['result_statuses','result_status_id','status_name','status_code'],
    ];

    public function options(array $input): array
    {
        $resource = $input['resource'];
        $this->validateParents($input);
        if ($resource === 'offering_statuses') return $this->staticOptions(['open' => 'مفتوح','closed' => 'مغلق']);
        if ($resource === 'grade_workflow_statuses') return $this->staticOptions(['draft'=>'مسودة','submitted'=>'مرسل','returned'=>'معاد','approved'=>'معتمد']);
        if ($resource === 'offerings') return $this->offerings($input);
        if ($resource === 'courses') return $this->courses($input);
        if ($resource === 'academic_levels') return $this->academicLevels($input);

        [$table,$id,$label,$code] = self::MAP[$resource];
        $query = DB::table($table)->select("{$id} as id", "{$label} as label");
        if ($code) $query->addSelect("{$code} as code");
        $this->parents($query, $resource, $input);
        if (($q = trim((string)($input['q'] ?? ''))) !== '') $query->where(fn ($w) => $w->where($label,'like',"%{$q}%")->when($code,fn($x)=>$x->orWhere($code,'like',"%{$q}%")));
        return $this->page($query->orderBy($label)->orderBy($id), $input);
    }

    private function offerings(array $input): array
    {
        $query = DB::table('course_offerings as co')->join('courses as c','c.course_id','=','co.course_id')
            ->select('co.course_offering_id as id', DB::raw("c.course_name as label"), 'c.course_code as code')
            ->when(isset($input['program_id']),fn($q)=>$q->where('co.academic_program_id',$input['program_id']))
            ->when(isset($input['department_id']),fn($q)=>$q->whereIn('co.academic_program_id',DB::table('academic_programs')->select('academic_program_id')->where('department_id',$input['department_id'])))
            ->when(isset($input['college_id']),fn($q)=>$q->whereIn('co.academic_program_id',DB::table('academic_programs')->select('academic_program_id')->whereIn('department_id',DB::table('departments')->select('department_id')->where('college_id',$input['college_id']))))
            ->when(isset($input['academic_year_id']),fn($q)=>$q->where('co.academic_year_id',$input['academic_year_id']))
            ->when(isset($input['semester_id']),fn($q)=>$q->where('co.semester_id',$input['semester_id']));
        if (($term=trim((string)($input['q']??'')))!=='') $query->where(fn($w)=>$w->where('c.course_name','like',"%{$term}%")->orWhere('c.course_code','like',"%{$term}%"));
        return $this->page($query->orderBy('c.course_code')->orderBy('co.course_offering_id'),$input);
    }

    private function courses(array $input): array
    {
        $query=DB::table('courses as c')->select('c.course_id as id','c.course_name as label','c.course_code as code')->distinct();
        if(isset($input['program_id']))$query->whereIn('c.course_id',DB::table('program_courses')->select('course_id')->where('academic_program_id',$input['program_id'])->where('is_active',1));
        if(isset($input['department_id']))$query->whereIn('c.course_id',DB::table('course_departments')->select('course_id')->where('department_id',$input['department_id']));
        if(isset($input['college_id']))$query->whereIn('c.course_id',DB::table('course_departments')->select('course_id')->whereIn('department_id',DB::table('departments')->select('department_id')->where('college_id',$input['college_id'])));
        if(($term=trim((string)($input['q']??'')))!=='')$query->where(fn($w)=>$w->where('c.course_name','like',"%{$term}%")->orWhere('c.course_code','like',"%{$term}%"));
        return$this->page($query->orderBy('c.course_code')->orderBy('c.course_id'),$input);
    }

    private function academicLevels(array $input): array
    {
        $query=DB::table('academic_levels as al')->select('al.academic_level_id as id','al.level_name as label','al.level_code as code')->distinct();
        $programs=DB::table('academic_programs as lap')->select('lap.academic_program_id');
        if(isset($input['program_id']))$programs->where('lap.academic_program_id',$input['program_id']);
        if(isset($input['department_id']))$programs->where('lap.department_id',$input['department_id']);
        if(isset($input['college_id']))$programs->whereIn('lap.department_id',DB::table('departments')->select('department_id')->where('college_id',$input['college_id']));
        if(isset($input['program_id'])||isset($input['department_id'])||isset($input['college_id']))$query->whereIn('al.academic_level_id',DB::table('program_courses')->select('academic_level_id')->where('is_active',1)->whereIn('academic_program_id',$programs)->whereNotNull('academic_level_id'));
        if(($term=trim((string)($input['q']??'')))!=='')$query->where(fn($w)=>$w->where('al.level_name','like',"%{$term}%")->orWhere('al.level_code','like',"%{$term}%"));
        return$this->page($query->orderBy('al.academic_level_id'),$input);
    }

    private function parents($query,string $resource,array $input): void
    {
        if ($resource === 'departments' && isset($input['college_id'])) $query->where('college_id',$input['college_id']);
        if ($resource === 'programs') {
            if (isset($input['department_id'])) $query->where('department_id',$input['department_id']);
            if (isset($input['college_id'])) $query->whereIn('department_id',DB::table('departments')->select('department_id')->where('college_id',$input['college_id']));
        }
    }

    private function page($query,array $input): array
    {
        /** @var LengthAwarePaginator $page */
        $page=$query->paginate((int)($input['per_page']??25),['*'],'page',(int)($input['page']??1));
        return ['options'=>$page->items(),'pagination'=>['current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'last_page'=>$page->lastPage()]];
    }

    private function staticOptions(array $items): array { return ['options'=>collect($items)->map(fn($label,$id)=>['id'=>$id,'label'=>$label])->values()->all(),'pagination'=>['current_page'=>1,'per_page'=>count($items),'total'=>count($items),'last_page'=>1]]; }

    private function validateParents(array $input): void
    {
        $provided=array_values(array_intersect(['college_id','department_id','program_id','academic_year_id','semester_id'],array_keys($input)));
        $allowed=[
            'departments'=>['college_id'],'programs'=>['college_id','department_id'],'academic_levels'=>['college_id','department_id','program_id'],
            'courses'=>['college_id','department_id','program_id'],'offerings'=>['college_id','department_id','program_id','academic_year_id','semester_id'],
            'semesters'=>['academic_year_id'],
        ][$input['resource']]??[];
        if(array_diff($provided,$allowed)!==[])abort(422,'Unsupported parent context for this filter resource.');
        foreach (['college_id'=>['colleges','college_id'],'department_id'=>['departments','department_id'],'program_id'=>['academic_programs','academic_program_id'],'academic_year_id'=>['academic_years','academic_year_id'],'semester_id'=>['semesters','semester_id']] as $key=>[$table,$column]) {
            if(isset($input[$key])&&!DB::table($table)->where($column,$input[$key])->exists()) abort(403);
        }
        if(isset($input['college_id'],$input['department_id'])&&!DB::table('departments')->where('department_id',$input['department_id'])->where('college_id',$input['college_id'])->exists()) abort(422,'Hierarchy mismatch.');
        if(isset($input['department_id'],$input['program_id'])&&!DB::table('academic_programs')->where('academic_program_id',$input['program_id'])->where('department_id',$input['department_id'])->exists()) abort(422,'Hierarchy mismatch.');
        if(isset($input['college_id'],$input['program_id'])&&!DB::table('academic_programs as vp')->join('departments as vd','vd.department_id','=','vp.department_id')->where('vp.academic_program_id',$input['program_id'])->where('vd.college_id',$input['college_id'])->exists()) abort(422,'Hierarchy mismatch.');
    }
}
