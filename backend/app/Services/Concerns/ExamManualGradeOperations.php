<?php

namespace App\Services\Concerns;

use App\Exceptions\GradeException;
use App\Models\CourseOffering;
use App\Models\GradeComponent;
use App\Models\GradePartApproval;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentGradeComponent;
use App\Models\User;
use App\Models\SupplementaryExamRegistration;
use App\Support\SupplementaryExamRegistrationGovernance;
use App\Support\ExamManualGradeEntryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Separate authorized entry points; persistence/submission remain owned by GradePartWorkflowService. */
trait ExamManualGradeOperations
{
    /** Bounded eager loading for both the selected-student page and offering-wide readiness. No persistent cache. */
    public function manualSnapshots(\Illuminate\Support\Collection $registrations): \Illuminate\Support\Collection
    {
        if ($registrations->isEmpty()) return collect();
        $rows = new \Illuminate\Database\Eloquent\Collection($registrations->all());
        $rows->loadMissing(['registrationStatus', 'resultStatus', 'studentCourseResult.resultStatus',
            'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester',
            'courseOffering.academicProgram.department.college', 'courseOffering.gradePartApprovals',
            'courseOffering.gradeComponents', 'courseOffering.gradeApprovals.approvalStatus',
            'studentGradeComponents' => fn ($q) => $q->withMax('gradeAuditLogs', 'grade_audit_log_id')]);
        $context = $this->supplementaryEligibility->evaluationContext(collect(), $rows);
        // Validate canonical fixed-roster schema before its bounded informational projection.
        $schemaError = null;
        try { $this->grades->assertNotSupplementaryMaterialized((int) $rows->first()->getKey()); }
        catch (GradeException $e) { if ($e->status === 503) $schemaError = $e->errorCode; }
        $context['fixed_ids'] = $schemaError !== null ? collect() : SupplementaryExamRegistration::query()
            ->whereIn('student_course_registration_id', $rows->modelKeys())->where('status', 'registered')->where('current_slot', 1)
            ->whereHas('offering.period', fn ($q) => $q->whereIn('status', SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES))
            ->pluck('student_course_registration_id');
        $context['schema_error'] = $schemaError;
        return $rows->map(fn ($r) => $this->manualRegistration($r, $context));
    }

    public function manualRegistration(StudentCourseRegistration $registration, ?array $context = null): array
    {
        if ($context === null) return $this->manualSnapshots(collect([$registration]))->first();
        $offering = $registration->courseOffering;
        $components = $offering->gradeComponents->where('is_required', true)
            ->whereIn('component_type', GradePartApproval::PARTS)->sortBy('grade_component_id');
        $marks = $registration->studentGradeComponents->keyBy('grade_component_id');
        $approvals = $offering->gradePartApprovals->keyBy('component_type');
        $blocked = $context['schema_error'];
        if ($context['materialized_target_ids']->contains((int) $registration->getKey())) $blocked ??= 'supplementary_materialized_result_locked';
        if ($context['fixed_ids']->contains((int) $registration->getKey())) $blocked ??= 'supplementary_fixed_roster_target_locked';
        if (! $registration->allowsGradeEntry()) $blocked ??= 'grade_entry_not_allowed';
        if ($this->registrationIsDeprived($registration)) $blocked ??= 'deprived_student_grade_locked';
        if ($this->grades->isOfficiallyApprovedOffering($offering)) $blocked ??= 'official_result_locked';
        $deferral = $this->supplementaryEligibility->activeValidDeferral($registration, context: $context);
        $parts = [];
        foreach ($components->pluck('component_type')->unique()->sort()->values() as $part) {
            $status = $approvals->get($part)?->status ?? 'draft';
            $reason = $blocked ?? (! in_array($status, ['draft', 'returned'], true) ? 'grade_part_locked' : null);
            if ($part === 'theoretical' && $deferral !== null) $reason ??= 'supplementary_theoretical_deferred';
            $parts[$part] = ['status' => $status, 'can_edit' => $reason === null,
                // Readiness is offering-wide and must be fetched separately before submission.
                'can_check_submission' => $registration->allowsGradeEntry() && in_array($status, ['draft', 'returned'], true) && $blocked !== 'official_result_locked', 'blocked_reason' => $reason,
                'submission_version' => (int) ($approvals->get($part)?->submission_version ?? 0)];
        }
        $program = $offering->academicProgram;
        $data = [
            'registration_id' => (int) $registration->getKey(), 'student_id' => (int) $registration->student_id,
            'registration_status' => $registration->registrationStatus?->status_code,
            'course_offering_id' => (int) $offering->getKey(), 'course_id' => (int) $offering->course_id,
            'course_code' => $offering->course?->course_code, 'course_name' => $offering->course?->course_name,
            'academic_year_id' => (int) $offering->academic_year_id, 'academic_year' => $offering->academicYear?->year_name,
            'semester_id' => (int) $offering->semester_id, 'semester' => $offering->semester?->semester_name,
            'academic_program_id' => $offering->academic_program_id, 'program' => $program?->program_name,
            'college' => $program?->department?->college?->college_name,
            // There is no separate section label in the persisted offering schema.
            'section' => (string) $offering->getKey(), 'parts' => $parts,
            'blocked_reason' => $components->isEmpty() ? 'grade_part_not_required' : $blocked,
            'components' => $components->map(fn ($c) => ['grade_component_id' => (int) $c->getKey(),
                'component_type' => $c->component_type, 'name' => $c->component_name,
                'max_mark' => (float) $c->max_mark, 'mark' => $marks->get($c->getKey())?->mark === null
                    ? null : (float) $marks->get($c->getKey())->mark])->values()->all(),
        ];
        $data['revision'] = hash('sha256', json_encode([$data,
            $registration->only(['registration_status_id', 'result_status_id', 'updated_at']),
            $registration->studentCourseResult?->getAttributes(),
            $marks->sortKeys()->map->getAttributes()->values()->all(),
            $approvals->sortKeys()->map->getAttributes()->values()->all(),
            $offering->gradeApprovals->sortBy('grade_approval_id')->map->getAttributes()->values()->all(),
            $deferral?->getKey(),
        ], JSON_THROW_ON_ERROR));
        return $data;
    }

