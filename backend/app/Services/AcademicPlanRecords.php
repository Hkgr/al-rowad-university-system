<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{Student, StudentCourseRegistration};
use Illuminate\Support\Facades\DB;

/** Pins provenance; it never changes marks, status, academic identity or requirement budgets. */
final class AcademicPlanRecords
{
    public static function assertCurrentRequest(\Illuminate\Database\Eloquent\Model $request): void
    {
        if (!AcademicPlanContext::installed()) return;
        $student = Student::query()->whereKey($request->student_id)->lockForUpdate()->firstOrFail();
        $actual = AcademicPlanContext::forStudent($student)->versionId;
        $recorded = $request->academic_plan_version_id === null ? null : (int) $request->academic_plan_version_id;
        if ($recorded !== $actual) throw AcademicPlanException::conflict('academic_plan_request_stale', 'خطة الطلب لا تطابق إسناد الطالب؛ راجع سياق العملية قبل اعتمادها.');
    }

    public static function pinRegistration(StudentCourseRegistration $registration, Student $student, int $courseId): void
    {
        if (!AcademicPlanContext::installed()) return;
        $context = AcademicPlanContext::forStudent($student);
        if ($context->versionId === null) return;
        $membership = $context->membership($courseId);
        if ($registration->academic_plan_version_id !== null
            && (int) $registration->academic_plan_version_id !== $context->versionId) {
            throw AcademicPlanException::conflict('academic_plan_registration_context_changed', 'هذا التسجيل مرتبط بخطة سابقة؛ لا يجوز إعادة ربطه ضمنيًا.');
        }
        $registration->forceFill(['academic_plan_version_id' => $context->versionId,
            'plan_program_course_id' => $membership?->getKey()])->save();
    }

    public static function pinTransition(int $programId, int $versionId): void
    {
        $memberships = DB::table('program_courses')->where('academic_plan_version_id', $versionId)->where('is_active', true)->pluck('program_course_id', 'course_id');
        // Bounded reads/writes. Old timestamps are retained; this is provenance, not an academic mutation.
        DB::table('student_course_registrations as r')->join('students as s', 's.student_id', '=', 'r.student_id')
            ->join('course_offerings as o', 'o.course_offering_id', '=', 'r.course_offering_id')->where('s.academic_program_id', $programId)
            ->whereNull('r.academic_plan_version_id')->select(['r.student_course_registration_id', 'o.course_id'])->orderBy('r.student_course_registration_id')
            ->chunkById(250, function ($rows) use ($memberships, $versionId) {
                foreach ($rows->groupBy('course_id') as $courseId => $group) DB::table('student_course_registrations')
                    ->whereIn('student_course_registration_id', $group->pluck('student_course_registration_id'))
                    ->update(['academic_plan_version_id' => $versionId, 'plan_program_course_id' => $memberships->get($courseId)]);
            }, 'r.student_course_registration_id', 'student_course_registration_id');
        foreach (['student_registration_requests', 'student_registration_modification_requests', 'student_registration_replacement_requests',
            'student_progression_decisions', 'student_graduation_decisions'] as $table) {
            DB::table($table)->whereIn('student_id', DB::table('students')->where('academic_program_id', $programId)->select('student_id'))
                ->whereNull('academic_plan_version_id')->update(['academic_plan_version_id' => $versionId]);
        }
    }
}
