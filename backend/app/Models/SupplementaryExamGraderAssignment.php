<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplementaryExamGraderAssignment extends Model
{
    protected $primaryKey = 'supplementary_exam_grader_assignment_id';
    protected $guarded = [];
    protected function casts(): array { return ['current_slot' => 'integer', 'assigned_at' => 'datetime', 'ended_at' => 'datetime']; }
    public function offering() { return $this->belongsTo(SupplementaryExamOffering::class, 'supplementary_exam_offering_id'); }
    public function facultyMember() { return $this->belongsTo(FacultyMember::class, 'faculty_member_id'); }
}
