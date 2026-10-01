<?php

namespace App\Http\Requests\Grade;

use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncompleteRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
            'requirements' => ['required', 'string', 'max:2000'],
            'deadline' => ['required', 'date_format:Y-m-d', 'after:now'],
        ];
    }
}
