<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CorrectAccountHolderNameRequest extends FormRequest
{
    use RejectsServerOwnedFields;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::HOLDER_NAME_MANAGE) === true;
    }

    public function rules(): array
    {
        return [
            'person_type' => ['required', Rule::in(['employee', 'student'])],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->rejectUnknownFields($v, ['person_type', 'first_name', 'last_name', 'father_name', 'mother_name']));
    }

    public function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'max' => 'القيمة أطول من المسموح (100 محرف).',
            'person_type.in' => 'نوع السجل غير معروف.',
        ];
    }
}
