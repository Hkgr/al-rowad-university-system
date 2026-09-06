<?php

namespace App\Http\Requests;

use App\Support\ExecutiveReportAccess;
use App\Support\ExecutiveReportRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecutiveReportQueryRequest extends FormRequest
{
    private const TOP_KEYS = ['subject','mode','metrics','dimensions','filters','period','comparison','page','per_page','sort'];
    private const FILTER_KEYS = ['college_ids','department_ids','program_ids','academic_year_ids','semester_ids','academic_level_ids','course_ids','offering_ids','student_status_codes','result_status_codes','offering_statuses','grade_workflow_statuses'];
    private const PERIOD_KEYS = ['type','academic_year_ids','semester_ids','date_from','date_to'];
    private const COMPARISON_KEYS = ['type','baseline'];
    private const BASELINE_KEYS = ['filters','period'];
    private const SORT_KEYS = ['field','direction'];

    public function authorize(): bool { return app(ExecutiveReportAccess::class)->allows($this->user()); }

    public function rules(): array
    {
        $idList = ['sometimes','array','max:50'];
        $ids = ['integer','min:1'];
        $rules = [
            'subject' => ['required', Rule::in(ExecutiveReportRegistry::subjects())],
            'mode' => ['required', Rule::in(ExecutiveReportRegistry::MODES)],
            'metrics' => ['required','array','min:1','max:'.ExecutiveReportRegistry::LIMITS['metrics']], 'metrics.*' => ['string','distinct'],
            'dimensions' => ['sometimes','array','max:'.ExecutiveReportRegistry::LIMITS['dimensions']], 'dimensions.*' => ['string','distinct'],
            'filters' => ['sometimes','array'],
            'filters.college_ids' => $idList, 'filters.college_ids.*' => $ids,
            'filters.department_ids' => $idList, 'filters.department_ids.*' => $ids,
            'filters.program_ids' => $idList, 'filters.program_ids.*' => $ids,
            'filters.academic_year_ids' => $idList, 'filters.academic_year_ids.*' => $ids,
            'filters.semester_ids' => $idList, 'filters.semester_ids.*' => $ids,
            'filters.academic_level_ids' => $idList, 'filters.academic_level_ids.*' => $ids,
            'filters.course_ids' => $idList, 'filters.course_ids.*' => $ids,
            'filters.offering_ids' => $idList, 'filters.offering_ids.*' => $ids,
            'filters.student_status_codes' => ['sometimes','array','max:50'], 'filters.student_status_codes.*' => ['string','max:80'],
            'filters.result_status_codes' => ['sometimes','array','max:50'], 'filters.result_status_codes.*' => ['string','max:80'],
            'filters.offering_statuses' => ['sometimes','array','max:50'], 'filters.offering_statuses.*' => [Rule::in(['open','closed'])],
            'filters.grade_workflow_statuses' => ['sometimes','array','max:50'], 'filters.grade_workflow_statuses.*' => [Rule::in(['draft','submitted','returned','approved'])],
            'period' => ['sometimes','array'], 'period.type' => ['required_with:period', Rule::in(['academic','date_range'])],
            'period.academic_year_ids' => $idList, 'period.academic_year_ids.*' => $ids,
            'period.semester_ids' => $idList, 'period.semester_ids.*' => $ids,
            'period.date_from' => ['sometimes','date_format:Y-m-d'], 'period.date_to' => ['sometimes','date_format:Y-m-d','after_or_equal:period.date_from'],
            'comparison' => ['sometimes','array'], 'comparison.type' => ['required_with:comparison', Rule::in(ExecutiveReportRegistry::COMPARISONS)],
            'comparison.baseline' => ['sometimes','array'],
            'page' => ['sometimes','integer','min:1'], 'per_page' => ['sometimes','integer','min:1','max:100'],
            'sort' => ['sometimes','array'], 'sort.field' => ['required_with:sort','string'], 'sort.direction' => ['required_with:sort',Rule::in(['asc','desc'])],
        ];
        $rules['comparison.baseline.filters'] = ['sometimes','array'];
        foreach (['college_ids','department_ids','program_ids','academic_year_ids','semester_ids','academic_level_ids','course_ids','offering_ids'] as $key) {
            $rules["comparison.baseline.filters.{$key}"] = $idList;
            $rules["comparison.baseline.filters.{$key}.*"] = $ids;
        }
        foreach (['student_status_codes','result_status_codes'] as $key) {
            $rules["comparison.baseline.filters.{$key}"] = ['sometimes','array','max:50'];
            $rules["comparison.baseline.filters.{$key}.*"] = ['string','max:80'];
        }
        $rules['comparison.baseline.filters.offering_statuses'] = ['sometimes','array','max:50'];
        $rules['comparison.baseline.filters.offering_statuses.*'] = [Rule::in(['open','closed'])];
        $rules['comparison.baseline.filters.grade_workflow_statuses'] = ['sometimes','array','max:50'];
        $rules['comparison.baseline.filters.grade_workflow_statuses.*'] = [Rule::in(['draft','submitted','returned','approved'])];
        $rules['comparison.baseline.period'] = ['sometimes','array'];
        $rules['comparison.baseline.period.type'] = ['required_with:comparison.baseline.period',Rule::in(['academic','date_range'])];
        $rules['comparison.baseline.period.academic_year_ids'] = $idList;
        $rules['comparison.baseline.period.academic_year_ids.*'] = $ids;
        $rules['comparison.baseline.period.semester_ids'] = $idList;
        $rules['comparison.baseline.period.semester_ids.*'] = $ids;
        $rules['comparison.baseline.period.date_from'] = ['sometimes','date_format:Y-m-d'];
        $rules['comparison.baseline.period.date_to'] = ['sometimes','date_format:Y-m-d','after_or_equal:comparison.baseline.period.date_from'];
        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $unexpected = array_diff(array_keys($this->all()), self::TOP_KEYS);
        foreach ([['filters', self::FILTER_KEYS], ['period', self::PERIOD_KEYS], ['comparison', self::COMPARISON_KEYS], ['sort', self::SORT_KEYS]] as [$key,$allowed]) {
            if (is_array($this->input($key))) $unexpected = array_merge($unexpected, array_map(fn ($field) => "{$key}.{$field}", array_diff(array_keys($this->input($key)), $allowed)));
        }
        $baseline = $this->input('comparison.baseline');
        if (is_array($baseline)) {
            $unexpected = array_merge($unexpected, array_map(fn ($field) => "comparison.baseline.{$field}", array_diff(array_keys($baseline), self::BASELINE_KEYS)));
            foreach ([['filters', self::FILTER_KEYS], ['period', self::PERIOD_KEYS]] as [$key, $allowed]) {
                if (is_array($baseline[$key] ?? null)) $unexpected = array_merge($unexpected, array_map(fn ($field) => "comparison.baseline.{$key}.{$field}", array_diff(array_keys($baseline[$key]), $allowed)));
            }
        }
        if ($unexpected !== []) $validator->after(fn (Validator $v) => $v->errors()->add('request', 'Unsupported report fields: '.implode(', ', $unexpected)));
        $validator->after(function (Validator $v): void {
            $definition = ExecutiveReportRegistry::subject((string) $this->input('subject'));
            if (!$definition) return;
            $subject=(string)$this->input('subject');
            foreach ((array) $this->input('metrics', []) as $metric) if (!in_array($metric, $definition['metrics'], true)) $v->errors()->add('metrics', "Unsupported metric: {$metric}");
            foreach ((array) $this->input('dimensions', []) as $dimension) if (!in_array($dimension, $definition['dimensions'], true)) $v->errors()->add('dimensions', "Unsupported dimension: {$dimension}");
            if(!in_array((string)$this->input('mode'),ExecutiveReportRegistry::modes($subject),true))$v->errors()->add('mode','The selected subject does not support this report mode.');
            $periodType=$this->input('period.type','none');
            if(!in_array($periodType,ExecutiveReportRegistry::periods($subject),true))$v->errors()->add('period.type','The selected subject does not support this period type.');
            foreach (array_keys((array) $this->input('filters', [])) as $filter) if (!in_array($filter, $definition['filters'], true)) $v->errors()->add('filters', "Unsupported filter: {$filter}");
            foreach (array_keys((array) $this->input('comparison.baseline.filters', [])) as $filter) if (!in_array($filter, $definition['filters'], true)) $v->errors()->add('comparison.baseline.filters', "Unsupported filter: {$filter}");
            $total=collect([(array)$this->input('filters',[]),(array)$this->input('period',[]),(array)$this->input('comparison.baseline.filters',[]),(array)$this->input('comparison.baseline.period',[])])->sum(fn($scope)=>collect($scope)->filter('is_array')->sum(fn($values)=>count($values)));
            if ($total > ExecutiveReportRegistry::LIMITS['selected_ids']) $v->errors()->add('filters', 'Too many selected identifiers.');
            foreach(['academic_year_ids','semester_ids']as$key){$filter=array_values(array_unique((array)$this->input("filters.{$key}",[])));$period=array_values(array_unique((array)$this->input("period.{$key}",[])));sort($filter);sort($period);if($filter!==[]&&$period!==[]&&$filter!==$period)$v->errors()->add($key,'Filter and period selections conflict.');}
            $effectiveYears=array_unique(array_merge((array)$this->input('filters.academic_year_ids',[]),(array)$this->input('period.academic_year_ids',[])));
            $effectiveSemesters=array_unique(array_merge((array)$this->input('filters.semester_ids',[]),(array)$this->input('period.semester_ids',[])));
            if($effectiveSemesters!==[]&&$effectiveYears===[])$v->errors()->add('semester_ids','Semester selection requires an academic year context.');
            $comparisonType=$this->input('comparison.type');$baseline=$this->input('comparison.baseline');
            if($comparisonType==='custom'&&!is_array($baseline))$v->errors()->add('comparison.baseline','Custom comparison requires a complete baseline.');
            if($comparisonType!==null&&$comparisonType!=='custom'&&$baseline!==null)$v->errors()->add('comparison.baseline','Baseline is accepted only for custom comparison.');
            if(in_array($comparisonType,['previous_semester','previous_academic_year'],true)&&$periodType!=='academic')$v->errors()->add('comparison.type','The selected comparison requires an academic period.');
            if($comparisonType==='previous_period'&&$periodType!=='date_range')$v->errors()->add('comparison.type','Previous-period comparison requires a date range.');
            if(is_array($baseline)){
                if(!isset($baseline['filters'])&&!isset($baseline['period']))$v->errors()->add('comparison.baseline','Custom baseline must provide an explicit scope or period.');
                $baselineType=data_get($baseline,'period.type','none');if(!in_array($baselineType,ExecutiveReportRegistry::periods($subject),true))$v->errors()->add('comparison.baseline.period.type','The baseline period is unsupported for this subject.');
                $baselineYears=array_unique(array_merge((array)data_get($baseline,'filters.academic_year_ids',[]),(array)data_get($baseline,'period.academic_year_ids',[])));
                $baselineSemesters=array_unique(array_merge((array)data_get($baseline,'filters.semester_ids',[]),(array)data_get($baseline,'period.semester_ids',[])));
                if($baselineSemesters!==[]&&$baselineYears===[])$v->errors()->add('comparison.baseline.period.semester_ids','Baseline semester selection requires an academic year context.');
                foreach(['academic_year_ids','semester_ids']as$key){$a=array_values(array_unique((array)data_get($baseline,"filters.{$key}",[])));$b=array_values(array_unique((array)data_get($baseline,"period.{$key}",[])));sort($a);sort($b);if($a!==[]&&$b!==[]&&$a!==$b)$v->errors()->add("comparison.baseline.{$key}",'Baseline filter and period selections conflict.');}
                if($baselineType==='academic'&&empty(data_get($baseline,'period.academic_year_ids')))$v->errors()->add('comparison.baseline.period.academic_year_ids','Baseline academic period requires an academic year.');
                if($baselineType==='date_range'&&(!data_get($baseline,'period.date_from')||!data_get($baseline,'period.date_to')))$v->errors()->add('comparison.baseline.period','Baseline date range requires both boundaries.');
            }
            $sort=$this->input('sort.field');$sortable=$this->input('mode')==='details'?ExecutiveReportRegistry::detailSortable($subject):ExecutiveReportRegistry::sortable($subject);if($sort!==null&&!in_array($sort,$sortable,true))$v->errors()->add('sort.field','Unsupported sort field.');
            if($sort!==null&&!in_array($sort,array_merge((array)$this->input('metrics',[]),(array)$this->input('dimensions',[])),true))$v->errors()->add('sort.field','Sort field must be one of the selected metrics or dimensions.');
        });
    }
}
