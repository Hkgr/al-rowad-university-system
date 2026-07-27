<?php

namespace App\Http\Requests\Grade;

use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = StudentCourseRegistration::query()->find($this->route('id'));
        if ($registration === null || $this->user() === null) {
            return false;
        }

        app(AcademicAuthorizationService::class)->assertCanEnterGrades($this->user(), $registration);

        return true;
    }

    public function rules(): array
    {
        return [
            'theoretical_mark' => ['required', 'numeric', 'min:0', 'max:60'],
            'practical_mark' => ['required', 'numeric', 'min:0', 'max:40'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
