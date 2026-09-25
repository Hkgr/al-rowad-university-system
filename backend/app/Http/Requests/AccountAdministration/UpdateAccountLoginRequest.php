<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountLoginRequest extends FormRequest
{
    use RejectsServerOwnedFields;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::MANAGE) === true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('username'))) {
            $normalized['username'] = trim($this->input('username'));
        }
        if (is_string($this->input('email'))) {
            $normalized['email'] = mb_strtolower(trim($this->input('email')));
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'username' => ['required_without:email', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['required_without:username', 'string', 'email', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->rejectUnknownFields($v, ['username', 'email']));
    }

    public function messages(): array
    {
        return [
            'username.required_without' => 'أدخل اسم مستخدم جديدًا أو بريدًا جديدًا.',
            'email.required_without' => 'أدخل اسم مستخدم جديدًا أو بريدًا جديدًا.',
            'username.min' => 'اسم المستخدم 3 محارف على الأقل.',
            'username.max' => 'اسم المستخدم أطول من المسموح.',
            'username.regex' => 'اسم المستخدم يقبل الأحرف اللاتينية والأرقام والنقطة و- و_ فقط.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.max' => 'البريد الإلكتروني أطول من المسموح.',
        ];
    }
}
