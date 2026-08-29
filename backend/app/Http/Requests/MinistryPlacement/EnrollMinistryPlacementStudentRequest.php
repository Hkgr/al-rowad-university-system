<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Exceptions\MinistryPlacementException;
use App\Support\MinistryPlacementAccess;
use App\Support\MinistryPlacementNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class EnrollMinistryPlacementStudentRequest extends FormRequest
{
    private const ALLOWED_KEYS = [
        'student_number',
        'current_academic_level_id',
        'enrollment_date',
    ];

    /** @var array<int, string> */
    private array $unexpectedKeys = [];

    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    protected function prepareForValidation(): void
    {
        $studentNumber = $this->input('student_number');
        if (is_string($studentNumber)) {
            $this->merge(['student_number' => MinistryPlacementNormalizer::text($studentNumber)]);
        }
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50'],
            'current_academic_level_id' => ['required', 'integer'],
            'enrollment_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->unexpectedKeys = array_values(array_diff(array_keys($this->all()), self::ALLOWED_KEYS));
        if ($this->unexpectedKeys !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('payload', 'ministry_placement_enrollment_payload_not_allowed'));
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->unexpectedKeys !== []) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_enrollment_payload_not_allowed',
                'يقبل إنشاء الطالب رقم الطالب والمستوى الأكاديمي وتاريخ التسجيل فقط.',
                ['unexpected_fields' => $this->unexpectedKeys],
                422,
            );
        }

        parent::failedValidation($validator);
    }
}
