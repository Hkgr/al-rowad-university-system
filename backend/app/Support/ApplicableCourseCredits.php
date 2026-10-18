<?php

namespace App\Support;

use App\Models\{Course, CourseOffering, ProgramCourse, Student, StudentCourseRegistration};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema};

/** One read contract: recorded attempt > its pinned membership > truthful current-origin fallback. */
final class ApplicableCourseCredits
{
    /** Same nullable precedence in PHP, snapshot reconciliation and the SQL COALESCE reader. */
    public static function choose(?int $attempt,?int $pinnedMembership,?int $origin): ?int
    {
        return $attempt??$pinnedMembership??$origin;
    }
    public static function attach(Collection $registrations): void
    {
        $registrations=$registrations->reject(fn($r)=>$r->relationLoaded('applicableCreditEvidence'));
        if($registrations->isEmpty())return;
        $credits = collect(); $plans = collect();
        if (HistoricalExamSchema::installed()) {
            HistoricalExamSchema::requireReady();
            $credits = DB::table(HistoricalExamSchema::CREDITS)->whereIn('student_course_registration_id', $registrations->map->getKey()->all())
                ->orderBy('revision')->get()->keyBy('student_course_registration_id');
            $plans = DB::table(HistoricalExamSchema::PLAN)->whereIn('program_course_id', $registrations->pluck('plan_program_course_id')->filter()->all())
                ->orderBy('revision')->get()->keyBy('program_course_id');
        }
        foreach ($registrations as $r) {
            $credit = $credits->get($r->getKey()); $plan = $plans->get($r->plan_program_course_id);
            $origin = $r->courseOffering?->course?->credit_hours;
            $r->setRelation('applicableCreditEvidence', (object)[
                'credit_hours' => self::choose($credit?(int)$credit->credit_hours:null,$plan?(int)$plan->credit_hours:null,$origin===null?null:(int)$origin),
                'basis' => $credit ? 'recorded_attempt_credit' : ($plan ? 'pinned_plan_membership_credit' : 'current_origin_fallback_no_historical_credit_snapshot'),
                'evidence_id' => $credit?->credit_evidence_id ?? $plan?->credit_evidence_id,
            ]);
        }
    }

    public static function hours(StudentCourseRegistration $registration): ?int
    {
        if (! $registration->relationLoaded('applicableCreditEvidence')) self::attach(collect([$registration]));
        return $registration->getRelation('applicableCreditEvidence')->credit_hours;
    }

    public static function fields(StudentCourseRegistration $registration): array
    {
        $hours = self::hours($registration);
        return ['credit_hours' => $hours, 'credit_hours_reference' => $registration->getRelation('applicableCreditEvidence')->basis];
    }

    public static function attachMemberships(Collection $memberships): void
    {
        $memberships=$memberships->reject(fn($m)=>$m->relationLoaded('applicablePlanCredit'));
        if($memberships->isEmpty())return;
        $rows = collect();
        if (HistoricalExamSchema::installed()) {
            HistoricalExamSchema::requireReady();
            $rows = DB::table(HistoricalExamSchema::PLAN)->whereIn('program_course_id', $memberships->map->getKey()->all())
                ->orderBy('revision')->get()->keyBy('program_course_id');
        }
        foreach ($memberships as $member) $member->setRelation('applicablePlanCredit', $rows->get($member->getKey()));
    }

    public static function planHours(ProgramCourse $membership, ?Course $origin = null): int
    {
        if (! $membership->relationLoaded('applicablePlanCredit')) self::attachMemberships(collect([$membership]));
        return (int)($membership->getRelation('applicablePlanCredit')?->credit_hours ?? $origin?->credit_hours ?? $membership->course?->credit_hours ?? 0);
    }

    /** Candidate workload follows the student's actual assignment, never the program default. */
    public static function candidateHours(Student $student,?CourseOffering $offering): int
    {
        if(!$offering)return 0;
        if(!HistoricalExamSchema::installed())return (int)($offering->course?->credit_hours??0);
        $membership=\App\Services\AcademicPlanContext::forStudent($student)->membership((int)$offering->course_id);
        return $membership?self::planHours($membership):(int)($offering->course?->credit_hours??0);
    }

    public static function choiceHours(?Student $student, ?CourseOffering $offering, ?StudentCourseRegistration $materialized = null): int
    {
        if($materialized)return (int)self::hours($materialized);
        return $student ? self::candidateHours($student,$offering) : (int)($offering?->course?->credit_hours??0);
    }

    /** Read projection only; never persist the contextual display value into the shared course origin. */
    public static function projectMemberships(Collection $memberships): void
    {
        self::attachMemberships($memberships);
        foreach($memberships as $member)if($member->course) {
            $course=clone $member->course;
            $course->setAttribute('catalog_credit_hours',$course->credit_hours);
            $course->setAttribute('credit_hours',self::planHours($member));
            $member->setRelation('course',$course);
        }
    }

    public static function sql(string $registration, string $course): string
    {
        self::alias($registration); self::alias($course);
        if (! HistoricalExamSchema::installed()) return "$course.credit_hours";
        HistoricalExamSchema::requireReady();
        $attempt = '(SELECT ce.credit_hours FROM '.HistoricalExamSchema::CREDITS." ce WHERE ce.student_course_registration_id=$registration.student_course_registration_id ORDER BY ce.revision DESC LIMIT 1)";
        $plan = Schema::hasColumn('student_course_registrations', 'plan_program_course_id')
            ? '(SELECT pe.credit_hours FROM '.HistoricalExamSchema::PLAN." pe WHERE pe.program_course_id=$registration.plan_program_course_id ORDER BY pe.revision DESC LIMIT 1)" : 'NULL';
        return "COALESCE($attempt,$plan,$course.credit_hours)";
    }

    public static function planSql(string $membership, string $course): string
    {
        self::alias($membership); self::alias($course);
        if (! HistoricalExamSchema::installed()) return "$course.credit_hours";
        HistoricalExamSchema::requireReady();
        return 'COALESCE((SELECT pe.credit_hours FROM '.HistoricalExamSchema::PLAN." pe WHERE pe.program_course_id=$membership.program_course_id ORDER BY pe.revision DESC LIMIT 1),$course.credit_hours)";
    }

    private static function alias(string $value): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value)) throw new \InvalidArgumentException('Invalid internal credit-query alias.');
    }
}
