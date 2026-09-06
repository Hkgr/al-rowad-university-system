<?php

namespace Tests\Feature;

use App\Services\ExecutiveReportQueryService;
use App\Services\GradeService;
use App\Models\Student;
use App\Models\User;
use App\Support\ExecutiveReportAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
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
        Schema::create('colleges', fn(Blueprint $t)=>[$t->increments('college_id'),$t->string('college_name')->nullable(),$t->unsignedInteger('organizational_unit_id')->nullable()]);
        Schema::create('departments', fn(Blueprint $t)=>[$t->increments('department_id'),$t->unsignedInteger('college_id'),$t->string('department_name')->nullable()]);
        Schema::create('academic_programs', fn(Blueprint $t)=>[$t->increments('academic_program_id'),$t->unsignedInteger('department_id'),$t->string('program_name')->nullable()]);
        Schema::create('academic_levels', fn(Blueprint $t)=>[$t->increments('academic_level_id'),$t->string('level_name')->nullable()]);
        Schema::create('academic_years', fn(Blueprint $t)=>[$t->increments('academic_year_id'),$t->string('year_name')->nullable(),$t->date('start_date')->nullable(),$t->date('end_date')->nullable(),$t->boolean('is_current')->default(false),$t->boolean('is_active')->default(true),$t->string('calendar_lifecycle_status')->default('active')]);
        Schema::create('semesters', fn(Blueprint $t)=>[$t->increments('semester_id'),$t->string('semester_name')->nullable(),$t->unsignedInteger('semester_order')->default(1)]);
        Schema::create('student_statuses', fn(Blueprint $t)=>[$t->increments('student_status_id'),$t->string('status_code'),$t->string('status_name')->nullable()]);
        Schema::create('students', fn(Blueprint $t)=>[$t->increments('student_id'),$t->string('student_number'),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->unsignedInteger('academic_program_id'),$t->unsignedInteger('current_academic_level_id')->nullable(),$t->unsignedInteger('student_status_id'),$t->date('enrollment_date')->nullable(),$t->softDeletes()]);
        Schema::create('courses', fn(Blueprint $t)=>[$t->increments('course_id'),$t->string('course_code'),$t->string('course_name'),$t->unsignedInteger('credit_hours')->default(3),$t->decimal('theoretical_hours',5,2)->default(0),$t->decimal('practical_hours',5,2)->default(0)]);
        Schema::create('course_offerings', fn(Blueprint $t)=>[$t->increments('course_offering_id'),$t->unsignedInteger('course_id'),$t->unsignedInteger('academic_year_id'),$t->unsignedInteger('semester_id'),$t->unsignedInteger('academic_program_id')->nullable(),$t->string('status')->default('closed'),$t->unsignedInteger('capacity')->nullable(),$t->unsignedInteger('available_seats')->nullable(),$t->timestamps()]);
        Schema::create('grade_components', fn(Blueprint $t)=>[$t->increments('grade_component_id'),$t->unsignedInteger('course_offering_id'),$t->string('component_type'),$t->boolean('is_required')->default(true)]);
        Schema::create('grade_part_approvals', fn(Blueprint $t)=>[$t->increments('grade_part_approval_id'),$t->unsignedInteger('course_offering_id'),$t->string('component_type'),$t->string('status')->default('draft'),$t->unsignedInteger('submission_version')->default(0),$t->dateTime('submitted_at')->nullable(),$t->dateTime('reviewed_at')->nullable(),$t->timestamps()]);
        Schema::create('grade_part_approval_events', fn(Blueprint $t)=>[$t->increments('grade_part_approval_event_id'),$t->unsignedInteger('grade_part_approval_id'),$t->unsignedInteger('submission_version'),$t->string('action'),$t->dateTime('performed_at')]);
        Schema::create('registration_statuses', fn(Blueprint $t)=>[$t->increments('registration_status_id'),$t->string('status_code')]);
        Schema::create('student_course_registrations', fn(Blueprint $t)=>[$t->increments('student_course_registration_id'),$t->unsignedInteger('student_id'),$t->unsignedInteger('course_offering_id'),$t->unsignedInteger('registration_status_id')]);
        Schema::create('result_statuses', fn(Blueprint $t)=>[$t->increments('result_status_id'),$t->string('status_code')]);
        Schema::create('student_course_results', fn(Blueprint $t)=>[$t->increments('student_course_result_id'),$t->unsignedInteger('student_course_registration_id'),$t->decimal('theoretical_total',6,2)->nullable(),$t->decimal('practical_total',6,2)->nullable(),$t->decimal('final_mark',6,2)->nullable(),$t->unsignedInteger('result_status_id')]);
        Schema::create('approval_statuses', fn(Blueprint $t)=>[$t->increments('approval_status_id'),$t->string('status_code')]);
        Schema::create('grade_approvals', fn(Blueprint $t)=>[$t->increments('grade_approval_id'),$t->unsignedInteger('course_offering_id'),$t->unsignedInteger('approval_status_id')]);
        Schema::create('employees', fn(Blueprint $t)=>[$t->increments('employee_id'),$t->string('employee_number'),$t->string('first_name')->nullable(),$t->string('last_name')->nullable(),$t->unsignedInteger('organizational_unit_id')->nullable()]);
        Schema::create('employee_unit_assignments', fn(Blueprint $t)=>[$t->increments('employee_unit_assignment_id'),$t->unsignedInteger('employee_id'),$t->unsignedInteger('organizational_unit_id'),$t->boolean('is_active')->default(true),$t->date('end_date')->nullable()]);
        Schema::create('faculty_members', fn(Blueprint $t)=>[$t->increments('faculty_member_id'),$t->unsignedInteger('employee_id'),$t->string('academic_rank')->nullable(),$t->boolean('is_active')->default(true)]);
        Schema::create('course_offering_instructors', fn(Blueprint $t)=>[$t->increments('course_offering_instructor_id'),$t->unsignedInteger('course_offering_id'),$t->unsignedInteger('faculty_member_id'),$t->boolean('is_active')->default(true)]);
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
        self::assertFalse($access->allows($this->actor(6,'employee','vice_presidency.scientific.access',true)));
        self::assertFalse($access->allows($this->actor(7,'vice_president_scientific','students.view',true)));
    }

    public function test_http_routes_enforce_authentication_and_the_complete_vp_access_pair(): void
    {
        $this->getJson('/api/v1/vice-presidency/reports/definitions')->assertUnauthorized();
        Sanctum::actingAs($this->actor(10,'vice_president_scientific','vice_presidency.scientific.access',true));
        $this->getJson('/api/v1/vice-presidency/reports/definitions')->assertOk()->assertJsonPath('data.version','executive-reports.v1');

        Sanctum::actingAs($this->actor(11,'vice_president_scientific','vice_presidency.administrative.access',true));
        $this->getJson('/api/v1/vice-presidency/reports/definitions')->assertForbidden();

        DB::table('account_statuses')->insert(['account_status_id'=>2,'status_code'=>'inactive']);
        $inactive=$this->actor(12,'vice_president_scientific','vice_presidency.scientific.access',true);
        DB::table('users')->where('user_id',12)->update(['account_status_id'=>2]);
        Sanctum::actingAs($inactive->fresh());
        $this->getJson('/api/v1/vice-presidency/reports/definitions')->assertForbidden();
    }

    public function test_http_query_rejects_unknown_duplicate_and_transitively_mismatched_context(): void
    {
        Sanctum::actingAs($this->actor(20,'vice_president_administrative','vice_presidency.administrative.access',true));
        $base=['subject'=>'students','mode'=>'summary','metrics'=>['student_count'],'dimensions'=>[],'page'=>1,'per_page'=>25];
        $this->postJson('/api/v1/vice-presidency/reports/query',$base+['raw_column'=>'students.email'])->assertUnprocessable();
        $this->postJson('/api/v1/vice-presidency/reports/query',array_replace($base,['metrics'=>['student_count','student_count']]))->assertUnprocessable();
        $this->postJson('/api/v1/vice-presidency/reports/query',array_replace($base,['filters'=>['semester_ids'=>[1]]]))->assertUnprocessable();

        DB::table('colleges')->insert(['college_id'=>2]);DB::table('departments')->insert(['department_id'=>2,'college_id'=>2]);DB::table('academic_programs')->insert(['academic_program_id'=>2,'department_id'=>2]);
        $this->postJson('/api/v1/vice-presidency/reports/query',array_replace($base,['filters'=>['college_ids'=>[1],'program_ids'=>[2]]]))->assertUnprocessable();
    }

    public function test_http_student_details_succeed_with_optional_official_aggregates_gpa_and_students_without_results(): void
    {
        DB::table('students')->insert(['student_id'=>3,'student_number'=>'S3','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>4,'student_id'=>3,'course_offering_id'=>1,'registration_status_id'=>1]);
        Sanctum::actingAs($this->actor(30,'vice_president_scientific','vice_presidency.scientific.access',true));
        $base=['subject'=>'students','mode'=>'details','dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25];

        $without=$this->postJson('/api/v1/vice-presidency/reports/query',$base+['metrics'=>['student_count']])->assertOk();
        self::assertCount(3,$without->json('data.rows'));
        self::assertArrayNotHasKey('official_result_count',$without->json('data.rows.0'));

        $with=$this->postJson('/api/v1/vice-presidency/reports/query',$base+['metrics'=>['student_count','official_result_count','official_average','official_gpa','attempted_credit_hours','earned_credit_hours']])->assertOk();
        $rows=collect($with->json('data.rows'))->keyBy('student_number');
        self::assertSame(0,$rows['S3']['official_result_count']);
        self::assertNull($rows['S3']['official_average']);
        self::assertSame(['value'=>null,'contributing_students'=>0],$rows['S3']['official_gpa']);
        self::assertSame(0,$rows['S3']['attempted_credit_hours']);
        self::assertEquals(3.0,$rows['S1']['official_gpa']['value']);
    }

    public function test_http_grade_workflow_uses_snapshot_dimensions_and_preserves_each_missing_required_part(): void
    {
        DB::table('grade_components')->insert([['grade_component_id'=>1,'course_offering_id'=>1,'component_type'=>'theoretical','is_required'=>1],['grade_component_id'=>2,'course_offering_id'=>1,'component_type'=>'practical','is_required'=>1]]);
        Sanctum::actingAs($this->actor(31,'vice_president_administrative','vice_presidency.administrative.access',true));
        $summary=$this->postJson('/api/v1/vice-presidency/reports/query',['subject'=>'grade_workflow','mode'=>'summary','metrics'=>['required_parts_count','draft_parts_count'],'dimensions'=>['grade_workflow_status'],'filters'=>['offering_ids'=>[1]],'page'=>1,'per_page'=>25])->assertOk();
        self::assertSame(2,$summary->json('data.summary.required_parts_count'));
        self::assertSame(2,$summary->json('data.summary.draft_parts_count'));
        self::assertSame('draft',$summary->json('data.series.0.grade_workflow_status'));

        $page1=$this->postJson('/api/v1/vice-presidency/reports/query',['subject'=>'grade_workflow','mode'=>'details','metrics'=>['required_parts_count'],'dimensions'=>[],'filters'=>['offering_ids'=>[1]],'page'=>1,'per_page'=>1])->assertOk();
        $page2=$this->postJson('/api/v1/vice-presidency/reports/query',['subject'=>'grade_workflow','mode'=>'details','metrics'=>['required_parts_count'],'dimensions'=>[],'filters'=>['offering_ids'=>[1]],'page'=>2,'per_page'=>1])->assertOk();
        self::assertSame(2,$page1->json('data.pagination.total'));
        self::assertEqualsCanonicalizing(['practical','theoretical'],[$page1->json('data.rows.0.component_type'),$page2->json('data.rows.0.component_type')]);
        self::assertSame(1,$page1->json('data.rows.0.course_offering_id'));
        self::assertSame(1,$page2->json('data.rows.0.course_offering_id'));
    }

    public function test_http_grade_workflow_historical_dimension_uses_event_action(): void
    {
        DB::table('grade_components')->insert(['grade_component_id'=>1,'course_offering_id'=>1,'component_type'=>'theoretical','is_required'=>1]);
        DB::table('grade_part_approvals')->insert(['grade_part_approval_id'=>1,'course_offering_id'=>1,'component_type'=>'theoretical','status'=>'approved','submission_version'=>1,'created_at'=>'2025-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00']);
        DB::table('grade_part_approval_events')->insert(['grade_part_approval_event_id'=>1,'grade_part_approval_id'=>1,'submission_version'=>1,'action'=>'submitted','performed_at'=>'2026-06-01 00:00:00']);
        Sanctum::actingAs($this->actor(32,'vice_president_scientific','vice_presidency.scientific.access',true));
        $response=$this->postJson('/api/v1/vice-presidency/reports/query',['subject'=>'grade_workflow','mode'=>'summary','metrics'=>['submitted_parts_count'],'dimensions'=>['grade_workflow_status'],'period'=>['type'=>'date_range','date_from'=>'2026-01-01','date_to'=>'2026-12-31'],'page'=>1,'per_page'=>25])->assertOk();
        self::assertSame('submitted',$response->json('data.series.0.grade_workflow_status'));
        self::assertSame(1,$response->json('data.series.0.submitted_parts_count'));
    }

    public function test_official_metrics_use_latest_approval_and_canonical_repeated_attempt_gpa(): void
    {
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_result_count','official_average','official_gpa','pass_rate'],'dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(3.0,$data['summary']['official_result_count']);
        self::assertSame(3.5,$data['summary']['official_gpa']['value']);
        self::assertSame(2,$data['summary']['official_gpa']['contributing_students']);
        self::assertSame(3,$data['summary']['pass_rate']['denominator']);
        self::assertSame(2,$data['summary']['pass_rate']['numerator']);
        self::assertSame(3.0,app(GradeService::class)->calculateCgpa(Student::query()->findOrFail(1))['cgpa']);
        self::assertSame(4.0,app(GradeService::class)->calculateCgpa(Student::query()->findOrFail(2))['cgpa']);
        self::assertArrayHasKey('generated_at',$data);
    }

    public function test_failed_high_mark_is_zero_points_and_missing_required_totals_are_excluded_from_gpa(): void
    {
        DB::table('student_course_results')->where('student_course_result_id',1)->update(['final_mark'=>80,'theoretical_total'=>80]);
        $failed=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>[],'filters'=>['offering_ids'=>[1]],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(0.0,$failed['summary']['official_gpa']['value']);
        DB::table('student_course_results')->where('student_course_result_id',1)->update(['theoretical_total'=>null]);
        $missing=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>[],'filters'=>['offering_ids'=>[1]],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertNull($missing['summary']['official_gpa']['value']);
        self::assertSame(0,$missing['summary']['official_gpa']['contributing_students']);
    }

    public function test_student_academic_membership_does_not_require_an_official_result(): void
    {
        DB::table('students')->insert(['student_id'=>3,'student_number'=>'S3','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>4,'student_id'=>3,'course_offering_id'=>1,'registration_status_id'=>1]);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'students','mode'=>'summary','metrics'=>['student_count','official_result_count'],'dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(3.0,$data['summary']['student_count']);
        self::assertSame(3.0,$data['summary']['official_result_count']);
    }

    public function test_new_student_count_uses_enrollment_date_and_does_not_duplicate_population_count(): void
    {
        DB::table('students')->where('student_id',1)->update(['enrollment_date'=>'2026-02-01']);
        DB::table('students')->where('student_id',2)->update(['enrollment_date'=>'2025-02-01']);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'students','mode'=>'summary','metrics'=>['student_count','new_student_count'],'dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(2.0,$data['summary']['student_count']);
        self::assertSame(1.0,$data['summary']['new_student_count']);
    }

    public function test_component_visibility_matches_grade_service_for_theory_practical_and_mixed_courses(): void
    {
        $report=fn()=>app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>[],'filters'=>['offering_ids'=>[3]],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25]);
        self::assertSame(4.0,$report()['summary']['official_gpa']['value']);

        DB::table('courses')->where('course_id',1)->update(['theoretical_hours'=>0,'practical_hours'=>2]);
        DB::table('student_course_results')->where('student_course_result_id',3)->update(['theoretical_total'=>null,'practical_total'=>100]);
        self::assertSame(4.0,$report()['summary']['official_gpa']['value']);

        DB::table('courses')->where('course_id',1)->update(['theoretical_hours'=>2,'practical_hours'=>2]);
        self::assertNull($report()['summary']['official_gpa']['value']);
        DB::table('student_course_results')->where('student_course_result_id',3)->update(['theoretical_total'=>100]);
        self::assertSame(4.0,$report()['summary']['official_gpa']['value']);
        self::assertSame(4.0,app(GradeService::class)->calculateCgpa(Student::query()->findOrFail(2))['cgpa']);
    }

    public function test_offering_details_apply_date_range_and_requested_sort_with_stable_tie_breaker(): void
    {
        DB::table('course_offerings')->where('course_offering_id',1)->update(['created_at'=>'2025-01-01 00:00:00']);
        DB::table('course_offerings')->whereIn('course_offering_id',[2,3])->update(['created_at'=>'2026-05-01 00:00:00']);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'course_offerings','mode'=>'details','metrics'=>['course_offering_count'],'dimensions'=>[],'period'=>['type'=>'date_range','date_from'=>'2026-01-01','date_to'=>'2026-12-31'],'sort'=>['field'=>'course_offering_count','direction'=>'desc'],'page'=>1,'per_page'=>1]);
        self::assertSame(2,$data['pagination']['total']);
        self::assertSame(3,$data['rows'][0]['course_offering_id']);
    }

    public function test_grouped_gpa_selects_the_best_attempt_inside_each_group_while_summary_deduplicates_independently(): void
    {
        DB::table('academic_years')->insert(['academic_year_id'=>2,'start_date'=>'2027-01-01','end_date'=>'2027-12-31','is_current'=>0,'is_active'=>1,'calendar_lifecycle_status'=>'active']);
        DB::table('course_offerings')->insert(['course_offering_id'=>4,'course_id'=>1,'academic_year_id'=>2,'semester_id'=>1,'academic_program_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>4,'student_id'=>1,'course_offering_id'=>4,'registration_status_id'=>1]);
        DB::table('student_course_results')->insert(['student_course_result_id'=>4,'student_course_registration_id'=>4,'theoretical_total'=>80,'final_mark'=>80,'result_status_id'=>2]);
        DB::table('grade_approvals')->insert(['grade_approval_id'=>5,'course_offering_id'=>4,'approval_status_id'=>1]);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>['academic_year'],'period'=>['type'=>'academic','academic_year_ids'=>[1,2]],'page'=>1,'per_page'=>25]);
        $groups=collect($data['series'])->keyBy('academic_year');
        self::assertSame(3.5,$data['summary']['official_gpa']['value']);
        self::assertSame(3.5,$groups[1]['official_gpa']['value']);
        self::assertSame(0.0,$groups[2]['official_gpa']['value']);
    }

    public function test_http_gpa_uses_fractional_division_for_summary_group_and_student_detail(): void
    {
        DB::table('academic_programs')->insert(['academic_program_id'=>2,'department_id'=>1,'program_name'=>'Program 2']);
        DB::table('students')->insert(['student_id'=>20,'student_number'=>'S20','academic_program_id'=>2,'current_academic_level_id'=>1,'student_status_id'=>1]);
        DB::table('courses')->insert([
            ['course_id'=>20,'course_code'=>'GPA-A','course_name'=>'GPA Course A','credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>0],
            ['course_id'=>21,'course_code'=>'GPA-B','course_name'=>'GPA Course B','credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>0],
        ]);
        DB::table('course_offerings')->insert([
            ['course_offering_id'=>20,'course_id'=>20,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>2],
            ['course_offering_id'=>21,'course_id'=>21,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>2],
        ]);
        DB::table('student_course_registrations')->insert([
            ['student_course_registration_id'=>20,'student_id'=>20,'course_offering_id'=>20,'registration_status_id'=>1],
            ['student_course_registration_id'=>21,'student_id'=>20,'course_offering_id'=>21,'registration_status_id'=>1],
        ]);
        DB::table('student_course_results')->insert([
            ['student_course_result_id'=>20,'student_course_registration_id'=>20,'theoretical_total'=>80,'final_mark'=>80,'result_status_id'=>1],
            ['student_course_result_id'=>21,'student_course_registration_id'=>21,'theoretical_total'=>100,'final_mark'=>100,'result_status_id'=>1],
        ]);
        DB::table('grade_approvals')->insert([
            ['grade_approval_id'=>20,'course_offering_id'=>20,'approval_status_id'=>1],
            ['grade_approval_id'=>21,'course_offering_id'=>21,'approval_status_id'=>1],
        ]);
        Sanctum::actingAs($this->actor(33,'vice_president_scientific','vice_presidency.scientific.access',true));

        $performance=$this->postJson('/api/v1/vice-presidency/reports/query',[
            'subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>['academic_year'],
            'filters'=>['program_ids'=>[2]],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25,
        ])->assertOk();
        $details=$this->postJson('/api/v1/vice-presidency/reports/query',[
            'subject'=>'students','mode'=>'details','metrics'=>['official_gpa'],'dimensions'=>[],
            'filters'=>['program_ids'=>[2]],'period'=>['type'=>'academic','academic_year_ids'=>[1]],'page'=>1,'per_page'=>25,
        ])->assertOk();
        $canonical=app(GradeService::class)->calculateCgpa(Student::query()->findOrFail(20))['cgpa'];

        self::assertEquals(3.5,$canonical);
        self::assertEquals($canonical,$performance->json('data.summary.official_gpa.value'));
        self::assertEquals($canonical,$performance->json('data.series.0.official_gpa.value'));
        self::assertEquals($canonical,$details->json('data.rows.0.official_gpa.value'));
        self::assertSame(1,$performance->json('data.summary.official_gpa.contributing_students'));
    }

    public function test_previous_academic_year_replaces_current_period_filters_and_returns_resolved_group_output(): void
    {
        DB::table('academic_years')->insert(['academic_year_id'=>2,'start_date'=>'2027-01-01','end_date'=>'2027-12-31','is_current'=>0,'is_active'=>1,'calendar_lifecycle_status'=>'active']);
        DB::table('course_offerings')->insert(['course_offering_id'=>4,'course_id'=>1,'academic_year_id'=>2,'semester_id'=>1,'academic_program_id'=>1]);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'course_offerings','mode'=>'comparison','metrics'=>['course_offering_count'],'dimensions'=>['academic_year'],'filters'=>['academic_year_ids'=>[2]],'period'=>['type'=>'academic','academic_year_ids'=>[2]],'comparison'=>['type'=>'previous_academic_year'],'page'=>1,'per_page'=>25]);
        self::assertTrue($data['comparison']['available']);
        self::assertSame([1],$data['comparison']['period']['academic_year_ids']);
        self::assertSame([],$data['comparison']['scope']['academic_year_ids']??[]);
        self::assertSame(3.0,$data['comparison']['summary']['course_offering_count']);
        self::assertNotEmpty($data['comparison']['series']);
    }

    public function test_empty_rates_expose_zero_denominator_and_reports_are_read_only_and_pii_free(): void
    {
        $before=['students'=>DB::table('students')->count(),'results'=>DB::table('student_course_results')->count(),'approvals'=>DB::table('grade_approvals')->count()];
        DB::table('academic_years')->insert(['academic_year_id'=>2,'start_date'=>'2027-01-01','end_date'=>'2027-12-31','is_current'=>0,'is_active'=>1,'calendar_lifecycle_status'=>'active']);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'details','metrics'=>['pass_rate'],'dimensions'=>[],'period'=>['type'=>'academic','academic_year_ids'=>[2]],'page'=>1,'per_page'=>25]);
        self::assertSame(['numerator'=>0,'denominator'=>0,'value'=>null,'reason'=>'zero_denominator'],$data['summary']['pass_rate']);
        self::assertSame($before,['students'=>DB::table('students')->count(),'results'=>DB::table('student_course_results')->count(),'approvals'=>DB::table('grade_approvals')->count()]);
        $serialized=json_encode($data,JSON_THROW_ON_ERROR);
        foreach(['email','phone','birth','address','password','token','component_mark']as$forbidden)self::assertStringNotContainsString($forbidden,$serialized);
    }

    public function test_grade_workflow_history_uses_append_only_event_time_not_mutable_approval_timestamps(): void
    {
        DB::table('grade_part_approvals')->insert(['grade_part_approval_id'=>1,'course_offering_id'=>1,'component_type'=>'theoretical','status'=>'approved','submission_version'=>1,'submitted_at'=>'2026-06-01 00:00:00','reviewed_at'=>'2026-06-02 00:00:00','created_at'=>'2026-06-01 00:00:00','updated_at'=>'2026-06-02 00:00:00']);
        DB::table('grade_part_approval_events')->insert([['grade_part_approval_event_id'=>1,'grade_part_approval_id'=>1,'submission_version'=>1,'action'=>'approved','performed_at'=>'2025-06-02 00:00:00'],['grade_part_approval_event_id'=>2,'grade_part_approval_id'=>1,'submission_version'=>1,'action'=>'submitted','performed_at'=>'2026-06-01 00:00:00']]);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'grade_workflow','mode'=>'summary','metrics'=>['submitted_parts_count','approved_parts_count'],'dimensions'=>[],'period'=>['type'=>'date_range','date_from'=>'2026-01-01','date_to'=>'2026-12-31'],'page'=>1,'per_page'=>25]);
        self::assertSame(1.0,$data['summary']['submitted_parts_count']);
        self::assertSame(0.0,$data['summary']['approved_parts_count']);
    }

    public function test_unsupported_historical_snapshot_metrics_return_explicit_unavailable_data(): void
    {
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'faculty','mode'=>'summary','metrics'=>['faculty_count'],'dimensions'=>[],'period'=>['type'=>'date_range','date_from'=>'2026-01-01','date_to'=>'2026-12-31'],'page'=>1,'per_page'=>25]);
        self::assertFalse($data['history_availability']['available']);
        self::assertSame('historical_data_unavailable',$data['history_availability']['reason']);
        self::assertSame([],$data['summary']);
        self::assertSame([],$data['rows']);
    }

    public function test_custom_comparison_baseline_cannot_bypass_historical_capabilities(): void
    {
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'faculty','mode'=>'comparison','metrics'=>['faculty_count'],'dimensions'=>[],'comparison'=>['type'=>'custom','baseline'=>['period'=>['type'=>'date_range','date_from'=>'2025-01-01','date_to'=>'2025-12-31']]],'page'=>1,'per_page'=>25]);
        self::assertFalse($data['comparison']['available']);
        self::assertSame('historical_data_unavailable',$data['comparison']['reason']);
    }

    public function test_faculty_summary_deduplicates_multi_college_members_and_keeps_unassigned_faculty(): void
    {
        DB::table('organizational_units')->insert([['organizational_unit_id'=>10,'unit_code'=>'C1','is_active'=>1],['organizational_unit_id'=>20,'unit_code'=>'C2','is_active'=>1]]);
        DB::table('colleges')->where('college_id',1)->update(['organizational_unit_id'=>10]);
        DB::table('colleges')->insert(['college_id'=>2,'college_name'=>'College 2','organizational_unit_id'=>20]);
        DB::table('employees')->insert([['employee_id'=>1,'employee_number'=>'E1','organizational_unit_id'=>10],['employee_id'=>2,'employee_number'=>'E2','organizational_unit_id'=>null],['employee_id'=>3,'employee_number'=>'E3','organizational_unit_id'=>10]]);
        DB::table('faculty_members')->insert([['faculty_member_id'=>1,'employee_id'=>1,'is_active'=>1],['faculty_member_id'=>2,'employee_id'=>2,'is_active'=>1],['faculty_member_id'=>3,'employee_id'=>3,'is_active'=>1]]);
        DB::table('employee_unit_assignments')->insert(['employee_id'=>3,'organizational_unit_id'=>20,'is_active'=>1]);
        DB::table('course_offering_instructors')->insert(['course_offering_id'=>1,'faculty_member_id'=>1,'is_active'=>1]);
        $data=app(ExecutiveReportQueryService::class)->run(['subject'=>'faculty','mode'=>'summary','metrics'=>['faculty_count','assigned_sections_count'],'dimensions'=>['college'],'page'=>1,'per_page'=>25]);
        self::assertSame(3.0,$data['summary']['faculty_count']);
        self::assertSame(1.0,$data['summary']['assigned_sections_count']);
        self::assertCount(3,$data['series']);
    }

    public function test_grouped_gpa_query_count_is_bounded_when_group_count_grows(): void
    {
        $run=function():int{DB::flushQueryLog();DB::enableQueryLog();app(ExecutiveReportQueryService::class)->run(['subject'=>'academic_performance','mode'=>'summary','metrics'=>['official_gpa'],'dimensions'=>['academic_year'],'page'=>1,'per_page'=>25]);$count=count(DB::getQueryLog());DB::disableQueryLog();return$count;};
        $oneGroup=$run();
        DB::table('academic_years')->insert(['academic_year_id'=>2,'start_date'=>'2027-01-01','end_date'=>'2027-12-31','is_current'=>0,'is_active'=>1,'calendar_lifecycle_status'=>'active']);
        DB::table('course_offerings')->insert(['course_offering_id'=>4,'course_id'=>1,'academic_year_id'=>2,'semester_id'=>1,'academic_program_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>4,'student_id'=>1,'course_offering_id'=>4,'registration_status_id'=>1]);
        DB::table('student_course_results')->insert(['student_course_result_id'=>4,'student_course_registration_id'=>4,'theoretical_total'=>90,'final_mark'=>90,'result_status_id'=>1]);
        DB::table('grade_approvals')->insert(['grade_approval_id'=>5,'course_offering_id'=>4,'approval_status_id'=>1]);
        $twoGroups=$run();
        self::assertSame($oneGroup,$twoGroups);
    }

    public function test_http_rate_sort_uses_ratio_then_all_group_keys_for_deterministic_ties(): void
    {
        $this->seedPerformanceCohort(10,10,100,50);
        $this->seedPerformanceCohort(11,11,10,9);
        $this->seedPerformanceCohort(12,12,20,18);
        Sanctum::actingAs($this->actor(40,'vice_president_scientific','vice_presidency.scientific.access',true));
        $payload=['subject'=>'academic_performance','mode'=>'summary','metrics'=>['pass_rate'],'dimensions'=>['academic_year','course'],'filters'=>['course_ids'=>[10,11,12]],'sort'=>['field'=>'pass_rate','direction'=>'desc'],'page'=>1,'per_page'=>25];
        $response=$this->postJson('/api/v1/vice-presidency/reports/query',$payload)->assertOk();
        $series=$response->json('data.series');
        self::assertSame([11,12,10],array_column($series,'course'));
        self::assertSame(9,$series[0]['pass_rate']['numerator']);
        self::assertSame(10,$series[0]['pass_rate']['denominator']);
        self::assertEquals(90.0,$series[0]['pass_rate']['value']);
        self::assertSame(18,$series[1]['pass_rate']['numerator']);
        self::assertSame(20,$series[1]['pass_rate']['denominator']);
        self::assertEquals(90.0,$series[1]['pass_rate']['value']);
        self::assertSame(50,$series[2]['pass_rate']['numerator']);
        self::assertSame(100,$series[2]['pass_rate']['denominator']);
        self::assertEquals(50.0,$series[2]['pass_rate']['value']);

        $again=$this->postJson('/api/v1/vice-presidency/reports/query',array_replace($payload,['page'=>2,'per_page'=>1]))->assertOk();
        self::assertSame(12,$again->json('data.rows.0.course'));
    }

    public function test_http_ratio_sort_keeps_zero_denominator_null_and_faculty_detail_pages_stable(): void
    {
        DB::table('organizational_units')->insert(['organizational_unit_id'=>10,'unit_code'=>'C1','is_active'=>1]);
        DB::table('colleges')->where('college_id',1)->update(['organizational_unit_id'=>10]);
        DB::table('employees')->insert([['employee_id'=>1,'employee_number'=>'E1','organizational_unit_id'=>10],['employee_id'=>2,'employee_number'=>'E2','organizational_unit_id'=>null]]);
        DB::table('faculty_members')->insert([['faculty_member_id'=>1,'employee_id'=>1,'is_active'=>1],['faculty_member_id'=>2,'employee_id'=>2,'is_active'=>1]]);
        DB::table('course_offering_instructors')->insert(['course_offering_id'=>1,'faculty_member_id'=>1,'is_active'=>1]);
        Sanctum::actingAs($this->actor(41,'vice_president_administrative','vice_presidency.administrative.access',true));
        $ratio=$this->postJson('/api/v1/vice-presidency/reports/query',['subject'=>'faculty','mode'=>'summary','metrics'=>['students_per_assigned_faculty'],'dimensions'=>['faculty_member'],'sort'=>['field'=>'students_per_assigned_faculty','direction'=>'desc'],'page'=>1,'per_page'=>25])->assertOk();
        self::assertSame([1,2],array_column($ratio->json('data.series'),'faculty_member'));
        $series=collect($ratio->json('data.series'))->keyBy('faculty_member');
        self::assertSame('zero_denominator',$series[2]['students_per_assigned_faculty']['reason']);
        self::assertNull($series[2]['students_per_assigned_faculty']['value']);

        DB::table('organizational_units')->insert(['organizational_unit_id'=>20,'unit_code'=>'C2','is_active'=>1]);
        DB::table('colleges')->insert(['college_id'=>2,'college_name'=>'College 2','organizational_unit_id'=>20]);
        DB::table('employee_unit_assignments')->insert(['employee_id'=>1,'organizational_unit_id'=>20,'is_active'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>5,'student_id'=>2,'course_offering_id'=>1,'registration_status_id'=>1]);
        $details=['subject'=>'faculty','mode'=>'details','metrics'=>['faculty_count'],'dimensions'=>[],'sort'=>['field'=>'faculty_count','direction'=>'asc'],'page'=>1,'per_page'=>1];
        $first=$this->postJson('/api/v1/vice-presidency/reports/query',$details)->assertOk();
        $second=$this->postJson('/api/v1/vice-presidency/reports/query',array_replace($details,['page'=>2]))->assertOk();
        $third=$this->postJson('/api/v1/vice-presidency/reports/query',array_replace($details,['page'=>3]))->assertOk();
        $repeated=$this->postJson('/api/v1/vice-presidency/reports/query',$details)->assertOk();
        self::assertSame(3,$first->json('data.pagination.total'));
        $identities=collect([$first,$second,$third])->map(fn($response)=>[$response->json('data.rows.0.faculty_member_id'),$response->json('data.rows.0.college_id')])->all();
        self::assertSame([[1,1],[1,2],[2,null]],$identities);
        self::assertSame($identities[0],[$repeated->json('data.rows.0.faculty_member_id'),$repeated->json('data.rows.0.college_id')]);
    }

    private function seedFacts(): void
    {
        DB::table('colleges')->insert(['college_id'=>1]);DB::table('departments')->insert(['department_id'=>1,'college_id'=>1]);DB::table('academic_programs')->insert(['academic_program_id'=>1,'department_id'=>1]);DB::table('academic_levels')->insert(['academic_level_id'=>1]);
        DB::table('academic_years')->insert(['academic_year_id'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_current'=>1,'is_active'=>1,'calendar_lifecycle_status'=>'active']);DB::table('semesters')->insert(['semester_id'=>1,'semester_order'=>1]);
        DB::table('student_statuses')->insert(['student_status_id'=>1,'status_code'=>'active']);DB::table('students')->insert([['student_id'=>1,'student_number'=>'S1','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1],['student_id'=>2,'student_number'=>'S2','academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1]]);
        DB::table('courses')->insert(['course_id'=>1,'course_code'=>'C1','course_name'=>'Course','credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>0]);DB::table('course_offerings')->insert([['course_offering_id'=>1,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1],['course_offering_id'=>2,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1],['course_offering_id'=>3,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1]]);
        DB::table('registration_statuses')->insert([['registration_status_id'=>1,'status_code'=>'completed'],['registration_status_id'=>2,'status_code'=>'withdrawn']]);DB::table('result_statuses')->insert([['result_status_id'=>1,'status_code'=>'passed'],['result_status_id'=>2,'status_code'=>'failed']]);DB::table('approval_statuses')->insert([['approval_status_id'=>1,'status_code'=>'approved'],['approval_status_id'=>2,'status_code'=>'pending']]);
        DB::table('student_course_registrations')->insert([['student_course_registration_id'=>1,'student_id'=>1,'course_offering_id'=>1,'registration_status_id'=>1],['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1],['student_course_registration_id'=>3,'student_id'=>2,'course_offering_id'=>3,'registration_status_id'=>1]]);
        DB::table('student_course_results')->insert([['student_course_result_id'=>1,'student_course_registration_id'=>1,'theoretical_total'=>50,'final_mark'=>50,'result_status_id'=>2],['student_course_result_id'=>2,'student_course_registration_id'=>2,'theoretical_total'=>80,'final_mark'=>80,'result_status_id'=>1],['student_course_result_id'=>3,'student_course_registration_id'=>3,'theoretical_total'=>100,'final_mark'=>100,'result_status_id'=>1]]);
        DB::table('grade_approvals')->insert([['grade_approval_id'=>1,'course_offering_id'=>1,'approval_status_id'=>1],['grade_approval_id'=>2,'course_offering_id'=>2,'approval_status_id'=>2],['grade_approval_id'=>3,'course_offering_id'=>2,'approval_status_id'=>1],['grade_approval_id'=>4,'course_offering_id'=>3,'approval_status_id'=>1]]);
    }

    private function seedPerformanceCohort(int $courseId,int $offeringId,int $total,int $passed): void
    {
        DB::table('courses')->insert(['course_id'=>$courseId,'course_code'=>'C'.$courseId,'course_name'=>'Course '.$courseId,'credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>0]);
        DB::table('course_offerings')->insert(['course_offering_id'=>$offeringId,'course_id'=>$courseId,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1]);
        DB::table('grade_approvals')->insert(['grade_approval_id'=>100+$offeringId,'course_offering_id'=>$offeringId,'approval_status_id'=>1]);
        $students=[];$registrations=[];$results=[];
        for($i=1;$i<=$total;$i++){
            $id=$courseId*1000+$i;$registrationId=$courseId*1000+$i;$isPassed=$i<=$passed;
            $students[]=['student_id'=>$id,'student_number'=>'S'.$id,'academic_program_id'=>1,'current_academic_level_id'=>1,'student_status_id'=>1];
            $registrations[]=['student_course_registration_id'=>$registrationId,'student_id'=>$id,'course_offering_id'=>$offeringId,'registration_status_id'=>1];
            $results[]=['student_course_result_id'=>$registrationId,'student_course_registration_id'=>$registrationId,'theoretical_total'=>$isPassed?80:40,'final_mark'=>$isPassed?80:40,'result_status_id'=>$isPassed?1:2];
        }
        DB::table('students')->insert($students);DB::table('student_course_registrations')->insert($registrations);DB::table('student_course_results')->insert($results);
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
