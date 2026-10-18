<?php

namespace App\Http\Requests\SupplementaryExamGrading;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSupplementaryExamGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'min:1'],
            'marks.*' => ['required', 'array:supplementary_exam_registration_id,theoretical_mark'],
            'marks.*.supplementary_exam_registration_id' => ['required', 'integer', 'distinct'],
            'marks.*.theoretical_mark' => ['required', 'numeric'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['marks']) as $field) {
                $validator->errors()->add((string) $field, 'هذا الحقل غير مسموح في طلب العلامات التكميلية.');
            }
        }];
    }
}
