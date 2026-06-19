<?php

namespace App\Http\Requests\StudentDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'document_type_id' => 'required|integer|exists:document_types,document_type_id',
            'file_name' => 'required|string|max:255',
            'file_url' => 'required|string|max:500',
            'verification_status' => 'nullable|string|max:50',
            'verified_by_user_id' => 'nullable|integer|exists:users,user_id',
            'verified_at' => 'nullable|date',
            'verification_notes' => 'nullable|string|max:255',
            'uploaded_at' => 'nullable|date',
        ];
    }
}
