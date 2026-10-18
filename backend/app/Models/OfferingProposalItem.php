<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferingProposalItem extends Model
{
    public const EXCEPTION_REASON = 'استثناء تلقائي: مادة إجبارية بدون مدرس، تُفتح للتسجيل إلى حين تأمين مدرس.';

    protected $table = 'offering_proposal_items';

    protected $primaryKey = 'offering_proposal_item_id';

    protected $fillable = [
        'offering_proposal_id',
        'course_id',
        'academic_program_id',
        'course_type',
        'faculty_member_id',
        'instructor_exception',
        'exception_reason',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'instructor_exception' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(OfferingProposal::class, 'offering_proposal_id', 'offering_proposal_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }
}
