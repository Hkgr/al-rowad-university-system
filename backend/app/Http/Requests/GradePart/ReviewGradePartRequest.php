<?php
namespace App\Http\Requests\GradePart;
use Illuminate\Foundation\Http\FormRequest;
class ReviewGradePartRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('exams.manage') === true; }
    public function rules(): array { return ['review_notes' => ['nullable', 'string', 'max:2000']]; }
}
