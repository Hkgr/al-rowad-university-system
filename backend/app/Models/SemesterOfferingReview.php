<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemesterOfferingReview extends Model
{
    protected $primaryKey = 'semester_offering_review_id';

    protected $fillable = [
        'semester_offering_request_id', 'submission_version', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'reason',
    ];

    protected function casts(): array
    {
        return ['submission_version' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function request(): BelongsTo { return $this->belongsTo(SemesterOfferingRequest::class, 'semester_offering_request_id', 'semester_offering_request_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id'); }
}
