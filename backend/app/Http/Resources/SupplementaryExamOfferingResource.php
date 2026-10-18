<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SupplementaryExamOffering */
class SupplementaryExamOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = $this->period;
        $program = $this->academicProgram;
        $course = $this->course;

        return [
            'supplementary_exam_offering_id' => $this->supplementary_exam_offering_id,
            'id' => $this->supplementary_exam_offering_id,
            'status' => $this->status,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'period' => $period === null ? null : [
                'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
                'id' => $period->supplementary_exam_period_id,
                'name' => $period->period_name,
                'status' => $period->status,
            ],
            'program' => $program === null ? null : [
                'academic_program_id' => $program->academic_program_id,
                'id' => $program->academic_program_id,
                'program_name' => $program->program_name,
                'program_code' => $program->program_code,
            ],
            'course' => $course === null ? null : [
                'course_id' => $course->course_id,
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
            ],
            'sources' => $this->whenLoaded('sources', function () {
                return $this->sources->map(function ($row) {
                    $source = $row->courseOffering;

                    return [
                        'course_offering_id' => $row->course_offering_id,
                        'semester_id' => $source?->semester_id,
                        'semester_name' => $source?->semester?->semester_name,
                        'semester_order' => $source?->semester?->semester_order,
                    ];
                })->values()->all();
            }),
            'opened_by' => $this->relationLoaded('openedBy') ? $this->safeUser($this->openedBy) : null,
            'closed_by' => $this->relationLoaded('closedBy') ? $this->safeUser($this->closedBy) : null,
        ];
    }

    private function safeUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
        ];
    }
}
