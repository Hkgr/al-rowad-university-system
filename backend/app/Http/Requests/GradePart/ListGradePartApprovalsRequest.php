<?php
namespace App\Http\Requests\GradePart;
use Illuminate\Foundation\Http\FormRequest;
class ListGradePartApprovalsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('exams.manage') === true; }
    public function rules(): array { return ['status' => ['nullable', 'in:draft,submitted,returned,approved'], 'component_type' => ['nullable', 'in:practical,theoretical'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]; }
}
