<?php

namespace Tests\Support;

use App\Services\CatalogCompletion\ApprovedCatalogManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Real approved academic definitions; synthetic students only, never an imported university dump. */
final class ApprovedCatalogFixture
{
    public static function initialize(): void
    {
        AcademicPlanFixture::initialize();
        // effectiveRoles orders by this real column when the MySQL grammar is exercised.
        Schema::table('user_roles', fn (Blueprint $t) => $t->timestamp('assigned_at')->nullable());
        Schema::table('academic_levels', fn (Blueprint $t) => $t->integer('level_order')->nullable());
        Schema::table('semesters', fn (Blueprint $t) => $t->integer('semester_order')->nullable());
        DB::table('academic_levels')->where('academic_level_id', 1)->update(['level_order' => 1]);
        DB::table('semesters')->where('semester_id', 1)->update(['semester_order' => 1]);
        foreach (range(2, 5) as $id) DB::table('academic_levels')->insert(['academic_level_id' => $id, 'level_name' => 'Synthetic year '.$id, 'level_order' => $id]);
        DB::table('semesters')->insert(['semester_id' => 2, 'semester_name' => 'Synthetic second', 'semester_order' => 2]);
        $manifest = new ApprovedCatalogManifest;
        foreach ($manifest->rows('Catalog') as $c) DB::table('courses')->insert(['course_id' => (int) $c['course_id'], 'course_code' => $c['course_code'],
            'course_name' => $c['course_name'], 'credit_hours' => (int) $c['credit_hours'], 'theoretical_hours' => ApprovedCatalogManifest::nullableInt($c['theoretical_hours']),
            'practical_hours' => ApprovedCatalogManifest::nullableInt($c['practical_hours']), 'is_active' => (bool) $c['is_active']]);
        foreach ($manifest->programs() as $p) {
            $college = (int) $p['college_id']; $department = (int) $p['department_id']; $program = (int) $p['program_id'];
            DB::table('colleges')->insertOrIgnore(['college_id' => $college, 'college_name' => 'Synthetic college '.$college]);
            DB::table('departments')->insert(['department_id' => $department, 'college_id' => $college, 'department_name' => 'Synthetic department '.$department]);
            // Keep focused publication tests bounded; other programs deliberately fail identity reconciliation.
            DB::table('academic_programs')->insert(['academic_program_id' => $program, 'department_id' => $department,
                'program_name' => in_array($program, [40, 41, 42], true) ? $p['current_name'] : 'Synthetic unmatched program '.$program, 'total_credit_hours' => (int) $p['current_total']]);
            DB::table('students')->insert(['student_id' => 1000 + $program, 'academic_program_id' => $program, 'student_number' => 'SYNTHETIC-'.$program]);
        }
        $group = DB::table('academic_requirement_groups')->insertGetId(['academic_program_id' => 41, 'group_code' => 'SYN-INCOMPLETE-41',
            'group_name' => 'Synthetic missing budget', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory',
            'required_credit_hours' => null, 'is_active' => false], 'requirement_group_id');
        $membership = DB::table('program_courses')->insertGetId(['academic_program_id' => 41, 'course_id' => 403, 'course_type' => 'mandatory'], 'program_course_id');
        DB::table('program_course_requirement_groups')->insert(['program_course_id' => $membership, 'requirement_group_id' => $group]);
    }
}