    public function saveManualMarks(Student $student, StudentCourseRegistration $registration, array $data, User $actor): array
    {
        app(ExamManualGradeEntryAccess::class)->authorize($actor, $student, $registration);
        return DB::transaction(function () use ($student, $registration, $data, $actor): array {
            $locked = $this->lockManualContext($student, $registration, $actor);
            $snapshot = $this->manualRegistration($locked);
            $this->assertManualRevision($data['revision'], $snapshot['revision']);
            if (($data['acknowledged'] ?? false) !== true) throw ValidationException::withMessages(['acknowledged' => 'يجب الإقرار بصحة العلامات.']);
            $components = collect($snapshot['components'])->keyBy('grade_component_id');
            $groups = [];
            $ids = [];
            foreach ($data['components'] as $input) {
                $id = (int) $input['grade_component_id'];
                $component = $components->get($id);
                $mark = $input['mark'];
                if (! $component || isset($ids[$id]) || ($mark !== null && (! is_numeric($mark)
                    || ! is_finite((float) $mark) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $mark)
                    || (float) $mark < 0 || (float) $mark > $component['max_mark']))) {
                    throw ValidationException::withMessages(['components' => 'مكوّن أو علامة غير صالحين.']);
                }
                $ids[$id] = true;
                $part = $component['component_type'];
                if (! $snapshot['parts'][$part]['can_edit']) $this->fail('This grade part is locked.', $snapshot['parts'][$part]['blocked_reason']);
                $changed = $component['mark'] !== ($mark === null ? null : (float) $mark);
                if ($changed && $component['mark'] !== null && (($data['correction_confirmed'] ?? false) !== true || trim($data['correction_reason'] ?? '') === '')) {
                    throw ValidationException::withMessages(['correction_reason' => 'تغيير علامة موجودة يتطلب تأكيدًا وسببًا.']);
                }
                if ($changed) $groups[$part][] = $input;
            }
            ksort($groups);
            foreach ($groups as $part => $input) {
                $this->persistPartInTransaction($locked, $part, ['components' => $input], $actor,
                    json_encode(['origin' => 'exam_board_manual_entry', 'part' => $part,
                        'reason' => trim($data['correction_reason'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            return $this->manualRegistration($locked->fresh());
        });
    }

    private function lockManualContext(Student $student, StudentCourseRegistration $registration, User $actor): StudentCourseRegistration
    {
        CourseOffering::query()->whereKey($registration->course_offering_id)->lockForUpdate()->firstOrFail();
        GradePartApproval::query()->where('course_offering_id', $registration->course_offering_id)
            ->orderBy('component_type')->lockForUpdate()->get();
        $locked = StudentCourseRegistration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();
        if ((int) $locked->course_offering_id !== (int) $registration->course_offering_id) $this->fail('Registration context changed.', 'manual_grade_entry_stale');
        app(ExamManualGradeEntryAccess::class)->authorize($actor, $student, $locked);
        GradeComponent::query()->where('course_offering_id', $locked->course_offering_id)->orderBy('grade_component_id')->lockForUpdate()->get();
        StudentGradeComponent::query()->where('student_course_registration_id', $locked->getKey())->orderBy('student_grade_component_id')->lockForUpdate()->get();
        return $locked;
    }

    public function manualSubmissionReadiness(StudentCourseRegistration $registration, string $part): array
    {
        $this->assertPart($part);
        $this->assertRequired((int) $registration->course_offering_id, $part);
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $registration->course_offering_id)
            ->current()->orderBy('student_course_registration_id')->get();
        $components = GradeComponent::query()->where('course_offering_id', $registration->course_offering_id)
            ->where('component_type', $part)->where('is_required', true)->get();
        $counts = ['eligible' => 0, 'completed' => 0, 'incomplete' => 0, 'exempt' => 0];
        $revisions = [];
        $block = null;
        $snapshots = $this->manualSnapshots($registrations)->keyBy('registration_id');
        foreach ($registrations as $row) {
            $snapshot = $snapshots->get($row->getKey());
            $revisions[] = [$row->getKey(), $snapshot['revision']];
            $reason = $snapshot['parts'][$part]['blocked_reason'];
            if (in_array($reason, ['deprived_student_grade_locked', 'supplementary_theoretical_deferred'], true)) {
                $counts['exempt']++;
            } else {
                $counts['eligible']++;
                if ($this->requiredMarksComplete($row->studentGradeComponents, $components)) $counts['completed']++;
                else $counts['incomplete']++;
                $block ??= $reason;
            }
        }
        $approval = GradePartApproval::query()->where('course_offering_id', $registration->course_offering_id)->where('component_type', $part)->first();
        if (! in_array($approval?->status ?? 'draft', ['draft', 'returned'], true)) $block = 'grade_part_locked';
        $selected = $snapshots->get($registration->getKey()) ?? $this->manualRegistration($registration->fresh());
        if ($selected['registration_status'] !== StudentCourseRegistration::CURRENT_STATUS) $block = 'grade_entry_not_allowed';
        return ['course_offering_id' => (int) $registration->course_offering_id, 'part' => $part,
            'course_name' => $selected['course_name'], 'section' => $selected['section'],
            'academic_year' => $selected['academic_year'], 'semester' => $selected['semester'],
            'counts' => $counts, 'status' => $approval?->status ?? 'draft',
            'can_submit' => $block === null && $registrations->isNotEmpty() && $counts['incomplete'] === 0,
            'blocked_reason' => $block ?? ($registrations->isEmpty() || $counts['incomplete'] > 0 ? 'grade_part_incomplete' : null),
            'revision' => hash('sha256', json_encode([$part, $revisions, $approval?->getAttributes(), $selected['revision']], JSON_THROW_ON_ERROR))];
    }

    public function submitManualPart(Student $student, StudentCourseRegistration $registration, string $part, array $data, User $actor): array
    {
        app(ExamManualGradeEntryAccess::class)->authorize($actor, $student, $registration);
        return DB::transaction(function () use ($student, $registration, $part, $data, $actor): array {
            // Own offering first, then approvals, then the full roster in ID order.
            CourseOffering::query()->whereKey($registration->course_offering_id)->lockForUpdate()->firstOrFail();
            GradePartApproval::query()->where('course_offering_id', $registration->course_offering_id)->orderBy('component_type')->lockForUpdate()->get();
            StudentCourseRegistration::query()->where('course_offering_id', $registration->course_offering_id)->orderBy('student_course_registration_id')->lockForUpdate()->get();
            $locked = $this->lockManualContext($student, $registration, $actor);
            $ready = $this->manualSubmissionReadiness($locked, $part);
            $this->assertManualRevision($data['revision'], $ready['revision']);
            if (($data['confirmed'] ?? false) !== true) throw ValidationException::withMessages(['confirmed' => 'يجب تأكيد إرسال الجزء للطرح كاملًا.']);
            if (! $ready['can_submit']) $this->fail('The offering part is not ready.', $ready['blocked_reason'] ?? 'grade_part_incomplete');
            $this->submitPartInTransaction((int) $locked->course_offering_id, $part, (int) $actor->getKey());
            return $this->manualRegistration($locked->fresh());
        });
    }

    private function assertManualRevision(string $expected, string $actual): void
    {
        if (! hash_equals($actual, $expected)) $this->fail('تغيرت العلامات أو حالة الاعتماد. أعد تحميل البيانات.', 'manual_grade_entry_stale');
    }
}
