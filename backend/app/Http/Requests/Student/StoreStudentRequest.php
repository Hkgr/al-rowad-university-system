<?php

namespace App\Http\Requests\Student;

use App\Models\AdmissionApplication;
use App\Models\MinistryPlacementRecord;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Student::class);
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50', 'unique:students,student_number'],

            'admission_application_id' => ['required', 'integer', 'exists:admission_applications,admission_application_id'],

            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'father_name' => ['nullable', 'string', 'max:100'],
            'mother_name' => ['nullable', 'string', 'max:100'],

            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],

            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150', 'unique:students,email'],

            'address' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],

            'academic_program_id' => ['required', 'integer', 'exists:academic_programs,academic_program_id'],
            'current_academic_level_id' => ['required', 'integer', 'exists:academic_levels,academic_level_id'],
            'enrollment_date' => ['required', 'date'],
            'student_status_id' => ['required', 'integer', 'exists:student_statuses,student_status_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $applicationId = $this->input('admission_application_id');

            if ($applicationId === null || $applicationId === '') {
                return;
            }

            $application = AdmissionApplication::query()->find($applicationId);

            if ($application === null) {
                return;
            }

            if ($application->decision_status !== 'accepted') {
                $validator->errors()->add(
                    'admission_application_id',
                    'A new student must reference an accepted admission application that traces back to an imported Ministry placement record.'
                );

                return;
            }

            $hasPlacementRecord = MinistryPlacementRecord::query()
                ->where('applicant_id', $application->applicant_id)
                ->exists();

            if (! $hasPlacementRecord) {
                $validator->errors()->add(
                    'admission_application_id',
                    'A new student must reference an accepted admission application that traces back to an imported Ministry placement record.'
                );
            }
        });
    }
}
