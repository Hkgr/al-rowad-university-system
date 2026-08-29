<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Services\MinistryPlacementReconciliationService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileMinistryPlacementRequest extends FormRequest
{
    private const GLOBAL_KEYS = ['batch_id', 'severity', 'pipeline_state', 'issue_code', 'page', 'per_page'];

    private const BATCH_KEYS = ['severity', 'pipeline_state', 'issue_code', 'page', 'per_page'];

    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canView($this->user());
    }

    public function rules(): array
    {
        return [
            'batch_id' => [$this->route('batch') === null ? 'sometimes' : 'prohibited', 'integer', 'min:1'],
            'severity' => ['sometimes', Rule::in(['clean', 'warning', 'blocked'])],
            'pipeline_state' => ['sometimes', Rule::in(['imported', 'matched', 'applicant_pending', 'documents_pending', 'enrolled', 'rejected', 'inconsistent'])],
            'issue_code' => ['sometimes', Rule::in(MinistryPlacementReconciliationService::issueCodes())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = $this->route('batch') === null ? self::GLOBAL_KEYS : self::BATCH_KEYS;
        $unexpected = array_values(array_diff(array_keys($this->query->all()), $allowed));
        if ($unexpected !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('query', 'تحتوي معلمات التدقيق على حقول غير مسموحة: '.implode(', ', $unexpected)));
        }
    }
}
