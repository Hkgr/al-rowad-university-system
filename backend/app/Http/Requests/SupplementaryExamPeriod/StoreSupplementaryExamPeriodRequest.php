<?php

namespace App\Http\Requests\SupplementaryExamPeriod;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplementaryExamPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'prohibited',
            'semester_id' => 'prohibited',
            'period_name' => 'prohibited',
            'start_date' => 'prohibited',
            'end_date' => 'prohibited',
            'is_active' => 'prohibited',
            'status' => 'prohibited',
            'opened_by_user_id' => 'prohibited',
            'opened_at' => 'prohibited',
            'created_at' => 'prohibited',
            'updated_at' => 'prohibited',
        ];
    }
}
