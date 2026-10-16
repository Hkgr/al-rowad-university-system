<?php

namespace App\Http\Requests\Grade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IncompleteOperationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'reason' => ['sometimes', 'required', 'string', 'max:4000'], 'requirements' => ['sometimes', 'required', 'string', 'max:4000'],
            'expiry_outcome' => ['sometimes', 'required', 'in:F,Z'], 'deadline_at' => ['sometimes', 'required', 'date'],
            'components' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'components.*.grade_component_id' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.mark' => ['required', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2']];
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) $validator->errors()->add($key, 'Unknown field.');
            $components = $this->input('components', []);
            if (is_array($components)) foreach ($components as $item) if (is_array($item) && array_diff(array_keys($item), ['grade_component_id', 'mark'])) $validator->errors()->add('components', 'Unknown component field.');
        }];
    }
}
