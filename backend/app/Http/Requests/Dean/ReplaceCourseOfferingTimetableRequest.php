<?php

namespace App\Http\Requests\Dean;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReplaceCourseOfferingTimetableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'slots' => ['required', 'array', 'max:100'],
            'slots.*' => ['required', 'array:component_type,day_of_week,start_time,end_time,location_label'],
            'slots.*.component_type' => ['required', 'string', Rule::in(['theoretical', 'practical'])],
            'slots.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'slots.*.location_label' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['slots']) as $field) {
                $validator->errors()->add((string) $field, 'هذا الحقل غير مسموح في طلب الجدول الأسبوعي.');
            }
        }];
    }
}
