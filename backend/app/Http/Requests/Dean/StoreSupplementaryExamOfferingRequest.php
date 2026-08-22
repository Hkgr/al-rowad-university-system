<?php

namespace App\Http\Requests\Dean;

use App\Support\SupplementaryExamOfferingGovernance;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplementaryExamOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isDean()
            && $user->effectivePermissions()->contains(SupplementaryExamOfferingGovernance::PERMISSION_MANAGE);
    }

    public function rules(): array
    {
        return [
            'supplementary_exam_period_id' => ['required', 'integer', 'min:1', 'exists:supplementary_exam_periods,supplementary_exam_period_id'],
            'academic_program_id' => ['required', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'course_id' => ['required', 'integer', 'min:1', 'exists:courses,course_id'],
            'status' => ['prohibited'],
            'opened_by_user_id' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'closed_by_user_id' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'source_course_offering_ids' => ['prohibited'],
            'course_offering_id' => ['prohibited'],
            'college_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'supplementary_exam_offering_id' => ['prohibited'],
        ];
    }
}
