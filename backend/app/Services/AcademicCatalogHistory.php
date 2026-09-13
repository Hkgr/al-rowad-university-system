<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Mirrors the current-read history predicates in the manual SQL protection package. */
final class AcademicCatalogHistory
{
    public const PROGRAM_REFERENCES = [
        'students' => ['student_id', 'academic_program_id'],
        'course_offerings' => ['course_offering_id', 'academic_program_id'],
        'supplementary_exam_offerings' => ['supplementary_exam_offering_id', 'academic_program_id'],
        'admission_applications' => ['admission_application_id', 'academic_program_id'],
        'ministry_placement_records' => ['placement_record_id', 'matched_academic_program_id'],
        'student_graduation_decisions' => ['student_graduation_decision_id', 'academic_program_id'],
        'student_progression_decisions' => ['student_progression_decision_id', 'academic_program_id'],
    ];

    public function programUsed(int $id): bool
    {
        // No active/status/soft-deletion filter: historical use remains use.
        foreach (self::PROGRAM_REFERENCES as $table => [$key, $foreign]) {
            if (DB::table($table)->where($foreign, $id)->orderBy($key)->lockForUpdate()->first([$key])) return true;
        }
        return false;
    }

    public function courseUsed(int $id): bool
    {
        if (DB::table('course_offerings')->where('course_id', $id)->orderBy('course_offering_id')->lockForUpdate()->first(['course_offering_id'])
            || DB::table('supplementary_exam_offerings')->where('course_id', $id)->orderBy('supplementary_exam_offering_id')->lockForUpdate()->first(['supplementary_exam_offering_id'])) return true;
        foreach (DB::table('program_courses')->where('course_id', $id)->orderBy('academic_program_id')->lockForUpdate()->pluck('academic_program_id') as $program) {
            if ($this->programUsed((int) $program)) return true;
        }
        return false;
    }

    public function deleteReasons(int $id): array
    {
        $reasons = [];
        foreach (['course_offerings' => 'المادة مرتبطة بطروحات وتاريخ أكاديمي.', 'supplementary_exam_offerings' => 'المادة مرتبطة بطرح تكميلي.',
            'program_courses' => 'افصل ارتباطات البرامج المسموح بفصلها أولًا.', 'course_instructors' => 'المادة مرتبطة بمدرسين.'] as $table => $message) {
            if (DB::table($table)->where('course_id', $id)->exists()) $reasons[] = $message;
        }
        if (DB::table('course_prerequisites')->where('course_id', $id)->orWhere('prerequisite_course_id', $id)->exists()) $reasons[] = 'المادة مرتبطة بمتطلبات سابقة.';
        return $reasons;
    }
}
