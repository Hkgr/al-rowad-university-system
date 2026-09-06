<?php

namespace Tests\Feature;

use App\Services\ExecutiveReportQueryService;
use App\Models\User;
use App\Support\ExecutiveReportAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExecutiveReportsPhase1BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('account_statuses', fn(Blueprint $t)=>[$t->increments('account_status_id'),$t->string('status_code')]);
        Schema::create('users', fn(Blueprint $t)=>[$t->increments('user_id'),$t->string('username'),$t->unsignedInteger('account_status_id')]);
        Schema::create('roles', fn(Blueprint $t)=>[$t->increments('role_id'),$t->string('role_code'),$t->boolean('is_active')->default(true)]);
        Schema::create('permissions', fn(Blueprint $t)=>[$t->increments('permission_id'),$t->string('permission_code'),$t->boolean('is_active')->default(true)]);
        Schema::create('user_roles', fn(Blueprint $t)=>[$t->increments('user_role_id'),$t->unsignedInteger('user_id'),$t->unsignedInteger('role_id'),$t->boolean('is_active')->default(true)]);
        Schema::create('role_permissions', fn(Blueprint $t)=>[$t->increments('role_permission_id'),$t->unsignedInteger('role_id'),$t->unsignedInteger('permission_id')]);
        Schema::create('organizational_units', fn(Blueprint $t)=>[$t->increments('organizational_unit_id'),$t->string('unit_code'),$t->boolean('is_active')->default(true)]);
        Schema::create('user_access_scopes', fn(Blueprint $t)=>[$t->increments('user_access_scope_id'),$t->unsignedInteger('user_id'),$t->string('scope_type'),$t->unsignedInteger('scope_id'),$t->boolean('is_active')->default(true),$t->timestamps()]);
        Schema::create('colleges', fn(Blueprint $t)=>[$t->increments('college_id'),$t->string('college_name')->nullable()]);
        Schema::create('departments', fn(Blueprint $t)=>[$t->increments('department_id'),$t->unsignedInteger('college_id'),$t->string('department_name')->nullable()]);
        Schema::create('academic_programs', fn(Blueprint $t)=>[$t->increments('academic_program_id'),$t->unsignedInteger('department_id'),$t->string('program_name')->nullable()]);
        Schema::create('academic_levels', fn(Blueprint $t)=>[$t->increments('academic_level_id'),$t->string('level_name')->nullable()]);
        Schema::create('academic_years', fn(Blueprint $t)=>[$t->increments('academic_year_id'),$t->string('year_name')->nullable(),$t->date('start_date')->nullable(),$t->date('end_date')->nullable(),$t->boolean('is_current')->default(false),$t->boolean('is_active')->default(true),$t->string('calendar_lifecycle_status')->default('active')]);
        Schema::create('semesters', fn(Blueprint $t)=>[$t->increments('semester_id'),$t->string('semester_name')->nullable(),$t->unsignedInteger('semester_order')->default(1)]);
        Schema::create('student_statuses', fn(Blueprint $t)=>[$t->increments('student_status_id'),$t->string('status_code'),$t->string('status_name')->nullable()]);
        Schema::create('students', fn(Blueprint $t)=>[$t->increments('student_id'),$t->string('student_number'),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->unsignedInteger('academic_program_id'),$t->unsignedInteger('current_academic_level_id')->nullable(),$t->unsignedInteger('student_status_id'),$t->date('enrollment_date')->nullable(),$t->softDeletes()]);
        Schema::create('courses', fn(Blueprint $t)=>[$t->increments('course_id'),$t->string('course_code'),$t->string('course_name'),$t->unsignedInteger('credit_hours')->default(3)]);
        Schema::create('course_offerings', fn(Blueprint $t)=>[$t->increments('course_offering_id'),$t->unsignedInteger('course_id'),$t->unsignedInteger('academic_year_id'),$t->unsignedInteger('semester_id'),$t->unsignedInteger('academic_program_id')->nullable(),$t->string('status')->default('closed'),$t->timestamps()]);
        Schema::create('registration_statuses', fn(Blueprint $t)=>[$t->increments('registration_status_id'),$t->string('status_code')]);
        Schema::create('student_course_registrations', fn(Blueprint $t)=>[$t->increments('student_course_registration_id'),$t->unsignedInteger('student_id'),$t->unsignedInteger('course_offering_id'),$t->unsignedInteger('registration_status_id')]);
        Schema::create('result_statuses', fn(Blueprint $t)=>[$t->increments('result_status_id'),$t->string('status_code')]);
        Schema::create('student_course_results', fn(Blueprint $t)=>[$t->increments('student_course_result_id'),$t->unsignedInteger('student_course_registration_id'),$t->decimal('final_mark',6,2)->nullable(),$t->unsignedInteger('result_status_id')]);
        Schema::create('approval_statuses', fn(Blueprint $t)=>[$t->increments('approval_status_id'),$t->string('status_code')]);
        Schema::create('grade_approvals', fn(Blueprint $t)=>[$t->increments('grade_approval_id'),$t->unsignedInteger('course_offering_id'),$t->unsignedInteger('approval_status_id')]);
        $this->seedFacts();
    }

    public function test_access_requires_active_paired_vp_identity_permission_and_actual_university_scope(): void
    {
        $access=app(ExecutiveReportAccess::class);
        self::assertTrue($access->allows($this->actor(1,'vice_president_scientific','vice_presidency.scientific.access',true)));
        self::assertTrue($access->allows($this->actor(2,'vice_president_administrative','vice_presidency.administrative.access',true)));
        self::assertFalse($access->allows($this->actor(3,'vice_president_scientific','vice_presidency.administrative.access',true)));
        self::assertFalse($access->allows($this->actor(4,'super_admin','vice_presidency.scientific.access',true)));
        self::assertFalse($access->allows($this->actor(5,'vice_president_scientific','vice_presidency.scientific.access',false)));
    }

    public function test_official_metrics_use_latest_approval_and_canonical_repeated_attempt_gpa(): void
    {
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_result_count','official_average','official_gpa','pass_rate'],'dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(3.0,$data['summary']['official_result_count']);
        self::assertSame(3.5,$data['summary']['official_gpa']['value']);
        self::assertSame(2,$data['summary']['official_gpa']['contributing_students']);
        self::assertSame(3,$data['summary']['pass_rate']['denominator']);
        self::assertSame(2,$data['summary']['pass_rate']['numerator']);
        self::assertArrayHasKey('generated_at',$data);
    }

    private function seedFacts(): void
    {
        DB::table('colleges')->insert(['college_id'=>1]);DB::table('departments')->insert(['department_id'=>1,'college_id'=>1]);DB::table('academic_programs')->insert(['academic_program_id'=>1,'department_id'=>1]);DB::table('academic_levels')->insert(['academic_level_id'=>1]);
        DB::table('academic_years')->insert(['academic_year_id'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_current'=>1,'is_active'=>1,'calendar_lifecycle_status'=>'active']);DB::table('semesters')->insert(['semester_id'=>1,'semester_order'=>1]);
        DB::table('student_statuses')->insert(['student_status_id'=>1,'status_code'=>'active']);DB::table('students')->insert([['student_id'=>1,'student_number'=>'S1','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1],['student_id'=>2,'student_number'=>'S2','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1]]);
        DB::table('courses')->insert(['course_id'=>1,'course_code'=>'C1','course_name'=>'Course','credit_hours'=>3]);DB::table('course_offerings')->insert([['course_offering_id'=>1,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1],['course_offering_id'=>2,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1],['course_offering_id'=>3,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1]]);
        DB::table('registration_statuses')->insert([['registration_status_id'=>1,'status_code'=>'completed'],['registration_status_id'=>2,'status_code'=>'withdrawn']]);DB::table('result_statuses')->insert([['result_status_id'=>1,'status_code'=>'passed'],['result_status_id'=>2,'status_code'=>'failed']]);DB::table('approval_statuses')->insert([['approval_status_id'=>1,'status_code'=>'approved'],['approval_status_id'=>2,'status_code'=>'pending']]);
        DB::table('student_course_registrations')->insert([['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1],['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1],['student_course_registration_id'=>3,'student_id'=>2,'course_offering_id'=>3,'registration_status_id'=>1]]);
        DB::table('student_course_results')->insert([['student_course_result_id'=>1,'student_course_registration_id'=>1,'final_mark'=>50,'result_status_id'=>2],['student_course_result_id'=>2,'student_course_registration_id'=>2,'final_mark'=>80,'result_status_id'=>1],['student_course_result_id'=>3,'student_course_registration_id'=>3,'final_mark'=>100,'result_status_id'=>1]]);
        DB::table('grade_approvals')->insert([['grade_approval_id'=>1,'course_offering_id'=>1,'approval_status_id'=>1],['grade_approval_id'=>2,'course_offering_id'=>2,'approval_status_id'=>2],['grade_approval_id'=>3,'course_offering_id'=>2,'approval_status_id'=>1],['grade_approval_id'=>4,'course_offering_id'=>3,'approval_status_id'=>1]]);
    }

    private function actor(int $id,string $role,string $permission,bool $withScope): User
    {
        DB::table('account_statuses')->insertOrIgnore(['account_status_id'=>1,'status_code'=>'active']);
        DB::table('organizational_units')->insertOrIgnore(['organizational_unit_id'=>1,'unit_code'=>'PRES','is_active'=>1]);
        DB::table('users')->insert(['user_id'=>$id,'username'=>'u'.$id,'account_status_id'=>1]);
        $roleId=100+$id;$permissionId=100+$id;
        DB::table('roles')->insert(['role_id'=>$roleId,'role_code'=>$role,'is_active'=>1]);
        DB::table('permissions')->insert(['permission_id'=>$permissionId,'permission_code'=>$permission,'is_active'=>1]);
        DB::table('user_roles')->insert(['user_id'=>$id,'role_id'=>$roleId,'is_active'=>1]);
        DB::table('role_permissions')->insert(['role_id'=>$roleId,'permission_id'=>$permissionId]);
        if($withScope)DB::table('user_access_scopes')->insert(['user_id'=>$id,'scope_type'=>'university','scope_id'=>1,'is_active'=>1]);
        return User::query()->findOrFail($id);
    }
}
