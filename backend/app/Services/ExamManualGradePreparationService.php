<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\{AcademicYear, Course, CourseOffering, GradeComponent, GradePartApproval, Student, StudentCourseRegistration, User, UserActivityLog};
use App\Support\{ExamManualGradeEntryAccess, SupplementaryExamTargetGuard};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit recording boundary. GET never creates anything. Save is one atomic draft transaction. */
final class ExamManualGradePreparationService
{
    public function __construct(private ExamManualGradeEntryAccess $access, private DataScopeService $scope,
        private CourseOfferingContextService $contexts, private ExamManualGradeContextService $components,
        private RegistrationService $registrations, private GradeService $grades, private GradePartWorkflowService $workflow) {}

    public function preview(User $actor, Student $student, Course $course, array $input): array
    {
        $resolved = $this->resolve($actor, $student, $course, $input);
        return $this->describe($resolved);
    }

    private function resolve(User $actor, Student $student, Course $course, array $input): array
    {
        $this->access->authorize($actor, $student);
        abort_unless($this->scope->scopeManualGradeCourses(Course::query(), $actor)->whereKey($course->getKey())->exists(), 403);
        $year = AcademicYear::findOrFail($input['academic_year_id']);
        $semester = \App\Models\Semester::findOrFail($input['semester_id']);
        $query = CourseOffering::query()->where('course_id', $course->getKey())
            ->where('academic_year_id', $year->getKey())->where('semester_id', $semester->getKey());
        $choices = $this->scope->scopeManualGradeOfferings(clone $query, $actor)->orderBy('course_offering_id')->get();
        if (isset($input['course_offering_id'])) {
            $offering = $choices->firstWhere('course_offering_id', $input['course_offering_id']);
            abort_unless($offering, 403);
        } else {
            if ($choices->count() > 1) $this->fail('اختر الطرح الفعلي صراحةً؛ يوجد أكثر من سياق.', 'manual_registration_ambiguous');
            $offering = $choices->count() === 1 ? $choices->first() : null;
        }
        $context = null;
        if ($offering === null) {
            abort_unless($this->scope->canMutateProgram($actor, (int) $student->academic_program_id), 403);
            // Shared identity/curriculum resolver; no academic-plan or opening approval is fabricated.
            $context = $this->contexts->resolveContext((int) $course->getKey(), (int) $student->academic_program_id,
                (int) $year->getKey(), (int) $semester->getKey());
            $offering = new CourseOffering($context->offeringAttributes() + ['status' => 'closed']);
            $offering->setRelation('course', $course);
        } else {
            $this->access->authorizeOffering($actor, $student, $offering);
            if (!in_array($offering->status, ['open', 'closed'], true)) $this->fail('حالة الطرح غير مدعومة.', 'manual_academic_context_invalid');
            if ($this->grades->isOfficiallyApprovedOffering($offering)
                || $offering->gradePartApprovals()->get()->contains(fn ($p) => !in_array($p->status, ['draft', 'returned'], true))) {
                $this->fail('الطرح مقفل بسبب الإرسال أو الاعتماد الرسمي.', 'official_result_locked');
            }
            SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsReadable([$offering->getKey()]);
        }
        $attempts = StudentCourseRegistration::query()->where('student_id', $student->getKey())
            ->whereHas('courseOffering', fn ($q) => $q->where('course_id', $course->getKey())
                ->where('academic_year_id', $year->getKey())->where('semester_id', $semester->getKey()))
            ->with('registrationStatus')->orderBy('student_course_registration_id')->get();
        if (isset($input['registration_id'])) {
            $registration = $attempts->firstWhere('student_course_registration_id', $input['registration_id']);
            abort_unless($registration && (int) $registration->course_offering_id === (int) $offering->getKey(), 404);
        } else {
            if ($attempts->count() > 1) $this->fail('اختر المحاولة الموجودة صراحةً؛ لا تُنشأ محاولة بديلة.', 'manual_registration_ambiguous');
            $registration = $attempts->count() === 1 ? $attempts->first() : null;
            if ($registration && (int) $registration->course_offering_id !== (int) $offering->getKey()) {
                $this->fail('للطالب محاولة في طرح آخر من الفصل نفسه؛ اخترها صراحةً.', 'manual_registration_ambiguous');
            }
        }
        if ($registration) {
            $this->access->authorize($actor, $student, $registration);
            SupplementaryExamTargetGuard::assertOrdinaryMutationAvailable((int) $registration->getKey());
            if (!$registration->allowsGradeEntry()) $this->fail('حالة المحاولة لا تسمح بإدخال العلامات.', 'manual_registration_ineligible');
        } else {
            $this->registrations->assertManualGradeAcademicIdentity($student, $offering);
            $this->registrations->assertAcademicRegistrationCandidate($student, $offering);
        }
        $plan = $this->components->recordingComponentPlan($offering);
        $snapshot = $registration ? $this->workflow->manualRegistration($registration) : null;
        if (isset($snapshot['blocked_reason']) && $snapshot['blocked_reason'] !== 'grade_part_not_required') {
            $this->fail('المحاولة مقفلة: '.$snapshot['blocked_reason'], $snapshot['blocked_reason']);
        }
        // Existing exemptions (deprivation/deferral) never become a preparation bypass.
        foreach ($snapshot['parts'] ?? [] as $part) if (!$part['can_edit']) {
            $this->fail('المحاولة مقفلة: '.$part['blocked_reason'], $part['blocked_reason']);
        }
        return compact('student', 'course', 'year', 'semester', 'offering', 'registration', 'context', 'plan', 'snapshot');
    }

