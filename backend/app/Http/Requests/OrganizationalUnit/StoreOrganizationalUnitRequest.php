<?php

namespace App\Http\Requests\OrganizationalUnit;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_code' => 'nullable|string|max:50',
            'unit_name' => 'required|string|max:200',
            'unit_type_id' => 'required|integer|exists:organizational_unit_types,unit_type_id',
            'parent_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id',
            'description' => 'nullable|string',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
