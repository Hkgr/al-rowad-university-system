<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

/** Synthetic records only. No source workbook or university dump is imported into tests. */
final class StudentCompletionFixture
{
    public static function initialize(): void
    {
        AcademicPlanFixture::initialize();
        foreach(['father_name'=>'string','enrollment_date'=>'date'] as $name=>$type) Schema::table('students',fn(Blueprint $t)=>$t->{$type}($name)->nullable());
        Schema::table('students',fn(Blueprint $t)=>$t->unique('student_number'));
        Schema::table('academic_levels',fn(Blueprint $t)=>$t->integer('level_order')->nullable());
        Schema::table('semesters',fn(Blueprint $t)=>$t->integer('semester_order')->nullable());
        DB::table('academic_levels')->where('academic_level_id',1)->update(['level_order'=>1]);
        DB::table('semesters')->where('semester_id',1)->update(['semester_order'=>1]);
        DB::table('semesters')->insert(['semester_id'=>2,'semester_name'=>'Second','semester_order'=>2]);
        DB::table('academic_levels')->insert([['academic_level_id'=>2,'level_name'=>'Second','level_order'=>2],['academic_level_id'=>3,'level_name'=>'Third','level_order'=>3]]);
        DB::table('academic_years')->where('academic_year_id',1)->update(['year_name'=>'2023-2024','start_date'=>'2023-09-01','end_date'=>'2024-08-31']);
        DB::table('academic_years')->insert(['academic_year_id'=>2,'year_name'=>'2024-2025','start_date'=>'2024-09-01','end_date'=>'2025-08-31']);
        foreach([1=>'active',17=>'frozen',21=>'deceased',23=>'graduated',24=>'dismissed',25=>'withdrawn',26=>'suspended'] as $id=>$code)
            DB::table('student_statuses')->insert(['student_status_id'=>$id,'status_code'=>$code,'status_name'=>$code]);
        DB::table('students')->where('student_id',1)->update(['student_number'=>'S1','first_name'=>'Test','last_name'=>'Student','father_name'=>'Parent',
            'enrollment_date'=>'2023-12-10','student_status_id'=>1,'current_academic_level_id'=>1]);
        foreach([1,2] as $id) DB::table('academic_programs')->where('academic_program_id',$id)->update(['program_code'=>'P'.$id]);
        foreach(['students.view','students.manage','grades.view'] as $code) {
            $id=DB::table('permissions')->insertGetId(['permission_code'=>$code],'permission_id');
            DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>$id]);
        }
        Schema::create('student_academic_terms',function(Blueprint $t) {
            $t->increments('student_academic_term_id');$t->integer('student_id');$t->integer('academic_year_id');$t->integer('semester_id');
            $t->boolean('is_finalized');$t->integer('total_registered_hours');$t->timestamps();
        });
        foreach(['INSERT','UPDATE','DELETE'] as $event) DB::unprepared("CREATE TRIGGER completion_term_epoch_{$event} AFTER {$event} ON student_academic_terms BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        DB::table('course_offerings')->insert(['course_offering_id'=>1,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>2]);
        DB::table('student_academic_terms')->insert(['student_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'is_finalized'=>1,'total_registered_hours'=>3]);
        self::settle(1,1);
    }

    public static function settle(int $registration,int $offering): void
    {
        $status=DB::table('result_statuses')->where('status_code','failed')->value('result_status_id')
            ?? DB::table('result_statuses')->insertGetId(['status_code'=>'failed'],'result_status_id');
        DB::table('student_course_results')->insert(['student_course_registration_id'=>$registration,'result_status_id'=>$status,'final_mark'=>null]);
        DB::table('grade_approvals')->insert(['course_offering_id'=>$offering,'approval_status_id'=>1]);
    }

    public static function bundle(string $number='S1'): array
    {
        return ['contract'=>'student-completion.sources.v1','config'=>['program_aliases'=>['Synthetic program'=>'P1']],
            'sources'=>[],'snapshot'=>[], 'identities'=>[['number'=>$number,'first_name'=>'Test','last_name'=>'Student','father_name'=>'Parent',
                'full_name'=>'Test Student','program'=>'Synthetic program','college'=>'كلية 1','enrollment_date'=>'2023-12-10','cohort'=>'2024/2023','status'=>'active',
                'reference'=>['file'=>'roster','sheet'=>'Synthetic','row'=>5]]],
            'observations'=>[['number'=>$number,'name'=>'Test Student','program'=>'Synthetic program','course_code'=>'C1','course_name'=>'مادة 1',
                'plan_term'=>7,'year'=>'2024/2023','cohort'=>'2024/2023','attempt'=>1,'exam_mark'=>'-','outcome'=>'Absent','supplementary'=>null,
                'kind'=>'regular','reference'=>['file'=>'exam1','sheet'=>'Synthetic','row'=>2]]],'conflicts'=>[]];
    }
}
