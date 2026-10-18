<?php

namespace App\Http\Requests\AccountAdministration;

use App\Models\User;
use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::MANAGE) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => is_string($this->input('username')) ? trim($this->input('username')) : $this->input('username'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'account_status' => ['required', 'string', Rule::in(AccountAdministration::STATUSES)],
            'role_ids' => ['sometimes', 'array', 'max:20'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,role_id'],
            'password_hash' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'account_status_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'board_member_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $username = $this->input('username');
            if (is_string($username) && ! $validator->errors()->has('username')
                && User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->exists()) {
                $validator->errors()->add('username', 'اسم المستخدم مستخدم مسبقًا.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'اسم المستخدم مطلوب.',
            'username.min' => 'اسم المستخدم قصير جدًا.',
            'username.max' => 'اسم المستخدم طويل جدًا.',
            'username.regex' => 'اسم المستخدم يقبل الأحرف اللاتينية والأرقام والنقطة و- و_ فقط.',
            'username.unique' => 'اسم المستخدم مستخدم مسبقًا.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.max' => 'البريد الإلكتروني طويل جدًا.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقًا.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 10 محارف.',
            'password.letters' => 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.',
            'password.mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير.',
            'password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.',
            'account_status.required' => 'حالة الحساب مطلوبة.',
            'account_status.in' => 'حالة الحساب يجب أن تكون مفعّلًا أو معطّلًا.',
            'role_ids.*.exists' => 'أحد الأدوار المحددة غير موجود.',
            'role_ids.*.distinct' => 'لا يمكن تكرار الدور نفسه.',
            'password_hash.prohibited' => 'لا يُقبل password_hash من الواجهة؛ أرسل password فقط.',
            '*.prohibited' => 'هذا الحقل يُحدَّد على الخادم ولا يُقبل من الواجهة.',
        ];
    }
}
