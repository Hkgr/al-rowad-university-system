<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Disposable synthetic data only; never imports or alters a user's database. */
final class ExamTranscriptFixture
{
    public static function install(): void
    {
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('The exam transcript fixture requires in-memory SQLite.');
        }
        Schema::dropAllTables();
        CourseResultSymbolsFixture::installLegacy();
        CourseResultSymbolsFixture::installLifecycle();
        Schema::table('students', function (Blueprint $table): void {
            foreach (['father_name', 'mother_name', 'nationality', 'gender', 'address', 'date_of_birth'] as $field) {
                $table->string($field)->nullable();
            }
            $table->integer('student_status_id')->nullable();
        });
        Schema::create('student_academic_terms', function (Blueprint $table): void {
            $table->increments('student_academic_term_id');
            foreach (['student_id', 'academic_year_id', 'semester_id', 'academic_level_id'] as $field) {
                $table->integer($field);
            }
            $table->decimal('term_gpa')->nullable();
            $table->decimal('cumulative_gpa')->nullable();
            $table->timestamps();
        });
        DB::table('students')->where('student_id', 1)->update([
            'student_number' => 'SYN-2026-001',
            'first_name' => 'عبد الرحمن محمد عز الدين',
            'last_name' => 'الشيخ إبراهيم الاصطناعي',
            'father_name' => 'محمد عبد الكريم',
            'mother_name' => 'فاطمة الزهراء',
            'gender' => 'male',
            'nationality' => 'سورية',
            'date_of_birth' => '2002-03-14',
            'address' => 'عنوان سكن اصطناعي — ليس مكان الولادة',
        ]);
        DB::table('colleges')->where('college_id', 1)->update(['college_name' => 'كلية الهندسة']);
        DB::table('departments')->where('department_id', 1)->update(['department_name' => 'قسم هندسة تقانة المعلومات']);
        DB::table('academic_programs')->where('academic_program_id', 1)->update(['program_name' => 'هندسة تقانة المعلومات']);
        DB::table('academic_levels')->where('academic_level_id', 1)->update(['level_name' => 'المستوى الأول']);
        DB::table('academic_levels')->insert(['academic_level_id' => 2, 'level_name' => 'المستوى الثاني']);
        // The current level must never leak into an earlier historical heading.
        DB::table('students')->where('student_id', 1)->update(['current_academic_level_id' => 2]);
        DB::table('semesters')->where('semester_id', 1)->update(['semester_name' => 'الفصل الأول']);
        DB::table('semesters')->insert([
            ['semester_id' => 2, 'semester_name' => 'الفصل الثاني', 'semester_order' => 2],
            ['semester_id' => 3, 'semester_name' => 'الفصل الصيفي', 'semester_order' => 3],
        ]);
        DB::table('academic_years')->where('academic_year_id', 1)->update(['year_name' => '2025/2026']);
        DB::table('courses')->where('course_id', 1)->update(['course_code' => 'IT-101', 'course_name' => 'البرمجة والخوارزميات']);
    }

    public static function attempt(int $course, int $year, int $semester, ?float $mark, string $status = 'passed'): int
    {
        $offering = DB::table('course_offerings')->insertGetId([
            'course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester,
            'academic_program_id' => 1, 'department_id' => 1, 'status' => 'closed',
        ], 'course_offering_id');
        DB::table('grade_components')->insert([
            ['course_offering_id' => $offering, 'component_type' => 'theoretical', 'max_mark' => 60, 'is_required' => true],
            ['course_offering_id' => $offering, 'component_type' => 'practical', 'max_mark' => 40, 'is_required' => true],
        ]);
        $registration = DB::table('student_course_registrations')->insertGetId([
            'student_id' => 1, 'course_offering_id' => $offering,
            'registration_status_id' => $status === 'withdrawn' ? 5 : 4,
        ], 'student_course_registration_id');
        if ($status !== 'withdrawn') {
            DB::table('student_course_results')->insert([
                'student_course_registration_id' => $registration,
                'result_status_id' => DB::table('result_statuses')->where('status_code', $status)->value('result_status_id'),
                'theoretical_total' => $mark === null ? null : $mark * 0.6,
                'practical_total' => $mark === null ? null : $mark * 0.4,
                'final_mark' => $mark, 'is_deprived' => $status === 'deprived',
            ]);
        }
        DB::table('grade_approvals')->insert(['course_offering_id' => $offering, 'approval_status_id' => 1]);

        return $registration;
    }
}
