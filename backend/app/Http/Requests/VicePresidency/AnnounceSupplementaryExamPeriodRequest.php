<?php

namespace App\Http\Requests\VicePresidency;

use App\Support\SupplementaryExamPeriodGovernance;
use Illuminate\Foundation\Http\FormRequest;

class AnnounceSupplementaryExamPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isScientificVicePresident()
            && $user->effectivePermissions()->contains(SupplementaryExamPeriodGovernance::PERMISSION_DECIDE);
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'period_name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'decision_note' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'status' => ['prohibited'],
            'is_active' => ['prohibited'],
            'opened_by_user_id' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'supplementary_exam_period_id' => ['prohibited'],
        ];
    }
}
