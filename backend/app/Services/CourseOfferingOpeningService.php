<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CourseOfferingOpeningService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public function __construct(private CourseOfferingInstructorCoverageService $coverage)
    {
    }

    /**
     * Normal closed → open transition. Coverage is an academic invariant:
     * no role, including Super Admin, bypasses it.
     */
    public function normalOpen(CourseOffering $offering, ?User $actor = null): CourseOffering
    {
        return DB::transaction(function () use ($offering): CourseOffering {
            $locked = CourseOffering::query()
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            CourseOfferingInstructor::query()
                ->where('course_offering_id', $locked->course_offering_id)
                ->lockForUpdate()
                ->get();

            $locked->load([
                'course',
                'facultyMember.employee.employeeStatus',
                'offeringInstructors' => fn ($instructors) => $instructors
                    ->where('is_active', true)
                    ->with('facultyMember.employee.employeeStatus'),
            ]);

            if ($locked->status === self::STATUS_OPEN) {
                return $locked;
            }

            if ($locked->status !== self::STATUS_CLOSED) {
                throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
            }

            $this->coverage->assertCompleteForNormalOpening($locked);

            $locked->status = self::STATUS_OPEN;
            $locked->save();

            return $locked;
        });
    }
}
