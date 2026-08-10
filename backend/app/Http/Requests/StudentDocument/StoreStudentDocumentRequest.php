<?php

namespace App\Http\Requests\StudentDocument;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\StudentDocument;
use Illuminate\Support\Facades\Gate;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', StudentDocument::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'document_type_id' => 'required|integer|exists:document_types,document_type_id',
            'file_name' => 'required|string|max:255',
            'file_url' => 'required|string|max:500',
            'verification_status' => 'nullable|string|max:50',
            'verified_by_user_id' => 'prohibited',
            'verified_at' => 'prohibited',
            'verification_notes' => 'nullable|string|max:255',
            'uploaded_at' => 'nullable|date',
        ];
    }
}
