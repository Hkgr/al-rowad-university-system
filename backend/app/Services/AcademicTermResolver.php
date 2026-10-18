<?php

namespace App\Services;

use App\Models\AcademicYear;

class AcademicTermResolver
{
    public function uniqueCurrentAcademicYear(): ?AcademicYear
    {
        $currentYears = AcademicYear::query()->where('is_current', true)->get();

        return $currentYears->count() === 1 ? $currentYears->first() : null;
    }

    public function uniqueCurrentAcademicYearId(): ?int
    {
        $year = $this->uniqueCurrentAcademicYear();

        return $year === null ? null : (int) $year->academic_year_id;
    }
}
