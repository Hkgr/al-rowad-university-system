<?php

namespace App\Http\Requests;

use App\Support\ExecutiveReportAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecutiveReportFilterRequest extends FormRequest
{
    private const RESOURCES = ['colleges','departments','programs','academic_years','semesters','academic_levels','courses','offerings','student_statuses','result_statuses','offering_statuses','grade_workflow_statuses'];
    private const KEYS = ['resource','q','college_id','department_id','program_id','academic_year_id','semester_id','page','per_page'];
    public function authorize(): bool { return app(ExecutiveReportAccess::class)->allows($this->user()); }
    public function rules(): array { return [
        'resource' => ['required',Rule::in(self::RESOURCES)], 'q' => ['sometimes','string','max:100'],
        'college_id' => ['sometimes','integer','min:1'], 'department_id' => ['sometimes','integer','min:1'],
        'program_id' => ['sometimes','integer','min:1'], 'academic_year_id' => ['sometimes','integer','min:1'],
        'semester_id' => ['sometimes','integer','min:1'], 'page' => ['sometimes','integer','min:1'],
        'per_page' => ['sometimes','integer','min:1','max:100'],
    ]; }
    public function withValidator(Validator $validator): void { $extra = array_diff(array_keys($this->query()), self::KEYS); if ($extra) $validator->after(fn ($v) => $v->errors()->add('query','Unsupported filter fields: '.implode(', ',$extra))); }
}
