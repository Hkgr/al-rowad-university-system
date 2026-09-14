<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Student};
use Illuminate\Support\Facades\{Auth, DB};

/** Shared model boundary supplements database triggers; never grants admission authority. */
final class AcademicPlanAdmission
{
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
