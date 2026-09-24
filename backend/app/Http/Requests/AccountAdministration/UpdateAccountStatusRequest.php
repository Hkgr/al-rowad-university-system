<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::MANAGE) === true;
    }

    public function rules(): array
    {
        return [
            'account_status' => ['required', 'string', Rule::in(AccountAdministration::STATUSES)],
            'account_status_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_status.required' => 'حالة الحساب مطلوبة.',
            'account_status.in' => 'حالة الحساب يجب أن تكون مفعّلًا أو معطّلًا.',
            '*.prohibited' => 'هذا الحقل يُحدَّد على الخادم ولا يُقبل من الواجهة.',
        ];
    }
}
