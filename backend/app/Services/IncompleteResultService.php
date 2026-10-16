<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\{CourseIncompleteCase, GradeComponent, Student, StudentCourseRegistration, StudentCourseResult, User};
use App\Support\{ExamManualGradeEntryAccess, IncompleteOutcomeGuard as Guard, SupplementaryExamTargetGuard};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Sole writer for explicit, audited symbol transitions. Never changes offering approvals. */
final class IncompleteResultService
{
    public function __construct(private readonly GradeService $grades, private readonly IncompleteDeadlinePolicyService $deadlines) {}

    public function index(User $actor, array $filters): array
    {
        app(ExamManualGradeEntryAccess::class)->authorize($actor);
        Guard::assertReady();
        $scope = app(DataScopeService::class);
        $query = CourseIncompleteCase::query()
            ->whereIn('student_id', $scope->scopeManualGradeStudents(Student::query(), $actor)->select('students.student_id'))
            ->whereIn('student_course_registration_id', StudentCourseRegistration::query()->select('student_course_registration_id')
                ->whereIn('course_offering_id', $scope->scopeManualGradeOfferings(\App\Models\CourseOffering::query(), $actor)->select('course_offerings.course_offering_id')));
        foreach (['state', 'student_id', 'academic_year_id', 'semester_id'] as $key) if (isset($filters[$key])) $query->where($key, $filters[$key]);
        return $query->orderBy('deadline_at')->orderBy('incomplete_case_id')->paginate($filters['per_page'] ?? 15)->toArray();
    }

    public function registrationView(User $actor, int $id): array
    {
        $r = $this->registration($id);
        $this->authorize($actor, $r);
        $ready = Guard::ready();
        $case = $ready ? Guard::caseFor($id) : null;
        $status = $r->studentCourseResult?->resultStatus?->status_code;
        $official = $this->grades->isOfficiallyApprovedOffering($r->courseOffering);
        $state = $case ? 'incomplete_'.$case->state : ($r->studentCourseResult === null ? 'no_result' : ($status === 'pending_approval' ? 'pending_approval' : ($status === 'incomplete' ? 'ordinary_missing_components' : ($official ? 'official_result' : 'pending_approval'))));
        $block = !$ready ? 'course_result_symbols_schema_not_ready' : $this->conflictReason($r);
        if ($ready && $case && $block === null) {
            try { Guard::preservesOutcome($r); } catch (GradeException $e) { $block = $e->errorCode; }
        }
        if ($ready && !$case && $block === null && !$this->grantable($r)) $block = 'incomplete_grant_not_allowed';
        $overdue = $case?->state === 'active' && $this->clock()->gt($case->deadline_at);
        return ['schema_ready' => $ready, 'registration_id' => $id, 'state' => $state, 'is_official' => $official,
            'legacy_incomplete_without_grant' => !$case && $status === 'incomplete', 'case' => $case?->toArray(), 'overdue' => $overdue,
            'allowed_operations' => ['grant' => $ready && !$case && $block === null && $this->grantable($r),
                'deadline_update' => $ready && $case?->state === 'active' && $block === null,
                'resolve' => $ready && $case?->state === 'active' && !$overdue && $block === null],
            'blocking_code' => $block ?? ($overdue ? 'incomplete_deadline_elapsed' : ($case && $case->state !== 'active' ? 'incomplete_already_settled' : null)),
            'deadline_suggestion' => !$case ? $this->deadlines->suggest($r) : null];
    }

    public function show(User $actor, int $id): array
    {
        Guard::assertReady();
        $case = CourseIncompleteCase::findOrFail($id);
        $view = $this->registrationView($actor, (int) $case->student_course_registration_id);
        $view['events'] = $case->events->toArray();
        return $view;
    }

