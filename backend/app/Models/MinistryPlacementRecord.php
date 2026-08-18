<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinistryPlacementRecord extends Model
{
    protected $table = 'ministry_placement_records';

    protected $primaryKey = 'placement_record_id';

    protected $fillable = [
        'batch_id',
        'row_number',
        'national_civil_id',
        'subscription_number',
        'first_name',
        'last_name',
        'father_name',
        'mother_name',
        'date_of_birth',
        'gender',
        'nationality',
        'phone_number',
        'email',
        'certificate_type',
        'certificate_source_country',
        'certificate_grant_year',
        'directorate',
        'total_score',
        'max_total_score',
        'accepted_preference_text',
        'matched_academic_program_id',
        'track',
        'placement_round_name',
        'registration_type',
        'is_faculty_member_child',
        'has_academic_sequence',
        'applicant_id',
        'processing_status',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'total_score' => 'decimal:2',
            'max_total_score' => 'decimal:2',
            'is_faculty_member_child' => 'boolean',
            'has_academic_sequence' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn (): string => trim(($this->first_name ?? '').' '.($this->last_name ?? ''))
        );
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MinistryPlacementBatch::class, 'batch_id', 'batch_id');
    }

    public function matchedAcademicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'matched_academic_program_id', 'academic_program_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id', 'applicant_id');
    }
}
