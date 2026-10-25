<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, Student, StudentCourseRegistration};
use Illuminate\Database\QueryException;
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

    /** A missing assignment must return NULL. Without this handler the lookup aborts the insert. */
    public static function ensureRegistrationPlanLookup(): void
    {
        static $attempted = false;
        if ($attempted || DB::getDriverName() !== 'mysql') {
            return;
        }
        $attempted = true;
        foreach ([
            "CREATE OR REPLACE FUNCTION sc_plan_student_version(student_key INT) RETURNS INT READS SQL DATA
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  DECLARE owner_key INT DEFAULT NULL;
  DECLARE state_value VARCHAR(20) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;
  SELECT academic_program_id INTO owner_key FROM students WHERE student_id=student_key;
  SELECT plan_state INTO state_value FROM academic_programs WHERE academic_program_id=owner_key;
  SELECT academic_plan_version_id INTO version_key FROM student_academic_plan_assignments
    WHERE student_id=student_key AND academic_program_id=owner_key AND current_slot=1;
  IF version_key IS NULL AND (state_value='ready' OR EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=owner_key AND status='transitional')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  RETURN version_key;
END",
            "CREATE OR REPLACE TRIGGER sc_plan_course_registrations_i BEFORE INSERT ON student_course_registrations FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
  IF version_key IS NOT NULL THEN
    SET NEW.plan_program_course_id=(SELECT pc.program_course_id FROM program_courses pc JOIN course_offerings o ON o.course_id=pc.course_id
      WHERE o.course_offering_id=NEW.course_offering_id AND pc.academic_plan_version_id=version_key AND pc.is_active=1 LIMIT 1);
  ELSE SET NEW.plan_program_course_id=NULL;
  END IF;
END",
        ] as $statement) {
            try {
                DB::unprepared($statement);
            } catch (\Throwable) {
                // The database user may not be allowed to replace routines. The save still reports its own failure.
            }
        }
    }

    public static function pinRegistration(StudentCourseRegistration $registration, Student $student, int $courseId): void
    {
        if (!AcademicPlanContext::installed()) return;
        $context = AcademicPlanContext::forStudent($student);
        if ($context->versionId === null) return;
        $registration->refresh();
        if ($registration->academic_plan_version_id !== null) {
            if ((int) $registration->academic_plan_version_id !== $context->versionId) {
                throw AcademicPlanException::conflict('academic_plan_registration_context_changed', 'هذا التسجيل مرتبط بخطة سابقة؛ لا يجوز إعادة ربطه ضمنيًا.');
            }
            // The insert trigger already stored this provenance. A second write is rejected.
            return;
        }
        // An approved plan may be pinned only by the insert trigger. Writing it
        // afterwards is rejected and would roll the new registration back.
        $status = AcademicPlanVersion::query()->whereKey($context->versionId)->value('status');
        if (DB::getDriverName() === 'mysql' && $status !== 'transitional') {
            return;
        }
        $membership = $context->membership($courseId);
        try {
            $registration->forceFill(['academic_plan_version_id' => $context->versionId,
                'plan_program_course_id' => $membership?->getKey()])->save();
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'academic_plan_record_immutable')
                && ! str_contains($exception->getMessage(), 'academic_plan_context_invalid')) {
                throw $exception;
            }
            $registration->refresh();
        }
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
                    ->update(['academic_plan_version_id' => $versionId, 'plan_program_course_id' => $memberships->get($courseId), 'updated_at' => DB::raw('updated_at')]);
            }, 'r.student_course_registration_id', 'student_course_registration_id');
        foreach (['student_registration_requests', 'student_registration_modification_requests', 'student_registration_replacement_requests',
            'student_progression_decisions', 'student_graduation_decisions'] as $table) {
            DB::table($table)->whereIn('student_id', DB::table('students')->where('academic_program_id', $programId)->select('student_id'))
                ->whereNull('academic_plan_version_id')->update(['academic_plan_version_id' => $versionId, 'updated_at' => DB::raw('updated_at')]);
        }
    }
}
