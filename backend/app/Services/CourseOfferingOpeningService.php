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
        return $this->applyThenGuardOpenCoverage($offering, static function (): void {}, true, $actor);
    }

    /**
     * Apply Offering metadata inside the same locked opening transaction,
     * then ensure the Offering ends OPEN with complete coverage.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenNormalOpen(CourseOffering $offering, callable $mutate, ?User $actor = null): CourseOffering
    {
        return $this->applyThenGuardOpenCoverage($offering, $mutate, true, $actor);
    }

    /**
     * Apply Offering metadata inside one locked transaction.
     * If the Offering remains or becomes OPEN, coverage is enforced against
     * the FINAL Course whenever this request is a true open transition or
     * the coverage-driving course_id changed. Unchanged already-open
     * Offerings stay idempotent and are not retroactively rejected.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenGuardOpenCoverage(
        CourseOffering $offering,
        callable $mutate,
        bool $ensureOpen,
        ?User $actor = null,
    ): CourseOffering {
        return $this->withLockedOffering($offering, function (CourseOffering $locked) use ($mutate, $ensureOpen): CourseOffering {
            $originalCourseId = (int) $locked->course_id;
            $originalStatus = (string) $locked->status;

            $mutate($locked);
            $this->forgetCoverageGraph($locked);

            return $this->finalizeLockedOpenInvariant(
                $locked,
                $originalCourseId,
                $originalStatus,
                $ensureOpen,
            );
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

    private function finalizeLockedOpenInvariant(
        CourseOffering $locked,
        int $originalCourseId,
        string $originalStatus,
        bool $ensureOpen,
    ): CourseOffering {
        $this->reloadCoverageGraph($locked);

        $courseIdentityChanged = (int) $locked->course_id !== $originalCourseId;

        if ($locked->status === self::STATUS_OPEN) {
            $unchangedOpen = $originalStatus === self::STATUS_OPEN && ! $courseIdentityChanged;
            if ($unchangedOpen) {
                return $locked;
            }

            $this->coverage->assertCompleteForNormalOpening($locked);

            return $locked;
        }

        if (! $ensureOpen) {
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
