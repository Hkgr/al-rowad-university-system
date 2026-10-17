<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Student};
use Illuminate\Support\Facades\{Auth, DB};

/** Shared model boundary supplements database triggers; never grants admission authority. */
final class AcademicPlanAdmission
{
    /** CLI reconciliation only: no ambient bypass, no existing-student program transfer.
     * Ordinary model/DB admission hooks remain active. The default trigger's actual insertion
     * is retained and explicitly ended in this same transaction when a historical reference differs.
     */
    public static function createHistorical(\App\Models\User $actor, array $fields, ?int $historicalVersionId, string $operationId,?array $completionContext=null): Student
    {
        \Illuminate\Support\Facades\Gate::forUser($actor)->authorize('create', Student::class);
        if (!\Ramsey\Uuid\Uuid::isValid($operationId)) throw new \InvalidArgumentException('Historical admission requires the reviewed operation UUID.');
        abort_unless(app(DataScopeService::class)->canMutateProgram($actor, (int) $fields['academic_program_id']), 403);
        if (DB::transactionLevel()<1) throw AcademicPlanException::conflict('academic_plan_transaction_required','Historical admission must be atomic.');
        app(AcademicCatalogTransaction::class)->revision(true);
        AcademicPlanContext::assertReady();
        $program=self::assertAccepting((int)$fields['academic_program_id']);
        $historical=null;
        if ($program->plan_state==='ready') {
            $versions=AcademicPlanVersion::where('academic_program_id',$program->getKey())->where('status','transitional')->whereNotNull('fixed_at')->orderBy('academic_plan_version_id')->lockForUpdate()->get();
            if ($versions->count()===1 && (int)$versions->sole()->getKey()===$historicalVersionId)$historical=$versions->sole();
            else {
                // A newly-created, fully approved completion program has no invented transitional
                // predecessor. Its exact recorded source/publication proof is required instead.
                if($versions->isNotEmpty()||($completionContext['kind']??null)!=='approved_now_from_recorded_source_curriculum_not_historical_approval'
                    ||!preg_match('/^[a-f0-9]{64}$/',$completionContext['profile_hash']??'')||(int)$program->default_academic_plan_version_id!==$historicalVersionId)
                    throw AcademicPlanException::conflict('historical_plan_context_unproven','Review the fixed historical reference or explicitly published completion source before creating this student.');
                $event=DB::table('academic_plan_events')->where('academic_plan_event_id',$completionContext['event_id']??null)->where('academic_program_id',$program->getKey())
                    ->where('academic_plan_version_id',$historicalVersionId)->where('action','catalog_completion_published')->first();
                $proof=$event?json_decode($event->context,true,flags:JSON_THROW_ON_ERROR):null;
                if(!$proof||($proof['new_program_created']??false)!==true||($proof['source_version_id']??null)!==null
                    ||($proof['reference_kind']??null)!==$completionContext['kind']||($proof['manifest_hash']??null)!==$completionContext['profile_hash']
                    ||($proof['program_key']??null)!==($completionContext['program_key']??null))
                    throw AcademicPlanException::conflict('historical_plan_context_unproven','The recorded completion source is missing or changed.');
                app(\App\Services\CatalogCompletion\CatalogCompletionPublication::class)->assertHistoricalAdmission($actor,$program,$historicalVersionId,$completionContext);
                $historical=AcademicPlanVersion::findOrFail($historicalVersionId);
            }
        } elseif ($historicalVersionId!==null) throw AcademicPlanException::conflict('historical_plan_context_unproven','Legacy admission cannot select an unrelated plan.');
        $student=Student::create($fields); // All ordinary admission hooks and the installed DB triggers run.
        if ($historical) {
            $links=DB::table('student_academic_plan_assignments')->where('student_id',$student->getKey())->where('current_slot',1)->lockForUpdate()->get();
            if ($links->count()!==1 || (int)$links->sole()->academic_plan_version_id!==(int)$program->default_academic_plan_version_id)
                throw AcademicPlanException::conflict('academic_plan_context_invalid','The just-created admission context changed.');
            if ((int)$links->sole()->academic_plan_version_id!==$historicalVersionId) {
                DB::table('student_academic_plan_assignments')->where('student_id',$student->getKey())->where('current_slot',1)->update(['current_slot'=>null,'ended_at'=>now()]);
                DB::table('student_academic_plan_assignments')->insert(['student_id'=>$student->getKey(),'academic_program_id'=>$program->getKey(),
                    'academic_plan_version_id'=>$historicalVersionId,'current_slot'=>1,'assigned_by_user_id'=>$actor->getKey(),
                    'reason'=>'reviewed_historical_student_completion:'.$operationId,'assigned_at'=>now()]);
            }
            DB::table('academic_plan_events')->insert(['academic_program_id'=>$program->getKey(),'academic_plan_version_id'=>$historicalVersionId,
                'action'=>'historical_student_admitted','actor_user_id'=>$actor->getKey(),'created_at'=>now(),
                'context'=>json_encode(['operation_id'=>$operationId,'student_id'=>$student->getKey(),'initial_trigger_assignment'=>$links->sole()->academic_plan_version_id,
                    'historical_reference'=>$historicalVersionId,'reference_kind'=>$completionContext['kind']??'current_state_not_historical_approval',
                    'completion_source'=>$completionContext],JSON_THROW_ON_ERROR)]);
        }
        return $student;
    }

