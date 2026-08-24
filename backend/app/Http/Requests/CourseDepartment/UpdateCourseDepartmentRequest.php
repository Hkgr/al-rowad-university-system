<?php

namespace App\Http\Requests\CourseDepartment;

use App\Models\CourseDepartment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentId = $this->currentCourseDepartmentId();
        $payload = $this->all();
        $departmentId = $this->input('department_id');

        if (array_key_exists('course_id', $payload)
            && ! array_key_exists('department_id', $payload)
            && $currentId !== null) {
            $departmentId = CourseDepartment::query()
                ->whereKey($currentId)
                ->value('department_id');
        }

        return [
            'course_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:courses,course_id',
                Rule::unique('course_departments', 'course_id')
                    ->where(fn ($query) => $query->where('department_id', $departmentId))
                    ->ignore($currentId, 'course_department_id'),
            ],
            'department_id' => 'sometimes|nullable|integer|exists:departments,department_id',
            'is_primary' => 'sometimes|nullable|boolean',
        ];
    }

    private function currentCourseDepartmentId(): ?int
    {
        $parameter = $this->route('course_department');
        if ($parameter instanceof CourseDepartment) {
            $parameter = $parameter->getKey();
        }

        if (! is_int($parameter) && ! (is_string($parameter) && ctype_digit($parameter))) {
            return null;
        }

        $currentId = (int) $parameter;

        return $currentId > 0 ? $currentId : null;
    }
}
