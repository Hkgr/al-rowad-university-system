<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentRegistrationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student' => StudentResource::make($this->resource['student'] ?? null),
            'academic_year' => AcademicYearResource::make($this->resource['academic_year'] ?? null),
            'semester' => SemesterResource::make($this->resource['semester'] ?? null),
            'academic_year_id' => $this->resource['academic_year_id'] ?? null,
            'semester_id' => $this->resource['semester_id'] ?? null,
            'total_registered_courses' => $this->resource['total_registered_courses'] ?? 0,
            'total_registered_hours' => $this->resource['total_registered_hours'] ?? 0,
            'max_allowed_hours' => $this->resource['max_allowed_hours'] ?? 0,
            'remaining_hours' => $this->resource['remaining_hours'] ?? 0,
            'registrations' => RegistrationSummaryItemResource::collection($this->resource['registrations'] ?? []),
        ];
    }
}
