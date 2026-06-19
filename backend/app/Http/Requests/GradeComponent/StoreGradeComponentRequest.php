<?php

namespace App\Http\Requests\GradeComponent;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'required|integer|exists:course_offerings,course_offering_id',
            'component_name' => 'required|string|max:150',
            'component_type' => 'required|string|max:50',
            'max_mark' => 'nullable|numeric',
            'weight_percentage' => 'nullable|numeric',
            'is_required' => 'required|integer',
            'exam_date' => 'nullable|date',
            'status' => 'required|string|max:50',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
