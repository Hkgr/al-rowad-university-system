<?php

namespace App\Services;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Models\CourseOffering;
use App\Models\CourseOfferingMinimumEnrollmentEvent;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Support\Facades\DB;

class MinimumEnrollmentCancellationMaterializer
{
    public function __construct(private RegistrationService $registrations) {}

    public function materializeIfLinked(CourseOffering $lockedOffering, int $closureRequestId, User $actor): void
    {
        if (! SemesterRegistrationPhase6::schemaReady()) return;
        $review=CourseOfferingMinimumEnrollmentReview::query()->where('course_offering_closure_request_id',$closureRequestId)->lockForUpdate()->first();
        if ($review===null) return;
        if (DB::transactionLevel()<1 || $review->status!=='closure_pending' || $review->scientific_decision!=='cancel' || (int)$review->course_offering_id!==(int)$lockedOffering->getKey() || $lockedOffering->status!=='closed') throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_cancellation_invalid','Linked minimum cancellation proof is invalid.');
        $rows=StudentCourseRegistration::query()->where('course_offering_id',$lockedOffering->getKey())->current()->orderBy('student_course_registration_id')->lockForUpdate()->get();
        foreach($rows as $row) $this->registrations->transitionRegisteredToCancelled($row,$lockedOffering);
        $review->update(['status'=>'cancelled','affected_registration_count'=>$rows->count(),'cancelled_at'=>now()]);
        CourseOfferingMinimumEnrollmentEvent::query()->create(['course_offering_minimum_enrollment_review_id'=>$review->getKey(),'event_type'=>'cancellation_materialized','actor_user_id'=>$actor->user_id,'created_at'=>now()]);
    }
}
