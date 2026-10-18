<?php

namespace Tests\Support;

use App\Models\{Course, Student, User};
use App\Services\{AcademicPlanContext, CourseOfferingContextService, RegistrationService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Additive synthetic Phase-6 fixture. Never imports a snapshot or disables an installed guard. */
final class SyntheticSupplementaryRace
{
    public static function schema(): void
    {
        if (\App\Support\SupplementaryExamMaterializationGovernance::schemaReady()) return;
        $build = function (string $table, callable $definition): void {
            if (!Schema::hasTable($table)) { Schema::create($table, $definition); return; }
            // Earlier synthetic runtime fixtures supply deliberately empty partial tables.
            // Complete those additively; never drop their definitions or any existing rows.
            if (DB::table($table)->exists()) throw new \RuntimeException('Nonempty supplementary fixture must not be replaced: '.$table);
            $existing = Schema::getColumnListing($table);
            $expected = new Blueprint(DB::connection(), $table);
            $definition($expected);
            if (!array_diff(array_map(fn($column)=>$column->name, $expected->getColumns()), $existing)) return;
            Schema::table($table, function (Blueprint $t) use ($definition, $existing) {
                $definition($t);
                foreach ($existing as $column) $t->removeColumn($column);
            });
        };
        $definitions = [
            'periods' => ['academic_year_id', 'semester_id'],
            'offerings' => ['supplementary_exam_period_id', 'academic_program_id', 'course_id'],
            'offering_sources' => ['supplementary_exam_offering_id', 'course_offering_id'],
            'registrations' => ['supplementary_exam_offering_id', 'student_id', 'student_course_registration_id'],
            'grade_submissions' => ['supplementary_exam_offering_id', 'submission_version'],
            'grader_assignments' => ['supplementary_exam_offering_id', 'faculty_member_id', 'assigned_by_user_id'],
            'grade_results' => ['supplementary_exam_registration_id', 'supplementary_exam_offering_id', 'student_course_registration_id', 'student_id', 'submission_version'],
            'grade_events' => ['supplementary_exam_grade_result_id', 'supplementary_exam_grade_submission_id', 'submission_version', 'actor_user_id'],
            'period_events' => ['supplementary_exam_period_id', 'actor_user_id'],
        ];
        foreach ($definitions as $suffix => $ids) {
            $table = 'supplementary_exam_'.$suffix;
            $build($table, function (Blueprint $t) use ($suffix, $ids) {
                $t->increments('supplementary_exam_'.substr($suffix, 0, -1).'_id');
                foreach ($ids as $id) $t->unsignedInteger($id);
                if (in_array($suffix, ['periods', 'offerings', 'registrations', 'grade_submissions', 'grade_results'])) $t->string('status', 32);
                if (in_array($suffix, ['registrations', 'grader_assignments'])) $t->tinyInteger('current_slot')->nullable();
                if ($suffix === 'registrations') $t->string('eligibility_reason', 40);
                if ($suffix === 'grader_assignments') { $t->dateTime('assigned_at'); $t->dateTime('ended_at')->nullable(); }
                if (in_array($suffix, ['grade_results', 'grade_events'])) $t->decimal('theoretical_mark', 5, 2)->nullable();
                if (in_array($suffix, ['grade_results', 'grade_submissions'])) $t->dateTime('published_at')->nullable();
                if (in_array($suffix, ['grade_events', 'period_events'])) {
                    $t->string('event_type', 64); $t->string('from_status', 32)->nullable(); $t->string('to_status', 32); $t->text('notes')->nullable();
                }
                $t->timestamps();
            });
        }
        $build('supplementary_exam_materializations', function (Blueprint $t) {
            $t->increments('supplementary_exam_materialization_id');
            foreach (['supplementary_exam_registration_id', 'supplementary_exam_offering_id', 'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id', 'supplementary_exam_grade_submission_id', 'source_submission_version', 'student_course_registration_id', 'student_course_result_id', 'student_id', 'grading_policy_id', 'grade_approval_id', 'preserved_registration_status_id', 'materialized_by_user_id'] as $id) $t->unsignedInteger($id);
            $t->decimal('source_theoretical_mark', 5, 2);
            foreach (['practical_components_snapshot', 'before_theoretical_components_snapshot', 'after_theoretical_components_snapshot'] as $c) $t->text($c);
            foreach (['source_registration_updated_at', 'source_result_published_at', 'source_submission_published_at', 'source_result_updated_at', 'source_submission_updated_at', 'grade_approval_updated_at', 'materialized_at', 'created_at'] as $c) $t->dateTime($c);
            foreach (['before', 'after'] as $side) {
                foreach (['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'] as $c) $t->decimal($side.'_'.$c, 5, 2);
                foreach (['result_status_id', 'registration_result_status_id', 'calculated_by_user_id'] as $c) $t->unsignedInteger($side.'_'.$c)->nullable();
                $t->boolean($side.'_is_deprived');
                foreach (['calculated_at', 'result_announced_at', 'result_updated_at', 'registration_updated_at'] as $c) $t->dateTime($side.'_'.$c)->nullable();
            }
            foreach (['supplementary_exam_registration_id', 'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id', 'student_course_registration_id', 'student_course_result_id'] as $i=>$id) $t->unique($id,'synthetic_sup_target_'.$i);
            $t->unique(['supplementary_exam_grade_submission_id', 'source_submission_version', 'supplementary_exam_registration_id'], 'synthetic_sup_version_unique');
        });
        $build('supplementary_exam_materialization_events', function (Blueprint $t) {
            $t->increments('supplementary_exam_materialization_event_id');
            foreach (['supplementary_exam_materialization_id', 'supplementary_exam_offering_id', 'supplementary_exam_registration_id', 'source_submission_version', 'actor_user_id'] as $id) $t->unsignedInteger($id);
            $t->unique('supplementary_exam_materialization_id','synthetic_sup_event_unique'); $t->string('event_type', 64); $t->dateTime('created_at');
        });
        if (!Schema::hasColumn('grading_policies', 'updated_at')) Schema::table('grading_policies', fn(Blueprint $t)=>$t->dateTime('updated_at')->nullable());
        foreach(['approval_statuses','registration_statuses'] as $table)if(!Schema::hasColumn($table,'is_active'))Schema::table($table,fn(Blueprint $t)=>$t->boolean('is_active')->default(true));
        if(!Schema::hasColumn('grade_approvals','approved_by_user_id'))Schema::table('grade_approvals',fn(Blueprint $t)=>$t->unsignedInteger('approved_by_user_id')->nullable());
        if(!Schema::hasColumn('grade_approvals','approval_date'))Schema::table('grade_approvals',fn(Blueprint $t)=>$t->dateTime('approval_date')->nullable());
        $module = DB::table('system_modules')->where('module_code', 'exams')->value('module_id')
            ?? DB::table('system_modules')->insertGetId(['module_code'=>'exams','is_active'=>true],'module_id');
        $id = DB::table('permissions')->where('permission_code','supplementary_exams.results.materialize')->value('permission_id')
            ?? DB::table('permissions')->insertGetId(['module_id'=>$module, 'permission_code'=>'supplementary_exams.results.materialize', 'permission_name'=>'Synthetic materialization', 'description'=>'owned:supplementary-exam-materialization-phase6', 'is_active'=>true], 'permission_id');
        $mapping=['role_id'=>DB::table('roles')->where('role_code', 'exam_officer')->value('role_id'), 'permission_id'=>$id];
        if(!DB::table('role_permissions')->where($mapping)->exists())DB::table('role_permissions')->insert($mapping);
    }

    public static function candidate(User $actor, Student $student, Course $course, int $ordinal): array
    {
        $old = '2026-01-01 10:00:00'; $published = '2026-01-02 10:00:00';
        $base=2300+(int)DB::table('academic_years')->max('academic_year_id');
        $year = DB::table('academic_years')->insertGetId(['year_name'=>$base.'-'.($base+1), 'start_date'=>$base.'-09-01', 'end_date'=>($base+1).'-08-31', 'is_current'=>false], 'academic_year_id');
        $o = app(CourseOfferingContextService::class)->createManualRecordingOffering($student, $course, $year, 1, $actor);
        foreach ([['theoretical', 60, 20], ['practical', 40, 30]] as [$type, $max, $mark]) {
            $component = $o->gradeComponents()->create(['component_name'=>'Synthetic '.$type, 'component_type'=>$type, 'max_mark'=>$max, 'is_required'=>true]);
            $components[$type] = [$component->getKey(), $mark];
        }
        $r = app(RegistrationService::class)->prepareManualGradeRegistration($student, $o, $actor, ['confirmed'=>true, 'reason'=>'Synthetic supplementary/import race', 'course_id'=>$course->getKey(), 'academic_year_id'=>$year, 'semester_id'=>1]);
        AcademicPlanContext::transaction(function () use ($r, $o, $actor, $components, $old) {
            $failed=DB::table('result_statuses')->where('status_code','failed')->value('result_status_id');
            DB::table('student_course_registrations')->where('student_course_registration_id',$r->getKey())->update(['result_status_id'=>$failed, 'updated_at'=>$old]);
            DB::table('course_offerings')->where('course_offering_id',$o->getKey())->update(['status'=>'closed', 'updated_at'=>$old]);
            DB::table('student_course_results')->insert(['student_course_registration_id'=>$r->getKey(), 'theoretical_total'=>20, 'practical_total'=>30, 'coursework_total'=>7, 'final_mark'=>50, 'result_status_id'=>$failed, 'is_deprived'=>false, 'calculated_at'=>$old, 'result_announced_at'=>$old, 'calculated_by_user_id'=>$actor->getKey(), 'created_at'=>$old, 'updated_at'=>$old]);
            foreach ($components as [$id,$mark]) DB::table('student_grade_components')->insert(['student_course_registration_id'=>$r->getKey(), 'grade_component_id'=>$id, 'mark'=>$mark, 'grade_status'=>'approved', 'created_at'=>$old, 'updated_at'=>$old]);
            DB::table('grade_components')->where('course_offering_id',$o->getKey())->update(['created_at'=>$old,'updated_at'=>$old]);
            DB::table('grade_approvals')->insert(['course_offering_id'=>$o->getKey(), 'approval_status_id'=>DB::table('approval_statuses')->where('status_code','approved')->value('approval_status_id'), 'approved_by_user_id'=>$actor->getKey(), 'approval_date'=>$old, 'created_at'=>$old, 'updated_at'=>$old]);
        });
        $period=DB::table('supplementary_exam_periods')->insertGetId(['academic_year_id'=>$year,'semester_id'=>1,'status'=>'results_published','created_at'=>$old,'updated_at'=>$old],'supplementary_exam_period_id');
        $sup=DB::table('supplementary_exam_offerings')->insertGetId(['supplementary_exam_period_id'=>$period,'academic_program_id'=>$student->academic_program_id,'course_id'=>$course->getKey(),'status'=>'closed','created_at'=>$old,'updated_at'=>$old],'supplementary_exam_offering_id');
        DB::table('supplementary_exam_offering_sources')->insert(['supplementary_exam_offering_id'=>$sup,'course_offering_id'=>$o->getKey(),'created_at'=>$old]);
        $supReg=DB::table('supplementary_exam_registrations')->insertGetId(['supplementary_exam_offering_id'=>$sup,'student_id'=>$student->getKey(),'student_course_registration_id'=>$r->getKey(),'status'=>'registered','current_slot'=>1,'eligibility_reason'=>'failed_theoretical','created_at'=>$old,'updated_at'=>$old],'supplementary_exam_registration_id');
        $submission=DB::table('supplementary_exam_grade_submissions')->insertGetId(['supplementary_exam_offering_id'=>$sup,'submission_version'=>1,'status'=>'published','published_at'=>$published,'created_at'=>$published,'updated_at'=>$published],'supplementary_exam_grade_submission_id');
        $result=DB::table('supplementary_exam_grade_results')->insertGetId(['supplementary_exam_registration_id'=>$supReg,'supplementary_exam_offering_id'=>$sup,'student_course_registration_id'=>$r->getKey(),'student_id'=>$student->getKey(),'theoretical_mark'=>40,'status'=>'published','submission_version'=>1,'published_at'=>$published,'created_at'=>$published,'updated_at'=>$published],'supplementary_exam_grade_result_id');
        DB::table('supplementary_exam_grader_assignments')->insert(['supplementary_exam_offering_id'=>$sup,'faculty_member_id'=>77,'current_slot'=>1,'assigned_by_user_id'=>$actor->getKey(),'assigned_at'=>$old,'created_at'=>$old,'updated_at'=>$old]);
        foreach ([['submitted','draft','submitted'],['approved','submitted','approved'],['published','approved','published']] as [$event,$from,$to]) DB::table('supplementary_exam_grade_events')->insert(['supplementary_exam_grade_result_id'=>$result,'supplementary_exam_grade_submission_id'=>$submission,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'submission_version'=>1,'theoretical_mark'=>40,'actor_user_id'=>$actor->getKey(),'created_at'=>$published]);
        return ['registration_id'=>(int)$r->getKey(),'offering_id'=>(int)$o->getKey(),'supplementary_offering_id'=>$sup,'supplementary_registration_id'=>$supReg,'period_id'=>$period,'year_name'=>$base.'-'.($base+1),'components'=>$components];
    }
}
