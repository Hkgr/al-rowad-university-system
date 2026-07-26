<?php

namespace App\Http\Requests\StudentDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Support\Facades\Gate;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = Student::query()->find($this->input('student_id'));

        return $student !== null && Gate::allows('createFor', [StudentDocument::class, $student]);
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'document_type_id' => 'required|integer|exists:document_types,document_type_id',
            'file_name' => 'required|string|max:255',
            'file_url' => 'required|string|max:500',
            'verification_status' => $this->administrativeRule('nullable|string|max:50'),
            'verified_by_user_id' => $this->administrativeRule('nullable|integer|exists:users,user_id'),
            'verified_at' => $this->administrativeRule('nullable|date'),
            'verification_notes' => $this->administrativeRule('nullable|string|max:255'),
            'uploaded_at' => $this->administrativeRule('nullable|date'),
        ];
    }

    private function administrativeRule(string $staffRule): string
    {
        return $this->user()?->student_id !== null ? 'prohibited' : $staffRule;
    }
}
