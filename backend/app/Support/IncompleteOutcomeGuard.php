<?php

namespace App\Support;

use App\Exceptions\GradeException;
use App\Models\CourseIncompleteCase;
use App\Models\StudentCourseRegistration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All lifecycle states own their outcome, not just active I. Never a client bypass. */
final class IncompleteOutcomeGuard
{
    public const CASES = 'student_course_incomplete_cases';
    public const EVENTS = 'student_course_incomplete_events';
    public const RESULT_FIELDS = ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark',
        'result_status_id', 'is_deprived', 'calculated_at', 'calculated_by_user_id', 'result_announced_at'];

    public static function installed(): bool { return Schema::hasTable(self::CASES) || Schema::hasTable(self::EVENTS); }
    public static function ready(): bool
    {
        $basic = Schema::hasColumns(self::CASES, ['incomplete_case_id', 'student_course_registration_id', 'student_course_result_id',
            'student_id', 'course_id', 'academic_year_id', 'semester_id', 'reason', 'requirements', 'granted_at', 'granted_by_user_id',
            'deadline_at', 'deadline_source', 'calendar_snapshot', 'expiry_outcome', 'state', 'revision', 'outcome_snapshot', 'settled_at', 'created_at', 'updated_at'])
            && Schema::hasColumns(self::EVENTS, ['incomplete_event_id', 'incomplete_case_id', 'revision', 'action', 'request_id',
                'request_hash', 'actor_user_id', 'actor_kind', 'before_values', 'after_values', 'response_snapshot', 'performed_at']);
        if (!$basic || DB::getDriverName() !== 'mysql') return $basic;
        return DB::select(IncompleteSchemaContract::issuesSql(true).' LIMIT 1') === [];
    }
    public static function assertReady(): void
    {
        if (!self::ready()) throw new GradeException('Incomplete lifecycle schema is not ready.', status: 503, errorCode: 'course_result_symbols_schema_not_ready');
    }
    public static function caseFor(int $registrationId, bool $lock = false): ?CourseIncompleteCase
    {
        if (!self::installed()) return null; // Earlier phases continue working before installation.
        self::assertReady();
        $q = CourseIncompleteCase::query()->where('student_course_registration_id', $registrationId);
        return ($lock ? $q->lockForUpdate() : $q)->first();
    }
    public static function attach(Collection $registrations): void
    {
        HistoricalOutcomeGuard::attach($registrations);
        $cases = collect();
        if (self::installed()) {
            self::assertReady();
            $cases = CourseIncompleteCase::query()->whereIn('student_course_registration_id', $registrations->map->getKey()->all())->get()->keyBy('student_course_registration_id');
        }
        foreach ($registrations as $r) $r->setRelation('incompleteCase', $cases->get($r->getKey()));
    }
    public static function forRegistration(StudentCourseRegistration $registration): ?CourseIncompleteCase
    {
        return $registration->relationLoaded('incompleteCase') ? $registration->getRelation('incompleteCase') : self::caseFor((int) $registration->getKey());
    }
    public static function assertOrdinaryWrite(int $registrationId): void
    {
        HistoricalOutcomeGuard::assertOrdinaryWrite($registrationId);
        if (self::caseFor($registrationId, DB::transactionLevel() > 0)) {
            throw new GradeException('This result belongs to an audited Incomplete lifecycle; ordinary grade writes cannot replace it.', status: 409, errorCode: 'incomplete_outcome_locked');
        }
    }
    /** Called by canonical locked transitions, with a model backstop for other writers. */
    public static function assertRegistrationTransition(int $registrationId, ?string $target, ?string $source = 'registered'): void
    {
        HistoricalOutcomeGuard::assertRegistrationTransition($registrationId,$target,$source);
        if (self::caseFor($registrationId, DB::transactionLevel() > 0)
            && (!in_array($target, ['registered', 'completed'], true) || !in_array($source, ['registered', 'completed'], true))) {
            throw new GradeException('This registration belongs to an Incomplete lifecycle; withdrawal, drop and cancellation are not supported.', status: 409, errorCode: 'incomplete_registration_transition_locked');
        }
    }
    public static function ordinaryComponents($query)
    {
        $query=HistoricalOutcomeGuard::ordinaryComponents($query);
        if (self::installed()) { self::assertReady(); $query->whereNotIn('student_course_registration_id', CourseIncompleteCase::query()->select('student_course_registration_id')); }
        return $query;
    }
    public static function assertConfigurationMutable(iterable $offeringIds): void
    {
        HistoricalOutcomeGuard::assertConfigurationMutable($offeringIds);
        if (!self::installed()) return;
        self::assertReady();
        if (CourseIncompleteCase::query()->whereIn('student_course_registration_id', StudentCourseRegistration::query()
            ->whereIn('course_offering_id', collect($offeringIds)->all())->select('student_course_registration_id'))->exists()) {
            throw new GradeException('Required component configuration is referenced by an Incomplete lifecycle.', status: 409, errorCode: 'incomplete_component_configuration_locked');
        }
    }
    public static function readState(StudentCourseRegistration $r): array
    {
        if(HistoricalOutcomeGuard::forRegistration($r))return ['state'=>'historical_reconciled','case_id'=>null,'revision'=>null,'deadline_at'=>null,
            'expiry_outcome'=>null,'ordinary_grade_edit_allowed'=>false,'blocking_code'=>'historical_outcome_locked'];
        $case = self::forRegistration($r);
        $status = $r->studentCourseResult?->resultStatus?->status_code;
        return ['state' => $case ? 'incomplete_'.$case->state : (!$r->studentCourseResult ? 'no_result' : ($status === 'incomplete' ? 'ordinary_missing_components' : ($status === 'pending_approval' ? 'pending_approval' : 'ordinary_result'))),
            'case_id' => $case?->getKey(), 'revision' => $case?->revision, 'deadline_at' => $case?->deadline_at?->toIso8601String(),
            'expiry_outcome' => $case?->expiry_outcome, 'ordinary_grade_edit_allowed' => $case === null,
            'blocking_code' => $case ? 'incomplete_outcome_locked' : null];
    }
    /** Completeness/finalization exemption requires a real case AND matching persisted evidence. */
    public static function preservesOutcome(StudentCourseRegistration $registration, bool $lock = false): bool
    {
        if(HistoricalOutcomeGuard::preservesOutcome($registration,$lock))return true;
        $case = $lock ? self::caseFor((int) $registration->getKey(), true) : self::forRegistration($registration);
        if (!$case) return false;
        $offering = $registration->courseOffering;
        if ((int) $case->student_id !== (int) $registration->student_id || (int) $case->course_id !== (int) $offering?->course_id
            || (int) $case->academic_year_id !== (int) $offering?->academic_year_id || (int) $case->semester_id !== (int) $offering?->semester_id) self::conflict();
        $result = DB::table('student_course_results')->where('student_course_registration_id', $registration->getKey())->first();
        $event = DB::table(self::EVENTS)->where('incomplete_case_id', $case->getKey())->where('revision', $case->revision)->first();
        $status = $result ? DB::table('result_statuses')->where('result_status_id', $result->result_status_id)->value('status_code') : null;
        return self::assertOutcomeEvidence($registration, $case, $result, $event, $status);
    }

    /** Read-only batch projection of the SAME protection as preservesOutcome().
     * Caller owns a fenced snapshot or the existing catalog-first transaction.
     * Never replaces the locked writer guard or authorizes a lifecycle mutation.
     */
    public static function readPreservedOutcomes(Collection $registrations): array
    {
        self::assertReady();
        $ids = $registrations->map->getKey()->all();
        $cases = CourseIncompleteCase::whereIn('student_course_registration_id', $ids)->get()->keyBy('student_course_registration_id');
        $results = DB::table('student_course_results')->whereIn('student_course_registration_id', $ids)->get()->keyBy('student_course_registration_id');
        $events = DB::table(self::EVENTS.' as e')->join(self::CASES.' as c', 'c.incomplete_case_id', '=', 'e.incomplete_case_id')
            ->whereColumn('e.revision','c.revision')->whereIn('c.student_course_registration_id',$ids)->select('e.*')->get()->keyBy('incomplete_case_id');
        $statuses = DB::table('result_statuses')->whereIn('result_status_id',$results->pluck('result_status_id')->unique())->pluck('status_code','result_status_id');
        $evidence = [];
        foreach ($registrations as $registration) {
            $id = $registration->getKey(); $case = $cases->get($id);
            $registration->setRelation('incompleteCase',$case);
            $evidence[$id] = ['state'=>$case?->state,'preserved'=>false,'reason'=>null];
            if (!$case) continue;
            $result = $results->get($id);
            try {
                self::assertOutcomeEvidence($registration,$case,$result,$events->get($case->getKey()),$statuses->get($result?->result_status_id));
                $evidence[$id]['preserved'] = true;
            } catch (GradeException $e) { $evidence[$id]['reason'] = $e->errorCode; }
            catch (\JsonException $e) { $evidence[$id]['reason'] = 'incomplete_outcome_conflict'; }
        }
        return $evidence;
    }

    private static function assertOutcomeEvidence(StudentCourseRegistration $registration, CourseIncompleteCase $case, ?object $result, ?object $event, ?string $status): bool
    {
        $offering = $registration->courseOffering;
        if ((int) $case->student_id !== (int) $registration->student_id || (int) $case->course_id !== (int) $offering?->course_id
            || (int) $case->academic_year_id !== (int) $offering?->academic_year_id || (int) $case->semester_id !== (int) $offering?->semester_id) self::conflict();
        $snapshot = $case->outcome_snapshot;
        if (!$result || !$event || !is_array($snapshot) || !in_array($case->state, ['active', 'resolved', 'expired'], true)
            || $case->revision < 1 || array_diff(self::RESULT_FIELDS, array_keys($snapshot))
            || (int) $case->student_course_result_id !== (int) $result->student_course_result_id) self::conflict();
        if (($case->state === 'active' && !in_array($event->action, ['grant', 'deadline_changed'], true))
            || ($case->state === 'resolved' && $event->action !== 'resolved') || ($case->state === 'expired' && $event->action !== 'expired')) self::conflict();
        $after = json_decode($event->after_values, true, flags: JSON_THROW_ON_ERROR);
        if ($after !== $case->toArray()) self::conflict();
        if (($case->state === 'active' && $status !== 'incomplete')
            || ($case->state === 'resolved' && !in_array($status, ['passed', 'failed'], true))
            || ($case->state === 'expired' && $status !== ($case->expiry_outcome === 'Z' ? 'deprived' : 'failed'))) self::conflict();
        foreach ($snapshot as $key => $value) {
            $actual = $result->{$key} ?? null;
            if (in_array($key, ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'], true)) {
                if (($value === null) !== ($actual === null) || ($value !== null && \Brick\Math\BigDecimal::of((string) $value)->compareTo((string) $actual) !== 0)) self::conflict();
            } elseif ((string) $actual !== (string) $value) self::conflict();
        }
        if ((int) $registration->result_status_id !== (int) $snapshot['result_status_id']) self::conflict();
        return true;
    }
    private static function conflict(): never { throw new GradeException('Incomplete settlement evidence conflicts with the result; explicit authorized review is required.', status: 409, errorCode: 'incomplete_outcome_conflict'); }
}
