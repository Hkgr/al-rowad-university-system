<?php

namespace App\Http\Requests\OrganizationalUnit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_code' => 'sometimes|nullable|string|max:50',
            'unit_name' => 'sometimes|nullable|string|max:200',
            'unit_type_id' => 'sometimes|nullable|integer|exists:organizational_unit_types,unit_type_id',
            'parent_unit_id' => 'sometimes|nullable|integer|exists:organizational_units,organizational_unit_id',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
