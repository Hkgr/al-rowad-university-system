<?php

namespace App\Support;

use App\Models\SemesterOfferingRequest;
use App\Models\SemesterOfferingReview;

final readonly class SemesterOfferingOpeningProof
{
    public function __construct(
        public SemesterOfferingRequest $request,
        public SemesterOfferingReview $review,
        public int $actorUserId,
    ) {
    }
}
