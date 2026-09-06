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
            'metrics' => ['required','array','min:1','max:'.ExecutiveReportRegistry::LIMITS['metrics']], 'metrics.*' => ['string'],
            'dimensions' => ['sometimes','array','max:'.ExecutiveReportRegistry::LIMITS['dimensions']], 'dimensions.*' => ['string'],
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
            foreach ((array) $this->input('metrics', []) as $metric) if (!in_array($metric, $definition['metrics'], true)) $v->errors()->add('metrics', "Unsupported metric: {$metric}");
            foreach ((array) $this->input('dimensions', []) as $dimension) if (!in_array($dimension, $definition['dimensions'], true)) $v->errors()->add('dimensions', "Unsupported dimension: {$dimension}");
            foreach (array_keys((array) $this->input('filters', [])) as $filter) if (!in_array($filter, $definition['filters'], true)) $v->errors()->add('filters', "Unsupported filter: {$filter}");
            foreach (array_keys((array) $this->input('comparison.baseline.filters', [])) as $filter) if (!in_array($filter, $definition['filters'], true)) $v->errors()->add('comparison.baseline.filters', "Unsupported filter: {$filter}");
            $total = collect((array) $this->input('filters', []))->filter('is_array')->sum(fn ($values) => count($values))
                + collect((array) $this->input('comparison.baseline.filters', []))->filter('is_array')->sum(fn ($values) => count($values));
            if ($total > ExecutiveReportRegistry::LIMITS['selected_ids']) $v->errors()->add('filters', 'Too many selected identifiers.');
        });
    }
}
