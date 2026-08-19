<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseOfferingClosureReview extends Model
{
    protected $table = 'course_offering_closure_reviews';

    protected $primaryKey = 'course_offering_closure_review_id';

    protected $fillable = [
        'course_offering_closure_request_id',
        'submission_version',
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
            'submission_version' => 'integer',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            CourseOfferingClosureRequest::class,
            'course_offering_closure_request_id',
            'course_offering_closure_request_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }
}
