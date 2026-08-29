<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Exceptions\MinistryPlacementException;
use App\Support\MinistryPlacementAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConvertMinistryPlacementApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->all() !== []) {
                $validator->errors()->add('payload', 'ministry_placement_conversion_payload_not_allowed');
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        throw MinistryPlacementException::conversionConflict(
            'ministry_placement_conversion_payload_not_allowed',
            'لا يقبل تحويل سجل المفاضلة أي بيانات إدخال؛ تُحسم جميع القيم من السجل المقفل.',
            $validator->errors()->toArray(),
            422,
        );
    }
}
