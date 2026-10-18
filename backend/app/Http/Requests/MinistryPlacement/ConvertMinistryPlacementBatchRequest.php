<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Exceptions\MinistryPlacementException;
use App\Support\MinistryPlacementAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConvertMinistryPlacementBatchRequest extends FormRequest
{
    private const ALLOWED_KEYS = [
        'expected_eligible_count',
        'expected_snapshot',
    ];

    /** @var array<int, string> */
    private array $unexpectedKeys = [];

    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    public function rules(): array
    {
        return [
            'expected_eligible_count' => ['required', 'integer', 'min:0'],
            'expected_snapshot' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->unexpectedKeys = array_values(array_diff(array_keys($this->all()), self::ALLOWED_KEYS));
        if ($this->unexpectedKeys !== []) {
            $validator->after(function (Validator $validator): void {
                $validator->errors()->add('payload', 'ministry_placement_conversion_batch_payload_not_allowed');
            });
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->unexpectedKeys !== []) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_conversion_batch_payload_not_allowed',
                'يقبل التحويل الجماعي عدد السجلات الجاهزة وبصمتها فقط.',
                ['unexpected_fields' => $this->unexpectedKeys],
                422,
            );
        }

        parent::failedValidation($validator);
    }
}
