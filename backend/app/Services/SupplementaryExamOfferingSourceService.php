<?php

namespace App\Services;

use App\Models\AcademicProgram;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamPeriod;
use App\Support\SupplementaryExamPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class SupplementaryExamOfferingSourceService
{
    /**
     * Eligible source CourseOfferings for a period + program + course.
     * Curriculum catalog rows are never consulted. Source offering status is ignored.
     *
     * @param  list<int>  $collegeIds
     * @return Collection<int, CourseOffering>
     */
    public function eligibleSources(
        SupplementaryExamPeriod $period,
        AcademicProgram $program,
        Course $course,
        array $collegeIds,
    ): Collection {
        return $this->eligibleSourceQuery($period, (int) $program->academic_program_id, (int) $course->course_id, $collegeIds)
            ->with(['semester', 'course', 'academicProgram'])
            ->orderBy('course_offering_id')
            ->get();
    }

    /**
     * Eligible source CourseOfferings for a period + program (catalog).
     *
     * @param  list<int>  $collegeIds
     * @return Collection<int, CourseOffering>
     */
    public function eligibleSourcesForProgram(
        SupplementaryExamPeriod $period,
        AcademicProgram $program,
        array $collegeIds,
    ): Collection {
        return $this->eligibleSourceQuery($period, (int) $program->academic_program_id, null, $collegeIds)
            ->with(['semester', 'course', 'academicProgram'])
            ->orderBy('course_id')
            ->orderBy('course_offering_id')
            ->get();
    }

    public function sourceStillValid(
        SupplementaryExamPeriod $period,
        AcademicProgram $program,
        Course $course,
        CourseOffering $source,
        array $collegeIds,
    ): bool {
        return $this->eligibleSourceQuery($period, (int) $program->academic_program_id, (int) $course->course_id, $collegeIds)
            ->whereKey($source->course_offering_id)
            ->exists();
    }

    /**
     * @param  list<int>  $collegeIds
     */
    private function eligibleSourceQuery(
        SupplementaryExamPeriod $period,
        int $programId,
        ?int $courseId,
        array $collegeIds,
    ) {
        $allowedOrders = SupplementaryExamPolicy::allowedSourceSemesterOrders($period);
        $yearId = (int) $period->academic_year_id;

        $query = CourseOffering::query()
            ->where('academic_year_id', $yearId)
            ->where('academic_program_id', $programId)
            ->whereNotNull('academic_program_id')
            ->whereHas('semester', fn ($semester) => $semester->whereIn('semester_order', $allowedOrders))
            ->whereHas(
                'studentCourseRegistrations.registrationStatus',
                fn ($status) => $status->whereIn('status_code', StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES)
            )
            ->whereIn('course_offering_id', CourseOffering::idsResolvedToColleges($collegeIds));

        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        return $query;
    }

    /**
     * @param  Collection<int, CourseOffering>  $sources
     */
    public function groupSourcesByCourse(Collection $sources): SupportCollection
    {
        return $sources
            ->groupBy(fn (CourseOffering $offering) => (int) $offering->course_id)
            ->sortKeys();
    }
}
