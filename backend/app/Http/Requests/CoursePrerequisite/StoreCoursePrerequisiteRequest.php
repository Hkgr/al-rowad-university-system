<?php

namespace App\Http\Requests\CoursePrerequisite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCoursePrerequisiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => [
                'required',
                'integer',
                'exists:courses,course_id',
                Rule::unique('course_prerequisites', 'course_id')->where(fn ($query) => $query->where('prerequisite_course_id', $this->input('prerequisite_course_id'))),
            ],
            'prerequisite_course_id' => 'required|integer|exists:courses,course_id|different:course_id',
            'minimum_result_status_id' => 'nullable|integer|exists:result_statuses,result_status_id',
        ];
    }
}
        ];
    }
}
