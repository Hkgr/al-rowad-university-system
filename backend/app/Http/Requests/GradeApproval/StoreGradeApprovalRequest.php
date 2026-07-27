<?php

namespace App\Http\Requests\GradeApproval;

use App\Models\ApprovalStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreGradeApprovalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('approval_status_code')) {
            $id = ApprovalStatus::query()->where('status_code', $this->string('approval_status_code')->toString())->value('approval_status_id');
            if ($id !== null) {
                $this->merge(['approval_status_id' => $id]);
            }
        }
    }
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'required|integer|exists:course_offerings,course_offering_id',
            'approval_status_id' => 'required|integer|exists:approval_statuses,approval_status_id',
            'approval_status_code' => 'sometimes|required|string|exists:approval_statuses,status_code',
            'submitted_by_user_id' => 'required|integer|exists:users,user_id',
            'submitted_at' => 'nullable|date',
            'approved_by_user_id' => 'nullable|integer|exists:users,user_id',
            'approval_role' => 'nullable|string|max:100',
            'approval_date' => 'nullable|date',
            'approval_notes' => 'nullable|string',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
