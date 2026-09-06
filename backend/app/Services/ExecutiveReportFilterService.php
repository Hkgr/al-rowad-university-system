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
            ->when(isset($input['academic_year_id']),fn($q)=>$q->where('co.academic_year_id',$input['academic_year_id']))
            ->when(isset($input['semester_id']),fn($q)=>$q->where('co.semester_id',$input['semester_id']));
        if (($term=trim((string)($input['q']??'')))!=='') $query->where(fn($w)=>$w->where('c.course_name','like',"%{$term}%")->orWhere('c.course_code','like',"%{$term}%"));
        return $this->page($query->orderBy('c.course_code')->orderBy('co.course_offering_id'),$input);
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
        foreach (['college_id'=>['colleges','college_id'],'department_id'=>['departments','department_id'],'program_id'=>['academic_programs','academic_program_id'],'academic_year_id'=>['academic_years','academic_year_id'],'semester_id'=>['semesters','semester_id']] as $key=>[$table,$column]) {
            if(isset($input[$key])&&!DB::table($table)->where($column,$input[$key])->exists()) abort(403);
        }
        if(isset($input['college_id'],$input['department_id'])&&!DB::table('departments')->where('department_id',$input['department_id'])->where('college_id',$input['college_id'])->exists()) abort(422,'Hierarchy mismatch.');
        if(isset($input['department_id'],$input['program_id'])&&!DB::table('academic_programs')->where('academic_program_id',$input['program_id'])->where('department_id',$input['department_id'])->exists()) abort(422,'Hierarchy mismatch.');
    }
}
