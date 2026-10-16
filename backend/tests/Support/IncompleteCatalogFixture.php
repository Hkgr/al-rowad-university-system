<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Synthetic installed-plan projection, never a production installer. */
final class IncompleteCatalogFixture
{
    public static function install(): void
    {
        self::assertIsolated();
        Schema::create('academic_catalog_control',function(Blueprint $t):void{$t->integer('control_id')->primary();$t->integer('schema_version');$t->unsignedBigInteger('revision');$t->boolean('is_ready');});
        Schema::create('academic_plan_control',function(Blueprint $t):void{$t->integer('control_id')->primary();$t->integer('schema_version');$t->boolean('is_ready');});
        DB::table('academic_catalog_control')->insert(['control_id'=>1,'schema_version'=>1,'revision'=>1,'is_ready'=>1]);
        DB::table('academic_plan_control')->insert(['control_id'=>1,'schema_version'=>1,'is_ready'=>1]);
        Schema::table('academic_programs',fn(Blueprint $t)=>[$t->string('plan_state')->default('ready'),$t->integer('default_academic_plan_version_id')->nullable()]);
        Schema::create('academic_plan_versions',function(Blueprint $t):void{
            $t->increments('academic_plan_version_id');$t->integer('academic_program_id');$t->string('status');$t->dateTime('fixed_at');$t->string('calculation_policy');$t->integer('total_credit_hours');
        });
        Schema::create('student_academic_plan_assignments',function(Blueprint $t):void{
            $t->increments('student_academic_plan_assignment_id');$t->integer('student_id');$t->integer('academic_program_id');$t->integer('academic_plan_version_id');$t->integer('current_slot')->nullable();$t->unique(['student_id','current_slot']);
        });
        Schema::table('program_courses',fn(Blueprint $t)=>[$t->integer('academic_plan_version_id')->nullable(),$t->integer('academic_level_id')->nullable(),$t->integer('recommended_semester_id')->nullable(),$t->string('course_type')->default('mandatory')]);
        Schema::create('academic_requirement_groups',function(Blueprint $t):void{
            $t->increments('requirement_group_id');$t->integer('academic_program_id');$t->integer('academic_plan_version_id');$t->string('group_code');$t->string('group_name');$t->string('requirement_scope');$t->string('requirement_type');$t->integer('required_credit_hours');$t->boolean('is_active');
        });
        Schema::create('program_course_requirement_groups',fn(Blueprint $t)=>[$t->increments('program_course_requirement_group_id'),$t->integer('program_course_id'),$t->integer('requirement_group_id')]);
        Schema::table('student_course_registrations',fn(Blueprint $t)=>[$t->integer('academic_plan_version_id')->nullable(),$t->integer('plan_program_course_id')->nullable()]);
        $hours=(int)DB::table('courses')->sum('credit_hours');
        DB::table('academic_plan_versions')->insert(['academic_plan_version_id'=>1,'academic_program_id'=>1,'status'=>'approved','fixed_at'=>'2026-01-01 00:00:00','calculation_policy'=>'legacy','total_credit_hours'=>$hours]);
        DB::table('academic_programs')->where('academic_program_id',1)->update(['default_academic_plan_version_id'=>1]);
        DB::table('academic_requirement_groups')->insert(['requirement_group_id'=>1,'academic_program_id'=>1,'academic_plan_version_id'=>1,'group_code'=>'SYN','group_name'=>'Synthetic fixed curriculum','requirement_scope'=>'department','requirement_type'=>'mandatory','required_credit_hours'=>$hours,'is_active'=>1]);
        foreach(DB::table('courses')->get() as $course){
            $id=DB::table('program_courses')->insertGetId(['academic_program_id'=>1,'academic_plan_version_id'=>1,'course_id'=>$course->course_id,'academic_level_id'=>1,'recommended_semester_id'=>1,'course_type'=>'mandatory','is_active'=>1]);
            DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$id,'requirement_group_id'=>1]);
            $offerings=DB::table('course_offerings')->where('course_id',$course->course_id)->pluck('course_offering_id');
            DB::table('student_course_registrations')->whereIn('course_offering_id',$offerings)->update(['academic_plan_version_id'=>1,'plan_program_course_id'=>$id]);
        }
        foreach(DB::table('students')->get() as $student)DB::table('student_academic_plan_assignments')->insert(['student_id'=>$student->student_id,'academic_program_id'=>1,'academic_plan_version_id'=>1,'current_slot'=>1]);
        // Whitelisted, existing production definitions, not simplified stand-in epoch triggers.
        self::reference('scientific-course-management','PROCEDURE','sc_catalog_touch');
        self::reference('academic-program-management','PROCEDURE','sc_plan_touch');
        self::reference('academic-program-management','FUNCTION','sc_plan_student_version');
        self::reference('academic-program-management','TRIGGER','sc_plan_course_registrations_i');
        self::reference('academic-program-management','TRIGGER','sc_plan_course_registrations_u');
        foreach(['course_results','grade_approvals','grade_audit_logs'] as $table)foreach(['i','u','d'] as $suffix)self::reference('academic-program-management','TRIGGER','sc_plan_epoch_'.$table.'_'.$suffix);
    }
    public static function assertIsolated():void
    {
        $path=getenv('COURSE_SYMBOLS_MARIADB_CONFIG');
        if(DB::getDriverName()!=='mysql' || !preg_match('/^course_symbols_([a-f0-9]{32})$/D',DB::getDatabaseName(),$match) || !$path || !is_file($path))throw new \RuntimeException('Isolated harness database required.');
        $config=json_decode(ltrim(file_get_contents($path),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
        $server=DB::selectOne('SELECT @@port AS port,@@datadir AS datadir');
        if($config['database']!==DB::getDatabaseName() || $config['marker']!==$match[1] || (int)$config['port']!==(int)$server->port
            || realpath(dirname($path).'/data')!==realpath($server->datadir) || DB::table('course_symbols_test_marker')->value('marker')!==$match[1])throw new \RuntimeException('Disposable identity mismatch.');
    }
    private static function reference(string $package,string $kind,string $name):void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/database/sql/'.$package.'/01_apply.sql');
        if(!preg_match('/^CREATE OR REPLACE '.preg_quote($kind.' '.$name,'/').'\b.*?END\/\//ms',$source,$m))throw new \RuntimeException('Missing whitelisted reference '.$name);
        DB::unprepared(substr($m[0],0,-2));
    }
}
