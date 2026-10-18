<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignmentReview extends Model
{
    protected $table = 'teaching_assignment_reviews';

    protected $primaryKey = 'teaching_assignment_review_id';

    protected $fillable = [
        'teaching_assignment_request_id',
        'review_authority',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'reason',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TeachingAssignmentRequest::class, 'teaching_assignment_request_id', 'teaching_assignment_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }
}
