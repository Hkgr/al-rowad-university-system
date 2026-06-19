<?php

namespace App\Http\Requests\GradeComponent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'sometimes|nullable|integer|exists:course_offerings,course_offering_id',
            'component_name' => 'sometimes|nullable|string|max:150',
            'component_type' => 'sometimes|nullable|string|max:50',
            'max_mark' => 'sometimes|nullable|numeric',
            'weight_percentage' => 'sometimes|nullable|numeric',
            'is_required' => 'sometimes|nullable|integer',
            'exam_date' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|string|max:50',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
