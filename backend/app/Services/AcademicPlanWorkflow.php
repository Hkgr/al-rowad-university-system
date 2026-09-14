<?php

namespace App\Services;

use App\Exceptions\{AcademicPlanException, AcademicRequirementConfigurationException};
use App\Models\{AcademicPlanVersion, AcademicProgram, AcademicRequirementGroup, ProgramCourse, ProgramCourseRequirementGroup, Student, User, UserActivityLog};
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Every public write uses the catalog writer lock before program/version/assignment locks. */
final class AcademicPlanWorkflow
{
    public function __construct(private AcademicCatalogTransaction $transaction, private ScientificProgramAccess $access) {}

    public function begin(User $actor, int $programId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->write($actor, $programId, ScientificProgramAccess::PLANS, $v['revision'], function ($program) use ($actor) {
            if ($program->plan_state !== 'legacy') $this->fail('academic_plan_transition_invalid', 'تهيئة هذا البرنامج بدأت بالفعل؛ حدّث العرض.');
            $program->forceFill(['plan_state' => 'preparing'])->save();
            $this->event($actor, $program, null, 'initialization_started', []);
            return $this->state($program);
        });
    }

    public function previewTransition(User $actor, int $programId): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::PLANS);
        AcademicPlanContext::assertReady();
        return $this->transaction->snapshot(function () use ($actor, $programId) {
            $program = $this->access->programs($actor)->findOrFail($programId);
            $this->assertCanFix($program);
            return $this->transitionProjection($program);
        });
    }

    public function fixTransition(User $actor, int $programId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->write($actor, $programId, ScientificProgramAccess::PLANS, $v['revision'], function ($program) use ($actor) {
            $this->assertCanFix($program);
            // All membership/student writers advance the epoch. This is a current read after its lock.
            $preview = $this->transitionProjection($program);
            if (!$preview['can_fix']) $this->fail('academic_plan_transition_invalid', 'توجد علاقات لا يمكن تثبيتها بأمان؛ راجع المعاينة.');
            $students = Student::withTrashed()->where('academic_program_id', $program->getKey())->orderBy('student_id')->lockForUpdate()->get();
            $version = AcademicPlanVersion::create(['academic_program_id' => $program->getKey(), 'version_number' => 1,
                'label' => 'مرجع انتقالي مثبت', 'status' => 'transitional', 'calculation_policy' => 'legacy',
                'total_credit_hours' => $program->total_credit_hours, 'created_by_user_id' => $actor->getKey(), 'fixed_at' => now()]);
            // Existing IDs and classifications are preserved, including inactive rows and configuration gaps.
            foreach (['academic_requirement_groups', 'program_courses'] as $table) {
                DB::table($table)->where('academic_program_id', $program->getKey())->whereNull('academic_plan_version_id')
                    ->update(['academic_plan_version_id' => $version->getKey()]);
            }
            foreach ($students as $student) $this->assign($actor, $student, $version, 'transition_fixed');
            AcademicPlanRecords::pinTransition((int) $program->getKey(), (int) $version->getKey());
            $this->event($actor, $program, $version, 'transition_fixed', ['student_count' => $students->count(), 'reference_kind' => 'current_state_not_historical_approval']);
            return $this->state($program);
        });
    }

    public function copy(User $actor, int $programId, int $sourceId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'label' => 'required|string|max:150']);
        return $this->write($actor, $programId, ScientificProgramAccess::PLANS, $v['revision'], function ($program) use ($actor, $sourceId, $v) {
            $source = AcademicPlanVersion::where('academic_program_id', $program->getKey())->lockForUpdate()->findOrFail($sourceId);
            if (!in_array($source->status, ['approved', 'transitional'], true)) $this->fail('academic_plan_transition_invalid', 'اختر خطة ثابتة لإنشاء نسخة للتعديل.');
            $number = 1 + (int) AcademicPlanVersion::where('academic_program_id', $program->getKey())->max('version_number');
            $version = AcademicPlanVersion::create(['academic_program_id' => $program->getKey(), 'version_number' => $number,
                'label' => trim($v['label']), 'status' => 'draft', 'calculation_policy' => 'explicit_zero_v1',
                'source_version_id' => $source->getKey(), 'total_credit_hours' => $source->total_credit_hours, 'created_by_user_id' => $actor->getKey()]);
            $groups = [];
            foreach (AcademicRequirementGroup::where('academic_plan_version_id', $sourceId)->orderBy('requirement_group_id')->get() as $old) {
                $copy = $old->replicate(['plan_scope_key']);
                $copy->academic_plan_version_id = $version->getKey();
                $copy->group_code = 'PLAN-'.$version->getKey().'-'.$old->getKey();
                $copy->save(); $groups[$old->getKey()] = $copy->getKey();
            }
            foreach (ProgramCourse::where('academic_plan_version_id', $sourceId)->with('requirementMapping')->orderBy('program_course_id')->get() as $old) {
                $copy = $old->replicate(['plan_scope_key']); $copy->academic_plan_version_id = $version->getKey(); $copy->save();
                if ($old->requirementMapping !== null) {
                    $group = $groups[$old->requirementMapping->requirement_group_id] ?? null;
                    if ($group === null) $this->fail('academic_plan_transition_invalid', 'مجموعة المادة لا تتبع الخطة المصدر.');
                    ProgramCourseRequirementGroup::create(['program_course_id' => $copy->getKey(), 'requirement_group_id' => $group]);
                }
            }
            $this->event($actor, $program, $version, 'draft_created', ['source_version_id' => $sourceId]);
            return $this->version($actor, (int) $program->getKey(), (int) $version->getKey());
        });
    }

    public function saveRequirements(User $actor, int $programId, int $versionId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'total_credit_hours' => 'present|nullable|integer|min:1|max:2147483647',
            'groups' => 'required|array|size:6', 'groups.*' => 'array:requirement_scope,requirement_type,required_credit_hours',
            'groups.*.requirement_scope' => 'required|in:university,college,department', 'groups.*.requirement_type' => 'required|in:mandatory,elective',
            'groups.*.required_credit_hours' => 'present|nullable|integer|min:0|max:2147483647']);
        return $this->write($actor, $programId, ScientificProgramAccess::PLANS, $v['revision'], function ($program) use ($actor, $versionId, $v) {
            $version = $this->editable($program, $versionId);
            $seen = [];
            foreach ($v['groups'] as $row) {
                $key = $row['requirement_scope'].':'.$row['requirement_type'];
                if (isset($seen[$key])) throw ValidationException::withMessages(['groups' => 'لا يجوز تكرار التصنيف.']);
                $seen[$key] = true;
                $groups = AcademicRequirementGroup::where('academic_plan_version_id', $versionId)->where('requirement_scope', $row['requirement_scope'])
                    ->where('requirement_type', $row['requirement_type'])->lockForUpdate()->get();
                if ($groups->count() > 1) $this->fail('academic_plan_group_ambiguous', 'يوجد أكثر من تعريف لهذا التصنيف؛ راجع الخطة.');
                $group = $groups->first() ?? new AcademicRequirementGroup(['academic_program_id' => $program->getKey(),
                    'group_code' => 'PLAN-'.$versionId.'-'.$row['requirement_scope'].'-'.$row['requirement_type'], 'group_name' => $this->groupName($row)]);
                $group->forceFill($row + ['academic_plan_version_id' => $versionId, 'is_active' => true])->save();
            }
            $version->update(['total_credit_hours' => $v['total_credit_hours']]);
            $this->event($actor, $program, $version, 'requirements_saved', []);
            return $this->version($actor, (int) $program->getKey(), $versionId);
        });
    }

    public function approve(User $actor, int $programId, int $versionId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->write($actor, $programId, ScientificProgramAccess::APPROVE, $v['revision'], function ($program) use ($actor, $versionId, $programId) {
            $version = $this->editable($program, $versionId);
            $configuration = $this->configuration($version);
            if (!$configuration['complete']) throw ValidationException::withMessages(['plan' => $configuration['issues']]);
            $version->update(['status' => 'approved', 'approved_by_user_id' => $actor->getKey(), 'approved_at' => now(), 'fixed_at' => now()]);
            $this->event($actor, $program, $version, 'approved', []);
            return $this->version($actor, $programId, $versionId);
        });
    }

    public function saveMembership(User $actor, int $programId, int $versionId, int $courseId, array $input, bool $delete = false): array
    {
        $v = $this->validate($input, $delete ? ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted'] : [
            'revision' => 'required|string|regex:/^[0-9]+$/', 'requirement_scope' => 'required|in:university,college,department',
            'course_type' => 'required|in:mandatory,elective', 'academic_level_id' => 'present|nullable|integer|exists:academic_levels,academic_level_id',
            'recommended_semester_id' => 'present|nullable|integer|exists:semesters,semester_id', 'is_active' => 'required|boolean']);
        return $this->write($actor, $programId, ScientificProgramAccess::PLANS, $v['revision'], function ($program) use ($actor, $versionId, $courseId, $v, $delete) {
            $version = $this->editable($program, $versionId);
            $course = app(\App\Support\ScientificCourseAccess::class)->courses($actor)->findOrFail($courseId);
            $rows = ProgramCourse::where('academic_plan_version_id', $versionId)->where('course_id', $courseId)->lockForUpdate()->get();
            if ($rows->count() > 1) $this->fail('academic_plan_membership_ambiguous', 'ارتباط المادة غير محدد بأمان.');
            $pc = $rows->first();
            if ($delete) {
                abort_unless($pc, 404); $pc->requirementMapping()->delete(); $pc->delete();
            } else {
                if (!$course->is_active) throw ValidationException::withMessages(['course' => 'المادة غير نشطة.']);
                $groups = AcademicRequirementGroup::where('academic_plan_version_id', $versionId)->where('requirement_scope', $v['requirement_scope'])
                    ->where('requirement_type', $v['course_type'])->where('is_active', true)->lockForUpdate()->get();
                if ($groups->count() !== 1) $this->fail('academic_plan_group_unavailable', 'أعد متطلبات البرنامج لهذا التصنيف أولًا؛ احتُفظ باختيار المادة.');
                $pc ??= new ProgramCourse(['academic_program_id' => $program->getKey(), 'course_id' => $courseId]);
                $pc->forceFill(collect($v)->only(['course_type', 'academic_level_id', 'recommended_semester_id', 'is_active'])->all()
                    + ['academic_plan_version_id' => $versionId])->save();
                ProgramCourseRequirementGroup::updateOrCreate(['program_course_id' => $pc->getKey()], ['requirement_group_id' => $groups->sole()->getKey()]);
            }
            $this->event($actor, $program, $version, $delete ? 'course_removed' : 'course_saved', ['course_id' => $courseId]);
            return $this->version($actor, (int) $program->getKey(), $versionId);
        });
    }

    public function previewTransfer(User $actor, int $programId, int $versionId, array $input): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::ASSIGN); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['student_ids' => 'required|array|min:1|max:50', 'student_ids.*' => 'required|integer|min:1|distinct']);
        return $this->transaction->snapshot(function () use ($actor, $programId, $versionId, $v) {
            $this->access->programs($actor)->findOrFail($programId);
            return $this->transferProjection($programId, $versionId, $v['student_ids']);
        });
    }

    public function transfer(User $actor, int $programId, int $versionId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted', 'reason' => 'required|string|max:1000',
            'student_ids' => 'required|array|min:1|max:50', 'student_ids.*' => 'required|integer|min:1|distinct']);
        if (trim($v['reason']) === '') throw ValidationException::withMessages(['reason' => 'وضح سبب النقل.']);
        return $this->write($actor, $programId, ScientificProgramAccess::ASSIGN, $v['revision'], function ($program) use ($actor, $versionId, $v) {
            $students = Student::where('academic_program_id', $program->getKey())->whereIn('student_id', $v['student_ids'])->orderBy('student_id')->lockForUpdate()->get();
            if ($students->count() !== count($v['student_ids'])) abort(404);
            $version = AcademicPlanVersion::where('academic_program_id', $program->getKey())->lockForUpdate()->findOrFail($versionId);
            $preview = $this->transferProjection((int) $program->getKey(), $versionId, $v['student_ids']);
            if (!$preview['can_transfer']) $this->fail('academic_plan_transfer_blocked', 'توجد عمليات أو قرارات مانعة؛ راجع معاينة النقل.');
            foreach ($students as $student) {
                $previous = AcademicPlanContext::forStudent($student)->versionId;
                DB::table('student_academic_plan_assignments')->where('student_id', $student->getKey())->where('current_slot', 1)
                    ->update(['current_slot' => null, 'ended_at' => now()]);
                $this->assign($actor, $student, $version, trim($v['reason']));
                $effect = collect($preview['students'])->firstWhere('student_id', $student->getKey());
                $this->event($actor, $program, $version, 'student_transferred', ['student_id' => $student->getKey(),
                    'previous_version_id' => $previous, 'effect' => $effect, 'reason' => trim($v['reason'])]);
            }
            return ['transferred_count' => $students->count(), 'revision' => $this->transaction->revision()];
        });
    }

    private function transferProjection(int $programId, int $versionId, array $studentIds): array
    {
        $target = AcademicPlanVersion::where('academic_program_id', $programId)->where('status', 'approved')->whereNotNull('fixed_at')->findOrFail($versionId);
        $students = Student::where('academic_program_id', $programId)->whereIn('student_id', $studentIds)->orderBy('student_id')->get();
        if ($students->count() !== count($studentIds)) abort(404);
        $rows = [];
        foreach ($students as $student) {
            $context = AcademicPlanContext::forStudent($student);
            $blockers = $this->transferBlockers((int) $student->getKey());
            if ($context->versionId === null || $context->versionId === $versionId) $blockers[] = 'اختر خطة مستهدفة مختلفة بعد تثبيت الخطة الحالية.';
            $currentRequirements = app(AcademicRequirementService::class)->forPlanContext($context);
            $targetRequirements = app(AcademicRequirementService::class)->forPlanContext(AcademicPlanContext::forVersion($target));
            $before = $this->transferProgress($currentRequirements, $student, $context);
            $after = $this->transferProgress($targetRequirements, $student, AcademicPlanContext::forVersion($target));
            if (!$before['available'] || !$after['available']) $blockers[] = 'تعذر حساب أثر النقل وفق إعداد المتطلبات الحالي.';
            $rows[] = ['student_id' => $student->getKey(), 'from_version_id' => $context->versionId, 'to_version_id' => $versionId,
                'before' => $before, 'after' => $after, 'blockers' => $blockers];
        }
        return ['students' => $rows, 'can_transfer' => collect($rows)->every(fn ($r) => $r['blockers'] === []), 'revision' => $this->transaction->revision()];
    }

    private function transferProgress(AcademicRequirementService $requirements, Student $student, AcademicPlanContext $context): array
    {
        try {
            $requirements->assertProgramGraduationConfiguration((int) $student->academic_program_id);
            $progress = $requirements->getStudentRequirementProgress($student);
            $eligibility = (new GraduationEligibilityService($requirements))->evaluatePlanProgress($student, $context, $progress);
            return ['available' => true, 'required_hours' => $progress['total_required_hours'], 'earned_hours' => $progress['earned_curriculum_hours'],
                'counted_hours' => $eligibility['graduation_counted_hours'], 'remaining_hours' => $eligibility['remaining_graduation_hours'],
                'counted_courses' => collect($progress['groups'])->flatMap(fn ($g) => collect($g['passed_courses'])->map(fn ($c) => [
                    'course_id' => $c['course_id'], 'course_label' => ($c['course_name'] ?? 'مادة').' ('.($c['course_code'] ?? 'غير محدد').')',
                    'credit_hours' => $c['credit_hours'], 'requirement_scope' => $g['requirement_scope'], 'requirement_type' => $g['requirement_type']]))->values()->all(),
                'outside_courses' => collect($progress['outside_current_curriculum'])->map(fn ($c) => collect($c)->only(['course_id', 'credit_hours', 'result_status'])->all()
                    + ['course_label' => ($c['course_name'] ?? 'مادة').' ('.($c['course_code'] ?? 'غير محدد').')'])->unique('course_id')->values()->all()];
        } catch (AcademicRequirementConfigurationException $e) {
            return ['available' => false, 'reason' => 'requirement_configuration_invalid'];
        }
    }

    private function transferBlockers(int $studentId): array
    {
        $blocked = [];
        if (\App\Models\StudentCourseRegistration::where('student_id', $studentId)->current()->exists()) $blocked[] = 'توجد تسجيلات دراسية جارية.';
        foreach (['student_registration_requests', 'student_registration_modification_requests', 'student_registration_replacement_requests'] as $table) {
            if (DB::table($table)->where('student_id', $studentId)->whereIn('status', ['draft', 'submitted', 'returned'])->exists()) $blocked[] = 'توجد طلبات تسجيل غير محسومة.';
        }
        foreach (['student_progression_decisions', 'student_graduation_decisions'] as $table) {
            if (DB::table($table)->where('student_id', $studentId)->where('current_slot', 1)->whereIn('status', ['submitted', 'returned'])->exists()) $blocked[] = 'توجد قرارات أكاديمية غير محسومة.';
        }
        if (DB::table('student_graduation_decisions')->where('student_id', $studentId)->where('status', 'approved')->whereNotNull('materialized_at')->exists()) $blocked[] = 'يوجد قرار تخرج نهائي.';
        if (DB::table('student_registration_withdrawal_requests')->where('student_id', $studentId)->whereIn('status', ['submitted', 'returned'])->exists()) $blocked[] = 'يوجد طلب انسحاب غير محسوم.';
        if (\App\Models\GradeAppeal::where('student_id', $studentId)
            ->whereDoesntHave('appealStatus', fn ($q) => $q->whereIn('status_code', ['closed', 'rejected']))->exists()) $blocked[] = 'يوجد اعتراض علامات لم يغلق بعد.';
        if (\App\Models\SupplementaryExamRegistration::where('student_id', $studentId)->where('status', 'registered')
            ->whereDoesntHave('materialization')->exists()) $blocked[] = 'توجد عملية تكميلية لم تكتمل ماديتها بعد.';
        return array_values(array_unique($blocked));
    }

    public function setDefault(User $actor, int $programId, int $versionId, array $input): array
    {
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->write($actor, $programId, ScientificProgramAccess::ASSIGN, $v['revision'], function ($program) use ($actor, $versionId) {
            $version = AcademicPlanVersion::where('academic_program_id', $program->getKey())->lockForUpdate()->findOrFail($versionId);
            if ($version->status !== 'approved' || !$version->fixed_at) $this->fail('academic_plan_not_approved', 'تعيين الطلاب الجدد يتطلب خطة معتمدة صراحة.');
            $unassigned = DB::table('students as s')->where('s.academic_program_id', $program->getKey())->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('student_academic_plan_assignments as a')->whereColumn('a.student_id', 's.student_id')->whereColumn('a.academic_program_id', 's.academic_program_id')->where('a.current_slot', 1))->exists();
            if ($unassigned) $this->fail('academic_plan_initialization_incomplete', 'توجد إسنادات طلاب غير مكتملة؛ لا يمكن استئناف القبول.');
            $previous = $program->default_academic_plan_version_id;
            $program->forceFill(['default_academic_plan_version_id' => $versionId, 'plan_state' => 'ready'])->save();
            $this->event($actor, $program, $version, 'default_selected', ['previous_version_id' => $previous]);
            return $this->state($program);
        });
    }

    public function version(User $actor, int $programId, int $versionId): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        return $this->transaction->snapshot(function () use ($actor, $programId, $versionId) {
            $this->access->programs($actor)->findOrFail($programId);
            $version = AcademicPlanVersion::where('academic_program_id', $programId)->findOrFail($versionId);
            $courses = ProgramCourse::where('academic_plan_version_id', $versionId)->with(['course', 'requirementMapping.requirementGroup', 'academicLevel', 'recommendedSemester'])
                ->orderBy('course_id')->get();
            $groups = AcademicRequirementGroup::where('academic_plan_version_id', $versionId)->orderBy('requirement_scope')->orderBy('requirement_type')->get();
            try {
                $pools = ['available' => true, 'groups' => app(AcademicRequirementService::class)->forPlanContext(AcademicPlanContext::forVersion($version))->getProgramRequirements($programId)];
            } catch (AcademicRequirementConfigurationException $e) {
                $pools = ['available' => false, 'groups' => [], 'reason' => 'requirement_configuration_invalid'];
            }
            return ['version' => $version, 'groups' => $groups, 'courses' => $courses, 'configuration' => $this->configuration($version),
                'requirement_pools' => $pools,
                'revision' => $this->transaction->revision(), 'capabilities' => $this->access->capabilities($actor)];
        });
    }

    public function configuration(AcademicPlanVersion $version): array
    {
        $groups = AcademicRequirementGroup::where('academic_plan_version_id', $version->getKey())->where('is_active', true)->get();
        $issues = [];
        if ($groups->count() !== 6 || $groups->map(fn ($g) => $g->requirement_scope.':'.$g->requirement_type)->unique()->count() !== 6) $issues[] = 'يلزم تعريف التصنيفات الستة دون تكرار.';
        if ($version->total_credit_hours === null) $issues[] = 'حدد إجمالي ساعات التخرج.';
        if ($groups->contains(fn ($g) => $g->required_credit_hours === null)) $issues[] = 'توجد ساعات مطلوبة لم تُحدد بعد.';
        if (ProgramCourse::where('academic_plan_version_id', $version->getKey())->where('is_active', true)
            ->whereDoesntHave('course', fn ($q) => $q->where('is_active', true))->exists()) $issues[] = 'توجد مواد مفقودة أو غير نشطة ضمن الخطة.';
        try {
            app(AcademicRequirementService::class)->forPlanContext(AcademicPlanContext::forVersion($version))->assertProgramGraduationConfiguration((int) $version->academic_program_id);
        } catch (AcademicRequirementConfigurationException $e) {
            $issues[] = 'توزيع المتطلبات أو المواد لا يطابق قواعد التخرج الرسمية.';
        }
        return ['complete' => $issues === [], 'issues' => $issues];
    }

    private function transitionProjection(AcademicProgram $program): array
    {
        $id = (int) $program->getKey();
        $foreignMapping = DB::table('program_courses as pc')->join('program_course_requirement_groups as m', 'm.program_course_id', '=', 'pc.program_course_id')
            ->leftJoin('academic_requirement_groups as g', 'g.requirement_group_id', '=', 'm.requirement_group_id')->where('pc.academic_program_id', $id)
            ->where(fn ($q) => $q->whereNull('g.requirement_group_id')->orWhere('g.academic_program_id', '<>', $id))->exists();
        $ambiguousMembership = DB::table('program_courses')->where('academic_program_id', $id)->where('is_active', true)
            ->groupBy('course_id')->havingRaw('COUNT(*) > 1')->exists();
        $groups = AcademicRequirementGroup::where('academic_program_id', $id)->orderBy('requirement_group_id')->get([
            'requirement_group_id', 'requirement_scope', 'requirement_type', 'required_credit_hours', 'is_active']);
        $references = [];
        // Fixed set of bulk counts; identifiers are internal constants, never request data.
        foreach (['student_course_registrations', 'student_registration_requests', 'student_registration_modification_requests',
            'student_registration_replacement_requests', 'student_progression_decisions', 'student_graduation_decisions'] as $table) {
            $references[$table] = DB::table($table)->whereIn('student_id', DB::table('students')->where('academic_program_id', $id)->select('student_id'))->count();
        }
        $blockers = [];
        if ($foreignMapping) $blockers[] = 'توجد روابط مجموعات خارج البرنامج.';
        if ($ambiguousMembership) $blockers[] = 'توجد عضويات فعالة مكررة للمادة نفسها؛ لا يمكن اختيار مرجع عشوائي.';
        return ['academic_program_id' => $id, 'reference_kind' => 'current_state_not_historical_approval', 'revision' => $this->transaction->revision(),
            'student_count' => Student::withTrashed()->where('academic_program_id', $id)->count(),
            'course_count' => ProgramCourse::where('academic_program_id', $id)->count(),
            'group_count' => $groups->count(), 'total_credit_hours' => $program->total_credit_hours, 'groups' => $groups,
            'operation_reference_counts' => $references,
            'can_fix' => $blockers === [], 'blockers' => $blockers];
    }

    private function assertCanFix(AcademicProgram $program): void
    {
        if ($program->plan_state !== 'preparing' || AcademicPlanVersion::where('academic_program_id', $program->getKey())->exists()) {
            $this->fail('academic_plan_transition_invalid', 'ابدأ التهيئة أولًا؛ ولا يمكن إعادة تثبيت مرجع سبق إنشاؤه.');
        }
    }

    private function editable(AcademicProgram $program, int $versionId): AcademicPlanVersion
    {
        $version = AcademicPlanVersion::where('academic_program_id', $program->getKey())->lockForUpdate()->findOrFail($versionId);
        if ($version->status !== 'draft' || $version->fixed_at !== null) $this->fail('academic_plan_locked', 'الخطة ثابتة؛ أنشئ نسخة للتعديل.');
        return $version;
    }

    private function assign(User $actor, Student $student, AcademicPlanVersion $version, string $reason): void
    {
        DB::table('student_academic_plan_assignments')->insert(['student_id' => $student->getKey(), 'academic_program_id' => $version->academic_program_id,
            'academic_plan_version_id' => $version->getKey(), 'current_slot' => 1, 'assigned_by_user_id' => $actor->getKey(), 'reason' => $reason, 'assigned_at' => now()]);
    }

    private function state(AcademicProgram $program): array
    {
        return ['program' => $program->fresh(), 'versions' => AcademicPlanVersion::where('academic_program_id', $program->getKey())->orderBy('version_number')->get(), 'revision' => $this->transaction->revision()];
    }

    private function write(User $actor, int $programId, string $permission, string $revision, callable $work): array
    {
        $this->access->authorize($actor, $permission); AcademicPlanContext::assertReady();
        return $this->transaction->run(function () use ($actor, $programId, $permission, $work) {
            $this->access->authorize($actor, $permission);
            return $work($this->access->programs($actor)->lockForUpdate()->findOrFail($programId));
        }, $revision);
    }

    private function event(User $actor, AcademicProgram $program, ?AcademicPlanVersion $version, string $action, array $context): void
    {
        $safe = ['academic_program_id' => $program->getKey(), 'academic_plan_version_id' => $version?->getKey()] + $context;
        DB::table('academic_plan_events')->insert(['academic_program_id' => $program->getKey(), 'academic_plan_version_id' => $version?->getKey(),
            'action' => $action, 'actor_user_id' => $actor->getKey(), 'context' => json_encode($safe, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => 'academic_plan.'.$action,
            'description' => json_encode($safe, JSON_THROW_ON_ERROR)]);
    }

    private function groupName(array $row): string
    {
        return ['university' => 'متطلبات الجامعة', 'college' => 'متطلبات الكلية', 'department' => 'متطلبات القسم'][$row['requirement_scope']]
            .' '.($row['requirement_type'] === 'mandatory' ? 'الإجبارية' : 'الاختيارية');
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_filter(array_keys($rules), fn ($k) => !str_contains($k, '.')));
        if ($unknown) throw ValidationException::withMessages(['input' => 'توجد حقول غير مسموحة.']);
        return Validator::make($input, $rules)->validate();
    }

    private function fail(string $code, string $message): never { throw AcademicPlanException::conflict($code, $message); }
}
