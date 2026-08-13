<?php

namespace App\Http\Requests\GradeApproval;

class ReturnGradeApprovalRequest extends ApproveGradeApprovalRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'approval_notes' => ['required', 'string', 'max:2000', 'regex:/\\S/'],
        ]);
    }
}
