<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\SupplementaryExamPolicy;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Support\Facades\DB;

class SupplementaryExamRegistrationWindowService
{
    public function __construct(private readonly SupplementaryExamEligibilityService $eligibility,private readonly DataScopeService $scope){}
    public function open(User $actor,int $id): SupplementaryExamPeriod
    {
        $this->assertCanGovernPeriod($actor);$this->ready();$out=DB::transaction(function()use($actor,$id){$p=SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($id);if($p->isLegacy())return ['e'=>['الدورة قديمة.','supplementary_exam_registration_locked',409]];if($p->status==='registration_open')return ['e'=>['التسجيل مفتوح مسبقاً.','supplementary_exam_registration_already_open',409]];if($p->status==='registration_closed')return ['e'=>['التسجيل مغلق نهائياً.','supplementary_exam_registration_already_closed',409]];if($p->status!=='announced')return ['e'=>['حالة الدورة لا تسمح بفتح التسجيل.','supplementary_exam_registration_locked',409]];$offerings=$p->supplementaryExamOfferings()->lockForUpdate()->get();if(!$offerings->contains(fn($o)=>$o->status==='open'))return ['e'=>['لا توجد مقررات مفتوحة.','supplementary_exam_registration_locked',409]];$p->forceFill(['status'=>'registration_open'])->save();$this->periodEvent($p,$actor,'registration_opened','announced','registration_open');return ['p'=>$p->fresh()];},3);if(isset($out['e']))$this->fail(...$out['e']);return $out['p'];
    }
    public function close(User $actor,int $id): SupplementaryExamPeriod
    {
        $this->assertCanGovernPeriod($actor);
        $this->ready();

        $out = DB::transaction(function () use ($actor, $id): array {
            $period = SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($id);
            if ($period->status === 'registration_closed') {
                return ['e' => ['التسجيل مغلق مسبقاً.', 'supplementary_exam_registration_already_closed', 409]];
            }
            if ($period->status !== 'registration_open') {
                return ['e' => ['نافذة التسجيل غير مفتوحة.', 'supplementary_exam_registration_window_not_open', 409]];
            }

            $rows = SupplementaryExamRegistration::query()
                ->where('status', 'registered')
                ->where('current_slot', 1)
                ->whereHas('offering', fn ($query) => $query->where('supplementary_exam_period_id', $id))
                ->orderBy('student_course_registration_id')
                ->orderBy('supplementary_exam_registration_id')
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return ['e' => [
                    'لا يمكن تثبيت قائمة تسجيل فارغة. سجّل طالباً مؤهلاً واحداً على الأقل قبل إغلاق التسجيل.',
                    'supplementary_exam_registration_roster_empty',
                    409,
                ]];
            }
            $targetIds = $rows->pluck('student_course_registration_id')->map(fn ($targetId): int => (int) $targetId);
            if ($targetIds->unique()->count() !== $targetIds->count()) {
                return ['e' => [
                    'المحاولة الأكاديمية نفسها مسجلة أكثر من مرة في هذه الدورة.',
                    'supplementary_exam_registration_duplicate_target_conflict',
                    409,
                ]];
            }

            $targets = StudentCourseRegistration::query()
                ->with([
                    'registrationStatus',
                    'resultStatus',
                    'studentCourseResult.resultStatus',
                    'courseOffering.gradeApprovals.approvalStatus',
                ])
                ->whereIn('student_course_registration_id', $targetIds)
                ->orderBy('student_course_registration_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_course_registration_id');
            $period->load('semester');
            $rows->load('offering.sources');
            $rows->each(fn (SupplementaryExamRegistration $registration) =>
                $registration->offering?->setRelation('period', $period));
            $evaluationContext = $this->eligibility->evaluationContext(
                $rows->pluck('offering')->filter()->unique('supplementary_exam_offering_id')->values(),
                $targets->values(),
            );
            $invalid = [];
            $counts = [];
            foreach ($rows as $registration) {
                $original = $targets->get((int) $registration->student_course_registration_id);
                if (! $original
                    || (int) $original->student_id !== (int) $registration->student_id
                    || ! $this->eligibility->evaluate($registration->offering, $original, $evaluationContext)['eligible']) {
                    $invalid[] = $registration->getKey();
                }
                $counts[$registration->student_id] = ($counts[$registration->student_id] ?? 0) + 1;
            }
            $limit = SupplementaryExamPolicy::maxCoursesPerStudent($period);
            if ($limit !== null) {
                foreach ($counts as $studentId => $count) {
                    if ($count > $limit) {
                        $invalid[] = 'student:'.$studentId;
                    }
                }
            }
            if ($invalid) {
                return ['e' => [
                    'تتضمن القائمة تسجيلات غير صالحة: '.implode(',', array_slice($invalid, 0, 20)),
                    'supplementary_exam_registration_list_has_invalid_entries',
                    409,
                ]];
            }

            $crossPeriodDuplicate = $targetIds->isNotEmpty()
                && SupplementaryExamRegistration::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('status', 'registered')
                    ->where('current_slot', 1)
                    ->whereNotIn('supplementary_exam_registration_id', $rows->modelKeys())
                    ->whereHas('offering.period', fn ($query) => $query->whereIn(
                        'status',
                        SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES,
                    ))
                    ->orderBy('supplementary_exam_registration_id')
                    ->lockForUpdate()
                    ->exists();
            if ($crossPeriodDuplicate) {
                return ['e' => [
                    'المحاولة الأكاديمية نفسها مثبتة في دورة تكميلية أخرى. ألغِ التسجيل المتعارض قبل تثبيت هذه القائمة.',
                    'supplementary_exam_registration_cross_period_target_conflict',
                    409,
                ]];
            }

            $period->forceFill(['status' => 'registration_closed'])->save();
            $this->periodEvent($period, $actor, 'registration_closed', 'registration_open', 'registration_closed');

            return ['p' => $period->fresh()];
        }, 3);
        if (isset($out['e'])) {
            $this->fail(...$out['e']);
        }

        return $out['p'];
    }
    private function assertCanGovernPeriod(User $u):void{if(!$u->isRegistrationOfficer()||!$u->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::WINDOW)||!$this->scope->hasActualUniversityScope($u))$this->fail('يتطلب موظف تسجيل فعلي وصلاحية ونطاق الجامعة.','supplementary_exam_registration_out_of_scope',403);}
    private function ready():void{if(!SupplementaryExamRegistrationGovernance::schemaReady())$this->fail('المخطط غير جاهز.','supplementary_exam_registration_schema_not_ready',503);}
    private function periodEvent($p,$u,$type,$from,$to):void{SupplementaryExamPeriodEvent::query()->create(['supplementary_exam_period_id'=>$p->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$u->user_id,'created_at'=>now()]);}
    private function fail(string $m,string $c,int $s):never{throw new GradeException($m,status:$s,errorCode:$c);}
}
