<?php

namespace App\Http\Requests\DocumentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_code' => 'required|string|max:50|unique:document_types,type_code',
            'type_name' => 'required|string|max:150',
            'is_required' => 'required|boolean',
            'description' => 'nullable|string|max:255',
            'is_active' => 'required|boolean',
        ];
    }
}
