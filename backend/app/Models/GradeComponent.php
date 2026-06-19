<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeComponent extends Model
{
    protected $table = 'grade_components';

    protected $primaryKey = 'grade_component_id';

    protected $fillable = [
        'course_offering_id',
        'component_name',
        'component_type',
        'max_mark',
        'weight_percentage',
        'is_required',
        'exam_date',
        'status',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'max_mark' => 'decimal:2',
            'weight_percentage' => 'decimal:2',
            'is_required' => 'boolean',
            'exam_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function studentGradeComponents(): HasMany
    {
        return $this->hasMany(StudentGradeComponent::class, 'grade_component_id', 'grade_component_id');
    }

}
