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
        return $this->withLockedOffering(
            $offering,
            fn (CourseOffering $locked): CourseOffering => $this->openLocked($locked),
        );
    }

    /**
     * Apply Offering metadata inside the same locked opening transaction.
     * If normal opening then fails, every metadata change is rolled back.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenNormalOpen(CourseOffering $offering, callable $mutate, ?User $actor = null): CourseOffering
    {
        return $this->withLockedOffering($offering, function (CourseOffering $locked) use ($mutate): CourseOffering {
            $mutate($locked);
            $this->forgetCoverageGraph($locked);

            return $this->openLocked($locked);
        });
    }

    /**
     * @param  callable(CourseOffering): CourseOffering  $then
     */
    private function withLockedOffering(CourseOffering $offering, callable $then): CourseOffering
    {
        return DB::transaction(function () use ($offering, $then): CourseOffering {
            $locked = CourseOffering::query()
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            CourseOfferingInstructor::query()
                ->where('course_offering_id', $locked->course_offering_id)
                ->lockForUpdate()
                ->get();

            return $then($locked);
        });
    }

    private function openLocked(CourseOffering $locked): CourseOffering
    {
        $this->reloadCoverageGraph($locked);

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
    }

    private function forgetCoverageGraph(CourseOffering $offering): void
    {
        $offering->unsetRelation('course');
        $offering->unsetRelation('facultyMember');
        $offering->unsetRelation('offeringInstructors');
    }

    private function reloadCoverageGraph(CourseOffering $offering): void
    {
        $this->forgetCoverageGraph($offering);
        $offering->load([
            'course',
            'facultyMember.employee.employeeStatus',
            'offeringInstructors' => fn ($instructors) => $instructors
                ->where('is_active', true)
                ->with('facultyMember.employee.employeeStatus'),
        ]);
    }
}
