<?php

namespace App\Http\Requests\Dean;

use App\Support\SupplementaryExamOfferingGovernance;
use Illuminate\Foundation\Http\FormRequest;

class CatalogSupplementaryExamOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isDean()
            && $user->effectivePermissions()->contains(SupplementaryExamOfferingGovernance::PERMISSION_VIEW);
    }

    public function rules(): array
    {
        return [
            'supplementary_exam_period_id' => ['required', 'integer', 'min:1', 'exists:supplementary_exam_periods,supplementary_exam_period_id'],
            'academic_program_id' => ['required', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
        ];
    }
}
