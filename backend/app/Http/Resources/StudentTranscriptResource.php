<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Student */
class StudentTranscriptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'student_number' => $this->student_number,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'registered_courses' => StudentTranscriptEntryResource::collection($this->whenLoaded('studentCourseRegistrations')),
        ];
    }
}
