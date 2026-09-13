<?php

namespace App\Services;

use App\Models\{AcademicProgram, AcademicRequirementGroup, Course, ProgramCourse, ProgramCourseRequirementGroup, User, UserActivityLog};
use App\Support\ScientificCourseAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit, atomic distribution to existing curricula. Never provisions requirement budgets. */
final class ScientificCourseDistribution
{
    public function __construct(private ScientificCourseAccess $access, private AcademicCatalogTransaction $transaction) {}

    public function preview(User $actor, array $input): array
    {
        $this->access->authorize($actor, true);
        return $this->transaction->snapshot(fn () => $this->plan($actor, $input) + ['revision' => $this->transaction->revision()]);
    }

    public function plan(User $actor, array $input, bool $lock = false): array
    {
        $this->access->authorize($actor, true);
        $rules = ['scope' => 'required|in:university,college,department', 'college_id' => 'sometimes|integer|min:1',
            'department_id' => 'sometimes|integer|min:1', 'course_type' => 'required|in:mandatory,elective'];
        if (array_diff(array_keys($input), array_keys($rules))) $this->invalid('حقول نطاق غير مسموحة.');
        $v = Validator::make($input, $rules)->validate();
        if ($v['scope'] === 'university') {
            abort_unless($this->access->university($actor), 403);
            if (isset($v['college_id']) || isset($v['department_id'])) $this->invalid('نطاق الجامعة لا يقبل كلية أو قسمًا.');
        } else {
            if (!isset($v['college_id'])) $this->invalid('حدد الكلية.');
            abort_unless($this->access->departments($actor)->where('college_id', $v['college_id'])->exists(), 403);
            if ($v['scope'] === 'department') {
                if (!isset($v['department_id'])) $this->invalid('حدد القسم.');
                $department = $this->access->departments($actor)->findOrFail($v['department_id']);
                if ((int) $department->college_id !== (int) $v['college_id']) $this->invalid('القسم لا يتبع الكلية المحددة.');
            } elseif (isset($v['department_id'])) $this->invalid('نطاق الكلية يشمل جميع أقسامها.');
        }
        $query = AcademicProgram::query()
            ->when(isset($v['college_id']), fn ($q) => $q->whereHas('department', fn ($d) => $d->where('college_id', $v['college_id'])))
            ->when(isset($v['department_id']), fn ($q) => $q->where('department_id', $v['department_id']));
        // A partial DataScope must never masquerade as ALL programs in the chosen scope.
        abort_if((clone $query)->whereNotIn('academic_program_id', $this->access->programs($actor)->select('academic_program_id'))->exists(), 403);
        $programs = $query->with('department.college')->orderBy('academic_program_id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $ids = $programs->modelKeys();
        $used = collect();
        // Fixed number of reference queries; no per-program queries or lazy loading.
        foreach (AcademicCatalogHistory::PROGRAM_REFERENCES as $table => [$key, $foreign]) {
            $used = $used->merge(DB::table($table)->whereIn($foreign, $ids)->distinct()->pluck($foreign));
        }
        $used = array_fill_keys($used->all(), true);
        $groups = AcademicRequirementGroup::whereIn('academic_program_id', $ids)->where('is_active', true)
            ->where('requirement_scope', $v['scope'])->where('requirement_type', $v['course_type'])
            ->orderBy('requirement_group_id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->groupBy('academic_program_id');
        $targets = $programs->map(function ($p) use ($used, $groups) {
            $reason = isset($used[$p->getKey()]) ? 'البرنامج مرتبط بتاريخ أكاديمي؛ لا يمكن تغيير مواده.'
                : (!$p->is_active || !$p->department?->is_active || !$p->department?->college?->is_active ? 'البرنامج أو قسمه أو كليته غير فعّال.'
                    : (($groups[$p->getKey()] ?? collect())->count() !== 1 ? 'يجب إعداد مجموعة المتطلبات المطابقة وميزانيتها للبرنامج أولًا.' : null));
            return ['academic_program_id' => $p->getKey(), 'program_name' => $p->program_name,
                'department_name' => $p->department?->department_name, 'college_name' => $p->department?->college?->college_name,
                'requirement_group_id' => $reason ? null : $groups[$p->getKey()]->sole()->getKey(), 'block_reason' => $reason];
        });
        return ['scope' => $v, 'targets' => $targets, 'program_count' => $targets->count(),
            'can_apply' => $targets->isNotEmpty() && !$targets->contains(fn ($p) => $p['block_reason'] !== null)];
    }

    public function apply(User $actor, Course $course, array $plan, array $advisory): void
    {
        // Called only for a newly created Course under the outer catalog writer lock/transaction.
        if (DB::transactionLevel() < 1 || !$plan['can_apply']) $this->invalid('تعذر ربط جميع البرامج؛ راجع معاينة النطاق. لم يُحفظ أي تغيير.');
        foreach ($plan['targets'] as $target) {
            $pc = ProgramCourse::create(['course_id' => $course->getKey(), 'academic_program_id' => $target['academic_program_id'],
                'course_type' => $plan['scope']['course_type'], 'is_active' => true, ...$advisory]);
            ProgramCourseRequirementGroup::create(['program_course_id' => $pc->getKey(), 'requirement_group_id' => $target['requirement_group_id']]);
        }
        UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'courses', 'action_code' => 'scientific_catalog.course.distribute',
            'description' => json_encode(['course_id' => $course->getKey(), 'scope' => $plan['scope'],
                'academic_program_ids' => $plan['targets']->pluck('academic_program_id')->all()], JSON_THROW_ON_ERROR)]);
    }

    private function invalid(string $message): never { throw ValidationException::withMessages(['distribution' => $message]); }
}
