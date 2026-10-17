<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Advisory metadata only: never an offering/registration eligibility exemption. */
final class AcademicAdvisoryPlacement
{
    public static function validate(array $membership): void
    {
        $level = $membership['academic_level_id'] ?? null;
        $semester = $membership['recommended_semester_id'] ?? null;
        if (($level === null) !== ($semester === null) || ($level === null && ($membership['course_type'] ?? null) !== 'elective')) {
            throw ValidationException::withMessages(['academic_level_id' => 'حدد السنة والفصل معًا للمادة الإجبارية؛ يجوز تركهما معًا دون توزيع للبديل الاختياري فقط.']);
        }
        if ($level === null) AcademicPlacementSchema::assertReady();
    }
}
