<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Exceptions\MinistryPlacementException;
use App\Support\MinistryPlacementAccess;
use App\Support\MinistryPlacementNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class EnrollMinistryPlacementBatchRequest extends FormRequest
{
    private const ALLOWED_KEYS = ['expected_eligible_count', 'expected_snapshot', 'items'];

    private const ALLOWED_ITEM_KEYS = ['placement_record_id', 'student_number', 'current_academic_level_id', 'enrollment_date'];

    /** @var array<int, string> */
    private array $unexpectedKeys = [];

    /** @var array<int, array{index: int, fields: array<int, string>}> */
    private array $unexpectedItemKeys = [];

    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $items = array_map(function ($item) {
                if (is_array($item) && isset($item['student_number']) && is_string($item['student_number'])) {
                    $item['student_number'] = MinistryPlacementNormalizer::text($item['student_number']);
                }

                return $item;
            }, $this->input('items'));
            $this->merge(['items' => $items]);
        }
    }

    public function rules(): array
    {
        return [
            'expected_eligible_count' => ['required', 'integer', 'min:0'],
            'expected_snapshot' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'items' => ['required', 'array'],
            'items.*' => ['required', 'array'],
            'items.*.placement_record_id' => ['required', 'integer', 'distinct'],
            'items.*.student_number' => ['required', 'string', 'max:50'],
            'items.*.current_academic_level_id' => ['required', 'integer'],
            'items.*.enrollment_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->unexpectedKeys = array_values(array_diff(array_keys($this->all()), self::ALLOWED_KEYS));
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $unexpected = array_values(array_diff(array_keys($item), self::ALLOWED_ITEM_KEYS));
            if ($unexpected !== []) {
                $this->unexpectedItemKeys[] = ['index' => (int) $index, 'fields' => $unexpected];
            }
        }
        if ($this->unexpectedKeys !== [] || $this->unexpectedItemKeys !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('payload', 'ministry_placement_enrollment_batch_payload_not_allowed'));
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->unexpectedKeys !== [] || $this->unexpectedItemKeys !== []) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_enrollment_batch_payload_not_allowed',
                'تحتوي حمولة إنشاء الطلاب الجماعية حقولاً غير مسموحة.',
                ['unexpected_fields' => $this->unexpectedKeys, 'unexpected_item_fields' => $this->unexpectedItemKeys],
                422,
            );
        }

        parent::failedValidation($validator);
    }
}
