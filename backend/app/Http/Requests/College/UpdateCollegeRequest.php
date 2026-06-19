<?php

namespace App\Http\Requests\College;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollegeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organizational_unit_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:organizational_units,organizational_unit_id',
                Rule::unique('colleges', 'organizational_unit_id')->ignoreModel($this->route('college'), 'college_id'),
            ],
            'college_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('colleges', 'college_code')->ignoreModel($this->route('college'), 'college_id'),
            ],
            'college_name' => 'sometimes|nullable|string|max:200',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
