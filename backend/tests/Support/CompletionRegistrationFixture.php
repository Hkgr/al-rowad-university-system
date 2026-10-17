<?php

namespace Tests\Support;

use App\Models\{CourseIncompleteCase, User};
use App\Services\IncompleteResultService;
use App\Support\IncompleteSchemaContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/** Test-only additions to the synthetic approved-catalog fixture. Never imports university records. */
final class CompletionRegistrationFixture
{
    public static function prepare(): void
    {
        if (!app()->environment('testing')) throw new \RuntimeException('Testing environment required.');
        $columns = [
            'student_course_results'=>['coursework_total'=>'decimal','is_deprived'=>'boolean','calculated_at'=>'dateTime','calculated_by_user_id'=>'integer','result_announced_at'=>'dateTime'],
            'grade_components'=>['component_name'=>'string'],
            'grade_audit_logs'=>['student_grade_component_id'=>'integer','old_mark'=>'decimal','new_mark'=>'decimal','changed_by_user_id'=>'integer','change_reason'=>'text','changed_at'=>'dateTime'],
            'supplementary_exam_materializations'=>['student_course_registration_id'=>'integer'],
            'supplementary_exam_registrations'=>['student_course_registration_id'=>'integer','current_slot'=>'integer','supplementary_exam_offering_id'=>'integer'],
        ];
        foreach (['student_grade_components'=>'student_grade_component_id','grade_part_approvals'=>'grade_part_approval_id','grade_audit_logs'=>'grade_audit_log_id'] as $table=>$id) if (!Schema::hasTable($table)) Schema::create($table,fn(Blueprint $t)=>$t->increments($id));
        $columns['student_grade_components']=['student_course_registration_id'=>'integer','grade_component_id'=>'integer','mark'=>'decimal','grade_status'=>'string','entered_by_user_id'=>'integer','entered_at'=>'dateTime','created_at'=>'dateTime','updated_at'=>'dateTime'];
        $columns['grade_part_approvals']=['course_offering_id'=>'integer','component_type'=>'string','status'=>'string'];
        foreach ($columns as $table=>$fields) foreach ($fields as $name=>$type) if (!Schema::hasColumn($table,$name)) Schema::table($table,function(Blueprint $t) use($name,$type) {
            $column = $type==='decimal' ? $t->decimal($name,5,2) : $t->{$type}($name);
            $column->nullable();
        });
        foreach (['supplementary_exam_periods'=>['supplementary_exam_period_id',[]],
            'supplementary_exam_offerings'=>['supplementary_exam_offering_id',['supplementary_exam_period_id']]] as $table=>[$id,$fields]) if (!Schema::hasTable($table)) Schema::create($table,function(Blueprint $t) use($id,$fields) {
                $t->increments($id); $t->string('status')->nullable(); foreach ($fields as $f) $t->integer($f)->nullable();
            });
        foreach (['supplementary_exam_periods'=>['status'=>'string'],'supplementary_exam_offerings'=>['supplementary_exam_period_id'=>'integer']] as $table=>$fields)
            foreach ($fields as $field=>$type) if (!Schema::hasColumn($table,$field)) Schema::table($table,fn(Blueprint $t)=>$t->{$type}($field)->nullable());
        if (!Schema::hasTable('grading_policies')) {
            Schema::create('grading_policies',function(Blueprint $t) {
                $t->increments('grading_policy_id'); $t->string('policy_name'); $t->boolean('is_active'); $t->boolean('is_default');
                foreach (['theoretical_max_mark','practical_max_mark','minimum_theoretical_mark','minimum_practical_mark','minimum_final_mark','absence_deprivation_percentage'] as $f) $t->decimal($f,5,2);
            });
            DB::table('grading_policies')->insert(['policy_name'=>'Synthetic only','is_active'=>1,'is_default'=>1,'theoretical_max_mark'=>100,
                'practical_max_mark'=>0,'minimum_theoretical_mark'=>50,'minimum_practical_mark'=>0,'minimum_final_mark'=>50,'absence_deprivation_percentage'=>25]);
        }
        foreach (['passed','failed','deprived','incomplete','pending_approval','unknown'] as $code) if (!DB::table('result_statuses')->where('status_code',$code)->exists()) DB::table('result_statuses')->insert(['status_code'=>$code,'is_active'=>true]);
        foreach (['pending','rejected','revoked'] as $code) if (!DB::table('approval_statuses')->where('status_code',$code)->exists()) DB::table('approval_statuses')->insert(['approval_status_id'=>1+(int)DB::table('approval_statuses')->max('approval_status_id'),'status_code'=>$code]);
        foreach (['dropped','withdrawn','cancelled'] as $code) if (!DB::table('registration_statuses')->where('status_code',$code)->exists()) DB::table('registration_statuses')->insert(['registration_status_id'=>1+(int)DB::table('registration_statuses')->max('registration_status_id'),'status_code'=>$code]);
        DB::table('users')->insert(['user_id'=>2,'username'=>'synthetic-exam-admin','account_status_id'=>1]);
        DB::table('roles')->insert(['role_id'=>2,'role_code'=>'super_admin','is_active'=>1]);
        DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>2,'is_active'=>1]);
        if (DB::getDriverName()==='mysql') {
            DB::statement('ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY practical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY final_mark DECIMAL(5,2) NULL DEFAULT NULL');
            DB::statement('ALTER TABLE student_grade_components MODIFY student_grade_component_id INT NOT NULL AUTO_INCREMENT');
        }
        if (!Schema::hasIndex('student_course_results',['student_course_registration_id'],'unique')) Schema::table('student_course_results',fn(Blueprint $t)=>$t->unique('student_course_registration_id'));
    }

    public static function sqliteLifecycle(): void
    {
        foreach (IncompleteSchemaContract::columns() as $table=>$columns) Schema::create($table,function(Blueprint $t) use($table,$columns) {
            foreach ($columns as $name=>$spec) {
                [$type,$nullable,$auto] = [$spec[0],$spec[1]??false,$spec[2]??false];
                if ($auto) { $type==='bigint' ? $t->bigIncrements($name) : $t->increments($name); continue; }
                $field=match(true) {
                    $type==='int unsigned'=>$t->unsignedInteger($name), $type==='int'=>$t->integer($name),
                    $type==='datetime'=>$t->dateTime($name), $type==='text'=>$t->text($name), $type==='longtext'=>$t->longText($name),
                    str_starts_with($type,'char(')=>$t->char($name,(int)substr($type,5)), default=>$t->string($name,(int)substr($type,8)),
                };
                if ($nullable) $field->nullable();
            }
            if ($table===IncompleteSchemaContract::CASES) { $t->unique('student_course_registration_id'); $t->unique('student_course_result_id'); }
            else { $t->unique('request_id'); $t->unique(['incomplete_case_id','revision']); }
        });
    }

    public static function registration(int $id, int $student=1040, string $offering='closed', ?string $outcome='passed', ?string $approval='approved'): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>$id,'course_id'=>403,'academic_program_id'=>40,'department_id'=>DB::table('academic_programs')->where('academic_program_id',40)->value('department_id'),
            'academic_year_id'=>1,'semester_id'=>1,'status'=>$offering,'created_at'=>'2025-01-01 00:00:00','updated_at'=>'2025-01-01 00:00:00']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>$student,'course_offering_id'=>$id,
            'registration_status_id'=>DB::table('registration_statuses')->where('status_code','registered')->value('registration_status_id'),
            'created_at'=>'2025-01-01 00:00:00','updated_at'=>'2025-01-01 00:00:00']);
        DB::table('grade_components')->insert(['grade_component_id'=>$id,'course_offering_id'=>$id,'component_type'=>'theoretical','component_name'=>'Synthetic theory','max_mark'=>100,'is_required'=>1]);
        if ($outcome!==null) DB::table('student_course_results')->insert(['student_course_registration_id'=>$id,'result_status_id'=>self::status($outcome),
            'final_mark'=>$outcome==='passed'?80:null,'theoretical_total'=>$outcome==='passed'?80:null,'coursework_total'=>0,'is_deprived'=>$outcome==='deprived',
            'result_announced_at'=>'2025-04-01 00:00:00','created_at'=>'2025-04-01 00:00:00','updated_at'=>'2025-04-01 00:00:00']);
        if ($approval!==null) self::approval($id,$approval);
    }
    public static function approval(int $id,string $code): void { DB::table('grade_approvals')->insert(['course_offering_id'=>$id,'approval_status_id'=>DB::table('approval_statuses')->where('status_code',$code)->value('approval_status_id')]); }
    public static function status(string $code): int { return (int)DB::table('result_statuses')->where('status_code',$code)->value('result_status_id'); }
    public static function lifecycle(int $id,string $state='active',string $outcome='F'): int
    {
        CarbonImmutable::setTestNow('2026-10-17 09:00:00 UTC');
        $service=app(IncompleteResultService::class);
        $response=$service->grant(User::findOrFail(2),$id,['request_id'=>(string)Str::uuid(),'expected_revision'=>0,
            'reason'=>'Synthetic I','requirements'=>'Complete the actual missing theoretical component','expiry_outcome'=>$outcome,'deadline_at'=>'2026-10-18']);
        $case=(int)$response['case']['incomplete_case_id'];
        if ($state==='resolved') $service->resolve(User::findOrFail(2),$case,['request_id'=>(string)Str::uuid(),'expected_revision'=>1,'components'=>[['grade_component_id'=>$id,'mark'=>80]]]);
        if ($state==='expired') { CarbonImmutable::setTestNow('2026-10-20 09:00:00 UTC'); $service->expire($case); }
        return $case;
    }
}
