<?php

namespace App\Support;

use App\Exceptions\GradeException;
use App\Models\StudentCourseRegistration;
use App\Services\StudentCompletion\CompletionData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Audited historical adoption is not an ordinary recalculation or an Incomplete lifecycle. */
final class HistoricalOutcomeGuard
{
    public static function attach(Collection $registrations): void
    {
        $rows = collect();
        if (HistoricalExamSchema::installed()) {
            HistoricalExamSchema::requireReady();
            $rows = DB::table(HistoricalExamSchema::OUTCOMES)->whereIn('student_course_registration_id', $registrations->map->getKey()->all())
                ->orderBy('revision')->get()->keyBy('student_course_registration_id');
        }
        foreach ($registrations as $r) $r->setRelation('historicalOutcome', $rows->get($r->getKey()));
    }

    /** Fixed-count reads for GPA/transcript; a relation name alone never proves the current settlement. */
    public static function assertBatch(Collection $registrations): void
    {
        self::attach($registrations);
        $owned=$registrations->filter(fn($r)=>$r->getRelation('historicalOutcome')!==null);
        if($owned->isEmpty())return;
        $ids=$owned->map->getKey()->all();
        $results=DB::table('student_course_results')->whereIn('student_course_registration_id',$ids)->orderBy('student_course_result_id')->get()->groupBy('student_course_registration_id');
        $marks=DB::table('student_grade_components')->whereIn('student_course_registration_id',$ids)->orderBy('student_grade_component_id')->get()->groupBy('student_course_registration_id');
        $credits=DB::table(HistoricalExamSchema::CREDITS)->whereIn('student_course_registration_id',$ids)->orderBy('revision')->get()->keyBy('student_course_registration_id');
        $statuses=DB::table('registration_statuses')->whereIn('registration_status_id',$owned->pluck('registration_status_id'))->pluck('status_code','registration_status_id');
        if(IncompleteOutcomeGuard::installed()) {
            IncompleteOutcomeGuard::assertReady();
            if(DB::table(IncompleteOutcomeGuard::CASES)->whereIn('student_course_registration_id',$ids)->exists())self::readConflict();
        }
        foreach($owned as $r) {
            $event=$r->getRelation('historicalOutcome');$expected=json_decode($event->after_values,true,flags:JSON_THROW_ON_ERROR)['snapshot'];
            $resultRows=$results->get($r->getKey(),collect())->map(fn($x)=>(array)$x)->all();
            $actual=['registration'=>array_intersect_key($r->getAttributes(),array_flip(['student_course_registration_id','student_id','course_offering_id','result_status_id','academic_plan_version_id','plan_program_course_id'])),
                'results'=>$resultRows,'marks'=>$marks->get($r->getKey(),collect())->map(fn($x)=>(array)$x)->all(),
                'applicable_credit_evidence'=>$credits->has($r->getKey())?(array)$credits->get($r->getKey()):null];
            if(!in_array($statuses->get($r->registration_status_id),['registered','completed'],true)
                ||!hash_equals(CompletionData::hash($expected),CompletionData::hash($actual))
                ||($r->relationLoaded('studentCourseResult')&&!hash_equals(CompletionData::hash($r->getRelation('studentCourseResult')?->getAttributes()),CompletionData::hash($resultRows[0]??null))))self::readConflict();
        }
    }

    private static function readConflict(): never
    {
        throw new GradeException('Current academic data does not match the recorded historical settlement.',status:409,errorCode:'historical_outcome_conflict');
    }

    public static function forRegistration(StudentCourseRegistration $r, bool $lock = false): ?object
    {
        if (! $lock && $r->relationLoaded('historicalOutcome')) return $r->getRelation('historicalOutcome');
        if (! HistoricalExamSchema::installed()) return null;
        HistoricalExamSchema::requireReady();
        $q = DB::table(HistoricalExamSchema::OUTCOMES)->where('student_course_registration_id', $r->getKey())->orderByDesc('revision');
        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function assertOrdinaryWrite(int $id): void
    {
        $r = new StudentCourseRegistration; $r->setRawAttributes(['student_course_registration_id'=>$id]);
        if (self::forRegistration($r, DB::transactionLevel() > 0)) throw new GradeException('This result has an audited historical settlement; use an explicitly reviewed correction.', status:409, errorCode:'historical_outcome_locked');
    }

    public static function preservesOutcome(StudentCourseRegistration $r, bool $lock = false): bool
    {
        $event = self::forRegistration($r, $lock);
        if (! $event) return false;
        if(IncompleteOutcomeGuard::caseFor((int)$r->getKey(),$lock))throw new GradeException('Conflicting lifecycle ownership requires explicit review.',status:409,errorCode:'historical_outcome_conflict');
        $stored = json_decode($event->after_values, true, flags:JSON_THROW_ON_ERROR);
        $expected = $stored['snapshot'];
        $actual = self::snapshot((int)$r->getKey());
        if (! hash_equals(CompletionData::hash($expected), CompletionData::hash($actual))) {
            throw new GradeException('Historical settlement evidence conflicts with the current result; explicit review is required.', status:409, errorCode:'historical_outcome_conflict');
        }
        return true;
    }

    /** Result/marks are exact; supported registered/completed transitions do not rewrite settlement ownership. */
    public static function snapshot(int $registrationId): array
    {
        $r = DB::table('student_course_registrations')->where('student_course_registration_id',$registrationId)->first();
        $fields = ['student_course_registration_id','student_id','course_offering_id','result_status_id','academic_plan_version_id','plan_program_course_id'];
        $registration = $r ? array_intersect_key((array)$r,array_flip($fields)) : null;
        $result = DB::table('student_course_results')->where('student_course_registration_id',$registrationId)->orderBy('student_course_result_id')->get()->map(fn($r)=>(array)$r)->all();
        $marks = DB::table('student_grade_components')->where('student_course_registration_id',$registrationId)->orderBy('student_grade_component_id')->get()->map(fn($r)=>(array)$r)->all();
        $credit=DB::table(HistoricalExamSchema::CREDITS)->where('student_course_registration_id',$registrationId)->orderByDesc('revision')->first();
        return CompletionData::canonical(['registration'=>$registration,'results'=>$result,'marks'=>$marks,'applicable_credit_evidence'=>$credit?(array)$credit:null]);
    }

    public static function ordinaryComponents($query)
    {
        if (HistoricalExamSchema::installed()) {
            HistoricalExamSchema::requireReady();
            $query->whereNotIn('student_course_registration_id',DB::table(HistoricalExamSchema::OUTCOMES)->select('student_course_registration_id'));
        }
        return $query;
    }

    public static function assertRegistrationTransition(int $id, ?string $target, ?string $source): void
    {
        if (in_array($target,['registered','completed'],true) && in_array($source,['registered','completed'],true)) return;
        self::assertOrdinaryWrite($id);
    }

    public static function assertConfigurationMutable(iterable $offeringIds): void
    {
        if (! HistoricalExamSchema::installed()) return;
        HistoricalExamSchema::requireReady();
        if (DB::table(HistoricalExamSchema::OUTCOMES)->whereIn('student_course_registration_id',DB::table('student_course_registrations')
            ->whereIn('course_offering_id',collect($offeringIds)->all())->select('student_course_registration_id'))->exists()) {
            throw new GradeException('Component definitions have audited historical settlement references.',status:409,errorCode:'historical_component_configuration_locked');
        }
    }
}
