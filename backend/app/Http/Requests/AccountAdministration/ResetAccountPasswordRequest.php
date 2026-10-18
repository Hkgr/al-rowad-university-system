<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class ResetAccountPasswordRequest extends FormRequest
{
    use RejectsServerOwnedFields;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountAdministration::MANAGE) === true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->rejectUnknownFields($v, ['password', 'password_confirmation']));
    }

    public function messages(): array
    {
        return [
            'password.required' => 'أدخل كلمة المرور الجديدة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 10 محارف.',
            'password.letters' => 'كلمة المرور يجب أن تحتوي على حرف.',
            'password.mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير.',
            'password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم.',
        ];
    }
}
