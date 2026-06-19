<?php

namespace App\Http\Requests\DocumentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('document_types', 'type_code')->ignoreModel($this->route('document_type'), 'document_type_id'),
            ],
            'type_name' => 'sometimes|nullable|string|max:150',
            'is_required' => 'sometimes|nullable|boolean',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
