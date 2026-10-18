<?php
namespace App\Http\Requests\GradePart;
class ReturnGradePartRequest extends ReviewGradePartRequest
{
    public function rules(): array { return ['review_notes' => ['required', 'string', 'max:2000', 'regex:/\S/']]; }
}
