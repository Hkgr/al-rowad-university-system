<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;

class AssignAccountRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::MANAGE) === true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,role_id'],
            'assigned_by_user_id' => ['prohibited'],
            'assigned_at' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required' => 'اختر الدور المطلوب إسناده.',
            'role_id.exists' => 'الدور المحدد غير موجود.',
            '*.prohibited' => 'هذا الحقل يُحدَّد على الخادم ولا يُقبل من الواجهة.',
        ];
    }
}
