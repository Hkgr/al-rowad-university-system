<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\ApprovalStatus;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\GradeAuditLog;
use App\Models\GradeComponent;
use App\Models\GradePartApproval;
use App\Models\GradePartApprovalEvent;
use App\Models\ResultStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GradePartWorkflowService
{
    public function __construct(private readonly DataScopeService $dataScope, private readonly GradeService $grades) {}

    public function workflow(int $offeringId): array
    {
        $offering = CourseOffering::query()->with(['course', 'gradeComponents' => fn ($q) => $q->where('is_required', true)])->findOrFail($offeringId);
        $required = $this->requiredParts($offeringId);
        $approvals = GradePartApproval::query()->where('course_offering_id', $offeringId)->get()->keyBy('component_type');
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()
            ->with(['student', 'registrationStatus', 'studentCourseResult.resultStatus', 'studentGradeComponents.gradeComponent'])->get();

        $parts = [];
        foreach (GradePartApproval::PARTS as $part) {
            $approval = $approvals->get($part);
            $status = $approval?->status ?? 'draft';
            $requiredPart = in_array($part, $required, true);
            $parts[$part] = [
                'required' => $requiredPart, 'status' => $status, 'approval_id' => $approval?->grade_part_approval_id,
                'submission_version' => $approval?->submission_version ?? 0,
                'can_edit' => $requiredPart && in_array($status, ['draft', 'returned'], true),
                'can_submit' => $requiredPart && in_array($status, ['draft', 'returned'], true) && $this->partComplete($registrations, $offering->gradeComponents, $part),
                'submitted_at' => $approval?->submitted_at?->toISOString(), 'reviewed_at' => $approval?->reviewed_at?->toISOString(),
                'review_notes' => $approval?->review_notes,
            ];
        }

        return [
            'course_offering_id' => $offeringId, 'course' => ['course_id' => $offering->course_id, 'course_code' => $offering->course?->course_code, 'course_name' => $offering->course?->course_name],
            'required_parts' => $required, 'parts' => $parts,
            'students' => $registrations->map(fn ($registration) => [
                'registration_id' => $registration->student_course_registration_id,
                'registration_status' => $registration->registrationStatus?->status_code,
                'is_deprived' => (bool) $registration->studentCourseResult?->is_deprived,
                'student' => ['student_id' => $registration->student_id, 'student_number' => $registration->student?->student_number, 'first_name' => $registration->student?->first_name, 'last_name' => $registration->student?->last_name],
                'marks' => collect(GradePartApproval::PARTS)->mapWithKeys(fn ($part) => [$part => $registration->studentGradeComponents->filter(fn ($grade) => $grade->gradeComponent?->component_type === $part)->map(fn ($grade) => ['grade_component_id' => $grade->grade_component_id, 'mark' => $grade->mark === null ? null : (float) $grade->mark, 'max_mark' => (float) $grade->gradeComponent->max_mark])->values()->all()])->all(),
            ])->values()->all(),
        ];
    }

    public function savePart(StudentCourseRegistration $registration, string $part, array $data, int $userId): array
    {
        $this->assertPart($part);
        return DB::transaction(function () use ($registration, $part, $data, $userId): array {
            $locked = StudentCourseRegistration::query()->whereKey($registration->student_course_registration_id)->lockForUpdate()->firstOrFail();
            $this->assertRequired((int) $locked->course_offering_id, $part);
            $approval = $this->lockApproval((int) $locked->course_offering_id, $part);
            if ($approval && ! in_array($approval->status, ['draft', 'returned'], true)) $this->fail('This grade part is locked.', 'grade_part_locked');

            $components = GradeComponent::query()->where('course_offering_id', $locked->course_offering_id)->where('component_type', $part)->where('is_required', true)->lockForUpdate()->get()->keyBy('grade_component_id');
            $input = $data['components'] ?? null;
            if ($input === null) {
                if ($components->count() !== 1) $this->fail('Component marks are required when a part has multiple components.', 'invalid_grade_part');
                $input = [['grade_component_id' => $components->keys()->first(), 'mark' => $data['mark']]];
            }
            foreach ($input as $item) {
                $component = $components->get($item['grade_component_id']);
                if (! $component || ($item['mark'] !== null && (float) $item['mark'] > (float) $component->max_mark)) $this->fail('A mark is outside the grade component limits.', 'invalid_grade_part');
                $grade = StudentGradeComponent::query()->where('student_course_registration_id', $locked->student_course_registration_id)->where('grade_component_id', $component->grade_component_id)->lockForUpdate()->first();
                $old = $grade?->mark;
                $grade = StudentGradeComponent::query()->updateOrCreate(
                    ['student_course_registration_id' => $locked->student_course_registration_id, 'grade_component_id' => $component->grade_component_id],
                    ['mark' => $item['mark'], 'grade_status' => $approval?->status === 'returned' ? 'returned' : 'draft', 'entered_by_user_id' => $userId, 'entered_at' => now(), 'notes' => $data['notes'] ?? null]
                );
                GradeAuditLog::query()->create(['student_grade_component_id' => $grade->student_grade_component_id, 'old_mark' => $old, 'new_mark' => $item['mark'], 'changed_by_user_id' => $userId, 'change_reason' => 'grade_part_saved:'.$part, 'changed_at' => now()]);
            }
            return $this->workflow((int) $locked->course_offering_id);
        });
    }

    public function submit(int $offeringId, string $part, int $userId): GradePartApproval
    {
        $this->assertPart($part);
        return DB::transaction(function () use ($offeringId, $part, $userId): GradePartApproval {
            CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->firstOrFail();
            $this->assertRequired($offeringId, $part);
            $approval = $this->lockApproval($offeringId, $part);
            if ($approval?->status === 'approved') $this->fail('This grade part is already approved.', 'grade_part_already_approved');
            if ($approval?->status === 'submitted') return $approval; // idempotent retry
            if ($approval && ! in_array($approval->status, ['draft', 'returned'], true)) $this->fail('This grade part cannot be submitted.', 'grade_part_already_submitted');
            $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()->lockForUpdate()->get();
            $components = GradeComponent::query()->where('course_offering_id', $offeringId)->where('component_type', $part)->where('is_required', true)->get();
            if ($registrations->isEmpty() || ! $this->partComplete($registrations, $components, $part)) $this->fail('Required marks for this grade part are incomplete.', 'grade_part_incomplete');
            $old = $approval?->toArray();
            $values = ['status' => 'submitted', 'submission_version' => ($approval?->submission_version ?? 0) + 1, 'submitted_by_user_id' => $userId, 'submitted_at' => now(), 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_notes' => null];
            $approval ? $approval->update($values) : $approval = GradePartApproval::query()->create(['course_offering_id' => $offeringId, 'component_type' => $part] + $values);
            StudentGradeComponent::query()->whereIn('student_course_registration_id', $registrations->pluck('student_course_registration_id'))->whereIn('grade_component_id', $components->pluck('grade_component_id'))->update(['grade_status' => 'submitted']);
            $this->event($approval, 'submitted', $old, $approval->fresh()->toArray(), $userId);
            return $approval->fresh();
        });
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $this->dataScope->scopeResourceQuery(GradePartApproval::query(), $user)->with('courseOffering.course')
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['component_type'] ?? null, fn (Builder $q, $v) => $q->where('component_type', $v))
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->orderBy('submitted_at')->paginate($filters['per_page'] ?? 15);
    }

    public function find(User $user, int $id): GradePartApproval
    {
        $approval = $this->dataScope->scopeResourceQuery(GradePartApproval::query(), $user)->with(['courseOffering.course', 'events'])->find($id);
        if (! $approval && GradePartApproval::query()->whereKey($id)->exists()) $this->fail('You are not authorized to access this grade part.', 'unauthorized_grade_part', 403);
        return $approval ?? GradePartApproval::query()->findOrFail($id);
    }

    public function review(User $user, int $id, string $action, ?string $notes): GradePartApproval
    {
        $visible = $this->find($user, $id);
        return DB::transaction(function () use ($visible, $user, $action, $notes): GradePartApproval {
            CourseOffering::query()->whereKey($visible->course_offering_id)->lockForUpdate()->firstOrFail();
            $approval = GradePartApproval::query()->whereKey($visible->grade_part_approval_id)->lockForUpdate()->firstOrFail();
            if ($approval->status === 'approved' && $action === 'approve') return $approval;
            if ($approval->status !== 'submitted') $this->fail('Only a submitted grade part may be reviewed.', 'grade_part_not_submitted');
            if ($action === 'return' && trim((string) $notes) === '') $this->fail('Review notes are required.', 'missing_review_notes');
            $old = $approval->toArray();
            $approval->update(['status' => $action === 'approve' ? 'approved' : 'returned', 'reviewed_by_user_id' => $user->user_id, 'reviewed_at' => now(), 'review_notes' => $notes]);
            $this->setComponentStatus($approval, $action === 'approve' ? 'approved' : 'returned');
            $this->event($approval, $action === 'approve' ? 'approved' : 'returned', $old, $approval->fresh()->toArray(), $user->user_id);
            if ($action === 'approve' && $this->allRequiredApproved((int) $approval->course_offering_id)) $this->finalize((int) $approval->course_offering_id, $user->user_id);
            return $approval->fresh(['courseOffering.course', 'events']);
        });
    }

    private function finalize(int $offeringId, int $userId): void
    {
        $required = $this->requiredParts($offeringId);
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()->lockForUpdate()->get();
        $components = GradeComponent::query()->where('course_offering_id', $offeringId)->where('is_required', true)->get();
        foreach ($registrations as $registration) {
            $existing = StudentCourseResult::query()->where('student_course_registration_id', $registration->student_course_registration_id)->lockForUpdate()->first();
            $marks = StudentGradeComponent::query()->where('student_course_registration_id', $registration->student_course_registration_id)->whereIn('grade_component_id', $components->pluck('grade_component_id'))->get()->keyBy('grade_component_id');
            $totals = [];
            foreach (GradePartApproval::PARTS as $part) $totals[$part] = in_array($part, $required, true) ? $components->where('component_type', $part)->sum(fn ($c) => (float) $marks->get($c->grade_component_id)?->mark) : null;
            $calculation = $this->grades->buildCalculationForRequiredParts($totals['theoretical'], $totals['practical'], in_array('theoretical', $required, true), in_array('practical', $required, true), $existing?->resultStatus?->status_code, (bool) $existing?->is_deprived);
            $statusId = ResultStatus::query()->where('status_code', $calculation['result_status_code'])->value('result_status_id');
            StudentCourseResult::query()->updateOrCreate(['student_course_registration_id' => $registration->student_course_registration_id], ['theoretical_total' => $totals['theoretical'], 'practical_total' => $totals['practical'], 'coursework_total' => 0, 'final_mark' => $calculation['final_mark'], 'result_status_id' => $statusId, 'is_deprived' => $calculation['result_status_code'] === 'deprived', 'calculated_at' => now(), 'calculated_by_user_id' => $userId]);
            $registration->update(['result_status_id' => $statusId]);
        }
        $approvedStatus = ApprovalStatus::query()->where('status_code', 'approved')->value('approval_status_id');
        $submitter = GradePartApproval::query()->where('course_offering_id', $offeringId)->whereIn('component_type', $required)->orderByDesc('submitted_at')->first();
        GradeApproval::query()->updateOrCreate(['course_offering_id' => $offeringId], ['approval_status_id' => $approvedStatus, 'submitted_by_user_id' => $submitter?->submitted_by_user_id ?? $userId, 'submitted_at' => $submitter?->submitted_at ?? now(), 'approved_by_user_id' => $userId, 'approval_role' => 'examination_committee', 'approval_date' => now(), 'approval_notes' => 'Finalized after all required grade parts were approved.']);
    }

    private function requiredParts(int $offeringId): array { return GradeComponent::query()->where('course_offering_id', $offeringId)->where('is_required', true)->whereIn('component_type', GradePartApproval::PARTS)->distinct()->pluck('component_type')->values()->all(); }
    private function assertRequired(int $offeringId, string $part): void { if (! in_array($part, $this->requiredParts($offeringId), true)) $this->fail('This grade part is not required.', 'grade_part_not_required'); }
    private function assertPart(string $part): void { if (! in_array($part, GradePartApproval::PARTS, true)) $this->fail('The grade part must be practical or theoretical.', 'invalid_grade_part'); }
    private function lockApproval(int $offeringId, string $part): ?GradePartApproval { return GradePartApproval::query()->where('course_offering_id', $offeringId)->where('component_type', $part)->lockForUpdate()->first(); }
    private function allRequiredApproved(int $offeringId): bool { $required = $this->requiredParts($offeringId); return $required !== [] && GradePartApproval::query()->where('course_offering_id', $offeringId)->whereIn('component_type', $required)->where('status', 'approved')->count() === count($required); }
    private function partComplete($registrations, $components, string $part): bool
    {
        $partComponents = $components->where('component_type', $part); if ($partComponents->isEmpty()) return false;
        foreach ($registrations as $registration) {
            if ($registration->studentCourseResult?->is_deprived) continue;
            $grades = StudentGradeComponent::query()->where('student_course_registration_id', $registration->student_course_registration_id)->whereIn('grade_component_id', $partComponents->pluck('grade_component_id'))->get()->keyBy('grade_component_id');
            foreach ($partComponents as $component) { $grade = $grades->get($component->grade_component_id); if (! $grade || $grade->mark === null || (float) $grade->mark < 0 || (float) $grade->mark > (float) $component->max_mark) return false; }
        }
        return true;
    }
    private function setComponentStatus(GradePartApproval $approval, string $status): void { StudentGradeComponent::query()->whereHas('studentCourseRegistration', fn ($q) => $q->where('course_offering_id', $approval->course_offering_id))->whereHas('gradeComponent', fn ($q) => $q->where('component_type', $approval->component_type))->lockForUpdate()->update(['grade_status' => $status]); }
    private function event(GradePartApproval $approval, string $action, ?array $old, array $new, int $userId): void { GradePartApprovalEvent::query()->create(['grade_part_approval_id' => $approval->grade_part_approval_id, 'submission_version' => $approval->submission_version, 'action' => $action, 'old_values' => $old, 'new_values' => $new, 'performed_by_user_id' => $userId, 'performed_at' => now()]); }
    private function fail(string $message, string $code, int $status = 409): never { throw new GradeException($message, status: $status, errorCode: $code); }
}
