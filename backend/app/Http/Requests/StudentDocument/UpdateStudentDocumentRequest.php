<?php

namespace App\Http\Requests\StudentDocument;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\StudentDocument;
use Illuminate\Support\Facades\Gate;

class UpdateStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('student_document');
        if (! $document instanceof StudentDocument) {
            $document = StudentDocument::query()->find($document);
        }

        return $document !== null && Gate::allows('update', $document);
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
