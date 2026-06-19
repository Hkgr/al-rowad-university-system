<?php

namespace App\Http\Requests\StudentDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'document_type_id' => 'sometimes|nullable|integer|exists:document_types,document_type_id',
            'file_name' => 'sometimes|nullable|string|max:255',
            'file_url' => 'sometimes|nullable|string|max:500',
            'verification_status' => 'sometimes|nullable|string|max:50',
            'verified_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'verified_at' => 'sometimes|nullable|date',
            'verification_notes' => 'sometimes|nullable|string|max:255',
            'uploaded_at' => 'sometimes|nullable|date',
        ];
    }
}