    public function grant(User $actor, int $registrationId, array $input): array { return $this->operate($actor, $registrationId, 'grant', $input); }
    public function changeDeadline(User $actor, int $caseId, array $input): array
    {
        Guard::assertReady();
        return $this->operate($actor, (int) CourseIncompleteCase::findOrFail($caseId)->student_course_registration_id, 'deadline_changed', $input);
    }
    public function resolve(User $actor, int $caseId, array $input): array
    {
        Guard::assertReady();
        return $this->operate($actor, (int) CourseIncompleteCase::findOrFail($caseId)->student_course_registration_id, 'resolved', $input);
    }

    private function operate(User $actor, int $registrationId, string $action, array $input): array
    {
        Guard::assertReady();
        $this->validateAction($action, $input);
        $this->authorize($actor, $this->registration($registrationId));
        try { return AcademicPlanContext::transaction(function () use ($actor, $registrationId, $action, $input): array {
            $r = $this->lock($registrationId);
            $this->authorize($actor, $r);
            $hash = $this->requestHash($registrationId, $action, $input);
            $event = DB::table(Guard::EVENTS)->where('request_id', $input['request_id'])->lockForUpdate()->first();
            if ($event) {
                if ((int) $event->actor_user_id !== (int) $actor->getKey() || !hash_equals($event->request_hash, $hash)) $this->fail('Request UUID has different content or actor.', 'incomplete_request_conflict');
                return json_decode($event->response_snapshot, true, flags: JSON_THROW_ON_ERROR);
            }
            $case = Guard::caseFor($registrationId, true);
            if ((int) $input['expected_revision'] !== (int) ($case?->revision ?? 0)) $this->fail('Incomplete case changed; review the current case.', 'incomplete_stale');
            $this->assertNoConflicts($r);
            $before = $case?->toArray();
            if ($case) Guard::preservesOutcome($r, true);
            if ($action === 'grant') {
                if ($case || !$this->grantable($r)) $this->fail('An ordinary final result or an existing case cannot be replaced by granting I.', 'incomplete_grant_not_allowed');
                $deadline = $this->acceptedDeadline($r, $input);
                $oldResult = $r->studentCourseResult?->getAttributes();
                $completed = $this->completedPartTotals($r);
                $resultId = $this->writeResult($r, ['theoretical_total' => $r->studentCourseResult?->theoretical_total ?? $completed['theoretical'],
                    'practical_total' => $r->studentCourseResult?->practical_total ?? $completed['practical'],
                    'final_mark' => null, 'result_status_id' => $this->statusId('incomplete'), 'is_deprived' => false], (int) $actor->getKey());
                $id = DB::table(Guard::CASES)->insertGetId(['student_course_registration_id' => $registrationId, 'student_course_result_id' => $resultId,
                    'student_id' => $r->student_id, 'course_id' => $r->courseOffering->course_id, 'academic_year_id' => $r->courseOffering->academic_year_id,
                    'semester_id' => $r->courseOffering->semester_id, 'reason' => trim($input['reason']), 'requirements' => trim($input['requirements']),
                    'granted_at' => $this->now(), 'granted_by_user_id' => $actor->getKey(), 'deadline_at' => $deadline['at'], 'deadline_source' => $deadline['source'],
                    'calendar_snapshot' => $deadline['snapshot'] === null ? null : json_encode($deadline['snapshot'], JSON_THROW_ON_ERROR), 'expiry_outcome' => $input['expiry_outcome'],
                    'state' => 'active', 'revision' => 1, 'outcome_snapshot' => json_encode($this->resultSnapshot($registrationId), JSON_THROW_ON_ERROR),
                    'settled_at' => null, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
                $case = CourseIncompleteCase::findOrFail($id);
                $before = ['result' => $oldResult];
            } else {
                if (!$case || $case->state !== 'active') $this->fail('Only an unresolved case can be changed; terminal cases cannot be reopened.', 'incomplete_already_settled');
                if ($action === 'deadline_changed') {
                    $deadline = $this->manualDeadline($input['deadline_at']);
                    DB::table(Guard::CASES)->where('incomplete_case_id', $case->getKey())->update(['deadline_at' => $deadline, 'deadline_source' => 'manual', 'calendar_snapshot' => null,
                        'revision' => $case->revision + 1, 'updated_at' => $this->now()]);
                } else {
                    if ($this->clock()->gt($case->deadline_at)) $this->fail('Deadline elapsed; extend the unresolved case explicitly before resolving.', 'incomplete_deadline_elapsed');
                    $totals = $this->completeComponents($r, $input['components'] ?? [], (int) $actor->getKey());
                    $this->writeResult($r, $totals, (int) $actor->getKey());
                    $this->settle($case, 'resolved');
                }
                $case = $case->fresh();
            }
            return $this->record($case, $action, $before, (int) $actor->getKey(), $input['request_id'], $hash);
        }); } catch (\Illuminate\Database\QueryException $e) {
            // Without the installed catalog epoch, different registrations can
            // race on the global UUID index. Never retry either mutation.
            if (str_contains($e->getMessage(), 'uq_crs_request') || str_contains($e->getMessage(), Guard::EVENTS.'.request_id'))
                $this->fail('Request UUID has already been used by another operation.', 'incomplete_request_conflict');
            throw $e;
        }
    }

    /** Bounded command batches use one transaction/case; no original-grant actor attribution. */
    public function expire(int $caseId): bool
    {
        Guard::assertReady();
        $preview = CourseIncompleteCase::findOrFail($caseId);
        return AcademicPlanContext::transaction(function () use ($preview): bool {
            $r = $this->lock((int) $preview->student_course_registration_id);
            $case = Guard::caseFor((int) $r->getKey(), true);
            if (!$case || $case->state !== 'active' || $this->clock()->lte($case->deadline_at)) return false;
            Guard::preservesOutcome($r, true);
            $this->assertNoConflicts($r);
            $before = $case->toArray();
            $this->writeResult($r, ['final_mark' => null, 'result_status_id' => $this->statusId($case->expiry_outcome === 'Z' ? 'deprived' : 'failed'),
                'is_deprived' => $case->expiry_outcome === 'Z'], null);
            $this->settle($case, 'expired');
            $case = $case->fresh();
            $this->record($case, 'expired', $before, null, 'system:expire:'.$case->getKey().':'.$case->revision, hash('sha256', 'expire:'.$case->getKey().':'.$case->revision));
            return true;
        });
    }

    private function lock(int $id): StudentCourseRegistration
    {
        $preview = StudentCourseRegistration::findOrFail($id);
        Student::query()->whereKey($preview->student_id)->lockForUpdate()->firstOrFail();
        \App\Models\CourseOffering::query()->whereKey($preview->course_offering_id)->lockForUpdate()->firstOrFail();
        DB::table('grade_approvals')->where('course_offering_id', $preview->course_offering_id)->orderBy('grade_approval_id')->lockForUpdate()->get();
        DB::table('grade_part_approvals')->where('course_offering_id', $preview->course_offering_id)->orderBy('component_type')->lockForUpdate()->get();
        StudentCourseRegistration::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        DB::table('grade_components')->where('course_offering_id', $preview->course_offering_id)->orderBy('grade_component_id')->lockForUpdate()->get();
        DB::table('student_grade_components')->where('student_course_registration_id', $id)->orderBy('student_grade_component_id')->lockForUpdate()->get();
        DB::table('student_course_results')->where('student_course_registration_id', $id)->lockForUpdate()->first();
        return $this->registration($id);
    }
    private function registration(int $id): StudentCourseRegistration
    {
        return StudentCourseRegistration::with(['student', 'registrationStatus', 'resultStatus', 'courseOffering.course', 'courseOffering.gradeComponents', 'studentCourseResult.resultStatus'])->findOrFail($id);
    }
    private function authorize(User $actor, StudentCourseRegistration $r): void { app(ExamManualGradeEntryAccess::class)->authorize($actor, $r->student, $r); }
    private function grantable(StudentCourseRegistration $r): bool
    {
        return in_array($r->registrationStatus?->status_code, ['registered', 'completed'], true)
            && !in_array($r->resultStatus?->status_code, ['passed', 'failed', 'deprived'], true)
            && (!$r->studentCourseResult || in_array($r->studentCourseResult->resultStatus?->status_code, ['incomplete', 'pending_approval'], true))
            && !$r->studentCourseResult?->is_deprived;
    }
    private function conflictReason(StudentCourseRegistration $r): ?string
    {
        try { $this->assertNoConflicts($r); return null; } catch (GradeException $e) { return $e->errorCode; }
    }
    private function assertNoConflicts(StudentCourseRegistration $r): void
    {
        if (!in_array($r->registrationStatus?->status_code, ['registered', 'completed'], true)) $this->fail('Registration is not an academic attempt.', 'incomplete_registration_not_eligible');
        SupplementaryExamTargetGuard::assertOrdinaryMutationAvailable((int) $r->getKey());
        if (Schema::hasTable('supplementary_exam_theoretical_deferrals') && DB::table('supplementary_exam_theoretical_deferrals')
            ->where('student_course_registration_id', $r->getKey())->where('current_slot', 1)->where('status', 'declared')->exists()) $this->fail('An explicit supplementary deferral conflicts with I.', 'incomplete_supplementary_conflict');
    }
    private function acceptedDeadline(StudentCourseRegistration $r, array $input): array
    {
        if (isset($input['deadline_at'])) return ['at' => $this->manualDeadline($input['deadline_at']), 'source' => 'manual', 'snapshot' => null];
        $suggestion = $this->deadlines->suggest($r);
        if (!$suggestion['available']) throw ValidationException::withMessages(['deadline_at' => 'manual_deadline_required: published next-semester study period is missing or ambiguous.']);
        return ['at' => $this->manualDeadline($suggestion['deadline_at']), 'source' => 'calendar', 'snapshot' => $suggestion['calendar_snapshot']];
    }
    private function manualDeadline(string $value): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? CarbonImmutable::parse($value, 'Asia/Damascus')->endOfDay()->setMicrosecond(0) : CarbonImmutable::parse($value, 'Asia/Damascus');
        if ($date->utc()->lt($this->clock())) throw ValidationException::withMessages(['deadline_at' => 'The accepted deadline must not already be past.']);
        return $date->utc()->format('Y-m-d H:i:s');
    }

    private function completeComponents(StudentCourseRegistration $r, array $input, int $actorId): array
    {
        $components = GradeComponent::where('course_offering_id', $r->course_offering_id)->where('is_required', true)
            ->whereIn('component_type', ['theoretical', 'practical'])->orderBy('grade_component_id')->get()->keyBy('grade_component_id');
        if ($components->isEmpty()) $this->fail('Required component definitions are missing.', 'incomplete_components_not_configured');
        foreach ($input as $item) {
            $component = $components->get($item['grade_component_id']);
            if (!$component || !is_numeric($item['mark']) || !is_finite((float) $item['mark']) || (float) $item['mark'] < 0 || (float) $item['mark'] > (float) $component->max_mark) throw ValidationException::withMessages(['components' => 'A component or mark is outside the actual required limits.']);
            $old = DB::table('student_grade_components')->where('student_course_registration_id', $r->getKey())->where('grade_component_id', $component->getKey())->first();
            if ($old && $old->mark !== null) {
                if (\Brick\Math\BigDecimal::of((string) $old->mark)->compareTo((string) $item['mark']) !== 0) $this->fail('Completed work must be preserved; this operation only fills outstanding marks.', 'incomplete_completed_component_locked');
                continue;
            }
            $mark = (string) \Brick\Math\BigDecimal::of((string) $item['mark'])->toScale(2);
            // The explicit Exam Affairs settlement reviews these new marks for this student only.
            // Offering-level official visibility still requires the existing canonical approval.
            $values = ['mark' => $mark, 'grade_status' => 'approved',
                'entered_by_user_id' => $actorId, 'entered_at' => $this->now(), 'updated_at' => $this->now()];
            if ($old) { DB::table('student_grade_components')->where('student_grade_component_id', $old->student_grade_component_id)->update($values); $id = $old->student_grade_component_id; }
            else $id = DB::table('student_grade_components')->insertGetId($values + ['student_course_registration_id' => $r->getKey(), 'grade_component_id' => $component->getKey(), 'created_at' => $this->now()]);
            DB::table('grade_audit_logs')->insert(['student_grade_component_id' => $id, 'old_mark' => null, 'new_mark' => $mark,
                'changed_by_user_id' => $actorId, 'change_reason' => json_encode(['origin' => 'incomplete_resolution', 'registration_id' => $r->getKey()], JSON_THROW_ON_ERROR), 'changed_at' => $this->now()]);
        }
        $marks = DB::table('student_grade_components')->where('student_course_registration_id', $r->getKey())->get()->keyBy('grade_component_id');
        foreach ($components as $c) if (!$marks->get($c->getKey()) || $marks->get($c->getKey())->mark === null) $this->fail('Outstanding required marks are still missing.', 'incomplete_components_missing');
        $theory = $components->where('component_type', 'theoretical'); $practice = $components->where('component_type', 'practical');
        $calc = $this->grades->buildCalculationForRequiredParts($theory->isEmpty() ? null : $theory->sum(fn ($c) => (float) $marks->get($c->getKey())->mark),
            $practice->isEmpty() ? null : $practice->sum(fn ($c) => (float) $marks->get($c->getKey())->mark), $theory->isNotEmpty(), $practice->isNotEmpty(),
            (float) $theory->sum('max_mark'), (float) $practice->sum('max_mark'));
        return ['theoretical_total' => $calc['theoretical_mark'], 'practical_total' => $calc['practical_mark'], 'final_mark' => $calc['final_mark'],
            'result_status_id' => $this->statusId($calc['result_status_code']), 'is_deprived' => false];
    }
    private function completedPartTotals(StudentCourseRegistration $r): array
    {
        $components = DB::table('grade_components')->where('course_offering_id', $r->course_offering_id)->where('is_required', 1)->get();
        $marks = DB::table('student_grade_components')->where('student_course_registration_id', $r->getKey())->get()->keyBy('grade_component_id');
        $totals = ['theoretical' => null, 'practical' => null];
        foreach (array_keys($totals) as $part) {
            $required = $components->where('component_type', $part);
            if ($required->isEmpty() || $required->contains(fn ($c) => !$marks->has($c->grade_component_id) || $marks->get($c->grade_component_id)->mark === null
                || (float) $marks->get($c->grade_component_id)->mark < 0 || (float) $marks->get($c->grade_component_id)->mark > (float) $c->max_mark)) continue;
            $sum = \Brick\Math\BigDecimal::zero();
            foreach ($required as $c) $sum = $sum->plus((string) $marks->get($c->grade_component_id)->mark);
            if ($sum->compareTo('999.99') > 0) $this->fail('Completed part total exceeds the persisted column capacity.', 'incomplete_component_configuration_invalid');
            $totals[$part] = (string) $sum->toScale(2);
        }
        return $totals;
    }
    /** Private trusted boundary. Query-builder writes cannot be reached through generic grade CRUD. */
    private function writeResult(StudentCourseRegistration $r, array $values, ?int $actorId): int
    {
        $values += ['calculated_at' => $this->now(), 'calculated_by_user_id' => $actorId, 'updated_at' => $this->now()];
        if ($r->studentCourseResult) {
            $id = (int) $r->studentCourseResult->getKey();
            DB::table('student_course_results')->where('student_course_result_id', $id)->update($values);
        } else {
            $id = DB::table('student_course_results')->insertGetId($values + ['student_course_registration_id' => $r->getKey(),
                'theoretical_total' => null, 'practical_total' => null, 'coursework_total' => 0, 'created_at' => $this->now()]);
        }
        DB::table('student_course_registrations')->where('student_course_registration_id', $r->getKey())->update(['result_status_id' => $values['result_status_id'], 'updated_at' => $this->now()]);
        return $id;
    }
    private function resultSnapshot(int $id): array
    {
        $row = DB::table('student_course_results')->where('student_course_registration_id', $id)->first();
        $fields = array_values(array_filter(Guard::RESULT_FIELDS, fn ($f) => property_exists($row, $f)));
        return array_intersect_key((array) $row, array_flip($fields));
    }
    private function settle(CourseIncompleteCase $case, string $state): void
    {
        DB::table(Guard::CASES)->where('incomplete_case_id', $case->getKey())->update(['state' => $state, 'settled_at' => $this->now(),
            'revision' => $case->revision + 1, 'outcome_snapshot' => json_encode($this->resultSnapshot((int) $case->student_course_registration_id), JSON_THROW_ON_ERROR), 'updated_at' => $this->now()]);
    }
    private function record(CourseIncompleteCase $case, string $action, ?array $before, ?int $actorId, string $requestId, string $hash): array
    {
        $result = ['case' => $case->toArray(), 'changed' => true];
        DB::table(Guard::EVENTS)->insert(['incomplete_case_id' => $case->getKey(), 'revision' => $case->revision, 'action' => $action,
            'request_id' => $requestId, 'request_hash' => $hash, 'actor_user_id' => $actorId, 'actor_kind' => $actorId === null ? 'system' : 'user',
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_values' => json_encode($case->toArray(), JSON_THROW_ON_ERROR),
            'response_snapshot' => json_encode($result, JSON_THROW_ON_ERROR), 'performed_at' => $this->now()]);
        return $result;
    }
    private function validateAction(string $action, array $input): void
    {
        $allowed = ['request_id', 'expected_revision'];
        $allowed = array_merge($allowed, match ($action) { 'grant' => ['reason', 'requirements', 'expiry_outcome', 'deadline_at'], 'deadline_changed' => ['deadline_at'], 'resolved' => ['components'] });
        if (array_diff(array_keys($input), $allowed)) throw ValidationException::withMessages(['request' => 'Fields are not allowed for this operation.']);
        $required = match ($action) { 'grant' => ['reason', 'requirements', 'expiry_outcome'], 'deadline_changed' => ['deadline_at'], default => [] };
        foreach ($required as $key) if (!isset($input[$key]) || trim((string) $input[$key]) === '') throw ValidationException::withMessages([$key => 'This field is required.']);
    }
    private function requestHash(int $id, string $action, array $input): string
    {
        unset($input['request_id']);
        ksort($input);
        if (isset($input['components'])) { usort($input['components'], fn ($a, $b) => $a['grade_component_id'] <=> $b['grade_component_id']); foreach ($input['components'] as &$item) { $item['mark'] = (string) \Brick\Math\BigDecimal::of((string) $item['mark'])->toScale(2); ksort($item); } unset($item); }
        return hash('sha256', json_encode([$id, $action, $input], JSON_THROW_ON_ERROR));
    }
    private function statusId(string $code): int
    {
        $id = DB::table('result_statuses')->where('status_code', $code)->where('is_active', 1)->value('result_status_id');
        if (!$id) $this->fail('Required result status is not configured.', 'result_status_missing');
        return (int) $id;
    }
    private function now(): string { return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'); }
    private function clock(): CarbonImmutable { return CarbonImmutable::now('UTC')->setMicrosecond(0); }
    private function fail(string $message, string $code): never { throw new GradeException($message, status: 409, errorCode: $code); }
}
