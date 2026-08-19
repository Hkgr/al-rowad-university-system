<?php

namespace App\Http\Requests\Dean;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPrepareDeanRegistrationOfferingRequest extends FormRequest
{
    public const MODE_ADVISORY_SEMESTER = 'advisory_semester';

    public const MODE_ADVISORY_LEVEL = 'advisory_level';

    public const MODE_ALL_CURRICULUM = 'all_curriculum';

    public const MODE_SELECTED = 'selected';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_program_id' => ['required', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'academic_year_id' => ['required', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'mode' => ['required', 'string', Rule::in([
                self::MODE_ADVISORY_SEMESTER,
                self::MODE_ADVISORY_LEVEL,
                self::MODE_ALL_CURRICULUM,
                self::MODE_SELECTED,
            ])],
            'academic_level_id' => ['required_if:mode,'.self::MODE_ADVISORY_LEVEL, 'prohibited_unless:mode,'.self::MODE_ADVISORY_LEVEL, 'integer', 'min:1'],
            'program_course_ids' => ['required_if:mode,'.self::MODE_SELECTED, 'prohibited_unless:mode,'.self::MODE_SELECTED, 'array', 'min:1'],
            'program_course_ids.*' => ['integer', 'min:1'],
            'college_id' => ['prohibited'],
            'course_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'faculty_member_id' => ['prohibited'],
            'status' => ['prohibited'],
            'capacity' => ['prohibited'],
            'exceptional' => ['prohibited'],
            'force' => ['prohibited'],
            'skip_coverage' => ['prohibited'],
            'bypass' => ['prohibited'],
        ];
    }
}
