<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentAcademicTerm extends Model
{
    protected $table = 'student_academic_terms';

    protected $primaryKey = 'student_academic_term_id';

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'semester_id',
        'academic_level_id',
        'term_gpa',
        'cumulative_gpa',
        'total_registered_hours',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'term_gpa' => 'decimal:2',
            'cumulative_gpa' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id', 'academic_level_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

}