    private function describe(array $r): array
    {
        $marks = collect($r['snapshot']['components'] ?? [])->keyBy('grade_component_id');
        $data = ['student_id' => (int) $r['student']->getKey(), 'course_id' => (int) $r['course']->getKey(),
            'academic_year_id' => (int) $r['year']->getKey(), 'semester_id' => (int) $r['semester']->getKey(),
            'academic_year' => $r['year']->year_name, 'semester' => $r['semester']->semester_name,
            'program' => $r['offering']->academicProgram?->program_name,
            'course_offering_id' => $r['offering']->getKey(), 'registration_id' => $r['registration']?->getKey(),
            'create_offering' => !$r['offering']->exists, 'create_registration' => $r['registration'] === null,
            'create_components' => $r['plan']['create'], 'offering_status' => $r['offering']->status,
            'exemptions' => ExamManualGradeEntryAccess::RECORDING_EXEMPTIONS,
            'components' => collect($r['plan']['components'])->map(fn ($c) => [
                'key' => $c['key'], 'component_type' => $c['component_type'], 'name' => $c['component_name'],
                'max_mark' => (float) $c['max_mark'], 'mark' => $marks->get($c['key'])['mark'] ?? null,
            ])->all()];
        $data['revision'] = hash('sha256', json_encode([$data, $r['plan'], $r['snapshot']['revision'] ?? null,
            $r['course']->getAttributes(), $r['offering']->getAttributes(), $r['student']->getAttributes()], JSON_THROW_ON_ERROR));
        return $data;
    }

    public function save(User $actor, Student $student, Course $course, array $input): array
    {
        $this->access->authorize($actor, $student);
        if (($input['confirmed'] ?? false) !== true || ($input['acknowledged'] ?? false) !== true || trim($input['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => 'يلزم التأكيد والإقرار وسبب الإدخال.']);
        }
        return DB::transaction(function () use ($actor, $student, $course, $input) {
            // Canonical student -> offering -> approvals -> registration -> components order.
            // Course lock serializes absent-context creation across students without a new lock table.
            $student = Student::whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $course = Course::whereKey($course->getKey())->lockForUpdate()->firstOrFail();
            $offerings = CourseOffering::where('course_id', $course->getKey())->where('academic_year_id', $input['academic_year_id'])
                ->where('semester_id', $input['semester_id'])->orderBy('course_offering_id')->lockForUpdate()->get();
            $selection = $this->resolve($actor, $student, $course, $input);
            $selectedIds = $selection['offering']->exists ? [$selection['offering']->getKey()] : [];
            GradePartApproval::whereIn('course_offering_id', $selectedIds)->orderBy('course_offering_id')->orderBy('component_type')->lockForUpdate()->get();
            SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable($selectedIds);
            StudentCourseRegistration::where('student_id', $student->getKey())->whereIn('course_offering_id', $offerings->modelKeys())
                ->orderBy('student_course_registration_id')->lockForUpdate()->get();
            GradeComponent::whereIn('course_offering_id', $selectedIds)->orderBy('grade_component_id')->lockForUpdate()->get();
            $this->grades->lockDefaultGradingPolicy();
            $r = $this->resolve($actor, $student, $course, $input);
            $preview = $this->describe($r);
            if (!hash_equals($preview['revision'], $input['revision'])) $this->fail('تغير السياق؛ أعد معاينته قبل تأكيد المسودة.', 'manual_grade_entry_stale');
            $offering = $r['offering'];
            if (!$offering->exists) $offering = $this->contexts->createOffering($r['context']);
            $registration = $r['registration'] ?? $this->registrations->prepareManualGradeRegistration($student, $offering, $actor,
                ['confirmed' => true, 'reason' => $input['reason'], 'course_id' => $course->getKey(),
                    'academic_year_id' => $input['academic_year_id'], 'semester_id' => $input['semester_id']]);
            $ids = [];
            foreach ($r['plan']['components'] as $component) {
                $key = $component['key']; unset($component['key']);
                $ids[$key] = $r['plan']['create'] ? GradeComponent::create(['course_offering_id' => $offering->getKey()] + $component)->getKey() : (int) $key;
            }
            $seen = []; $marks = [];
            foreach ($input['components'] as $mark) {
                $key = (string) $mark['key'];
                if (!isset($ids[$key]) || isset($seen[$key])) throw ValidationException::withMessages(['components' => 'مكوّن غير صالح أو مكرر.']);
                $seen[$key] = true; $marks[] = ['grade_component_id' => $ids[$key], 'mark' => $mark['mark']];
            }
            $current = $this->workflow->manualRegistration($registration->fresh());
            $saved = $this->workflow->saveManualMarks($student, $registration, [
                'revision' => $current['revision'], 'acknowledged' => true, 'components' => $marks,
                'correction_confirmed' => $input['correction_confirmed'] ?? false, 'correction_reason' => $input['reason'],
            ], $actor);
            UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'grades', 'action_code' => 'manual_grade_entry.context',
                'description' => json_encode(['student_id' => $student->getKey(), 'course_id' => $course->getKey(),
                    'course_offering_id' => $offering->getKey(), 'registration_id' => $registration->getKey(),
                    'academic_year_id' => $offering->academic_year_id, 'semester_id' => $offering->semester_id,
                    'created' => [$preview['create_offering'], $preview['create_registration'], $preview['create_components']],
                    'waived' => ExamManualGradeEntryAccess::RECORDING_EXEMPTIONS, 'reason' => trim($input['reason'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return $saved;
        }); // No automatic retry of a grade write. Any error rolls back the entire preparation and save.
    }

    private function fail(string $message, string $code): never
    {
        throw new GradeException($message, status: 409, errorCode: $code);
    }
}
