<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Support\MinistryPlacementAccess;
use Illuminate\Foundation\Http\FormRequest;

class PreviewMinistryPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }
}