    public static function beforeStudentCreate(Student $student): void
    {
        if (!AcademicPlanContext::installed()) return;
        AcademicPlanContext::assertReady();
        if (DB::transactionLevel() < 1) throw AcademicPlanException::conflict('academic_plan_transaction_required', 'إنشاء الطالب وإسناد خطته يتطلبان عملية ذرية.');
        app(AcademicCatalogTransaction::class)->revision(true);
        self::assertAccepting((int) $student->academic_program_id);
    }

    public static function assertAccepting(int $programId): AcademicProgram
    {
        $program = AcademicProgram::query()->whereKey($programId)->lockForUpdate()->firstOrFail();
        if ($program->archived_at !== null) throw AcademicPlanException::conflict('academic_program_archived', 'البرنامج مؤرشف؛ القبول الجديد غير متاح.');
        if ($program->plan_state === 'preparing') throw AcademicPlanException::conflict('academic_plan_initialization_incomplete', 'القبول الجديد موقوف حتى اكتمال تهيئة الخطط وتعيين خطة معتمدة للطلاب الجدد.');
        if ($program->plan_state === 'ready') {
            $version = AcademicPlanVersion::where('academic_program_id', $programId)->where('status', 'approved')
                ->whereNotNull('fixed_at')->find($program->default_academic_plan_version_id);
            if (!$version) throw AcademicPlanException::conflict('academic_plan_initialization_incomplete', 'لم تُحدد خطة معتمدة صالحة للطلاب الجدد.');
        } elseif ($program->plan_state !== 'legacy') {
            throw AcademicPlanException::conflict('academic_plan_context_invalid', 'حالة تهيئة البرنامج غير صالحة.');
        }
        return $program;
    }

    public static function afterStudentCreate(Student $student): void
    {
        if (!AcademicPlanContext::installed()) return;
        $program = self::assertAccepting((int) $student->academic_program_id);
        if ($program->plan_state !== 'ready') return;
        // MariaDB's AFTER INSERT guard already pins direct SQL writers. SQLite exercises the PHP fallback.
        $links = DB::table('student_academic_plan_assignments')->where('student_id', $student->getKey())->where('current_slot', 1)->get();
        if ($links->isEmpty()) DB::table('student_academic_plan_assignments')->insert([
            'student_id' => $student->getKey(), 'academic_program_id' => $student->academic_program_id,
            'academic_plan_version_id' => $program->default_academic_plan_version_id, 'current_slot' => 1,
            'assigned_by_user_id' => Auth::id(), 'reason' => 'default_on_student_creation', 'assigned_at' => now(),
        ]);
        $context = AcademicPlanContext::forStudent($student);
        if ($context->versionId !== (int) $program->default_academic_plan_version_id) throw AcademicPlanException::conflict('academic_plan_context_invalid', 'إسناد الطالب لا يطابق خطة القبول المختارة.');
    }

    public static function beforeStudentUpdate(Student $student): void
    {
        if (!AcademicPlanContext::installed() || !$student->isDirty('academic_program_id')) return;
        AcademicPlanContext::assertReady();
        app(AcademicCatalogTransaction::class)->revision(true);
        if (DB::table('student_academic_plan_assignments')->where('student_id', $student->getKey())->exists()) {
            throw AcademicPlanException::conflict('academic_plan_program_transfer_required', 'لا يجوز تغيير برنامج طالب ذي إسناد خطة من التعديل العام.');
        }
        $program = self::assertAccepting((int) $student->academic_program_id);
        if ($program->plan_state !== 'legacy') throw AcademicPlanException::conflict('academic_plan_program_transfer_required', 'نقل الطالب إلى برنامج ذي إصدارات يتطلب معالجة إسناد صريحة.');
    }
}
