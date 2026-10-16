<?php

namespace Tests\Feature;

use App\Models\{CourseIncompleteCase, Student, StudentCourseRegistration, User};
use App\Services\{GradeService, IncompleteResultService};
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseResultSymbolsFixture;
use Tests\TestCase;

/** Real middleware/routes/calculations/persistence. MariaDB has a separate multi-process harness. */
class CourseResultSymbolsBehaviorTest extends TestCase
{
    private const BASE = '/api/v1/exams/incomplete-results';
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::getDriverName());
        Schema::dropAllTables();
        CourseResultSymbolsFixture::installLegacy(); CourseResultSymbolsFixture::installLifecycle();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
        Sanctum::actingAs(User::findOrFail(1));
    }
    private function grant(string $outcome = 'F', array $extra = []): array
    {
        return $this->postJson(self::BASE.'/registrations/1', array_replace(['request_id'=>(string) Str::uuid(), 'expected_revision'=>0,
            'reason'=>'سبب اصطناعي موثق', 'requirements'=>'الامتحان النظري المتبقي', 'expiry_outcome'=>$outcome, 'deadline_at'=>'2026-10-17'], $extra))
            ->assertCreated()->json('data');
    }
    private function resolve(array $case, array $components)
    {
        return $this->postJson(self::BASE.'/'.$case['case']['incomplete_case_id'].'/resolve', ['request_id'=>(string) Str::uuid(),
            'expected_revision'=>$case['case']['revision'], 'components'=>$components]);
    }
    private function otherStudentMarks(): void
    {
        foreach ([1=>50,2=>30] as $id=>$mark) DB::table('student_grade_components')->insert(['student_course_registration_id'=>2,'grade_component_id'=>$id,'mark'=>$mark,'grade_status'=>'draft']);
    }
    private function finalizeOffering(): void
    {
        foreach (['theoretical','practical'] as $part) {
            $path = '/api/v1/exams/manual-grade-entry/students/2/registrations/2/parts/'.$part;
            $readiness = $this->getJson($path.'/submission-readiness')->assertOk()->json('data');
            self::assertTrue($readiness['can_submit'], json_encode($readiness));
            $this->postJson($path.'/submit', ['revision'=>$readiness['revision'],'confirmed'=>true])->assertOk();
            $id = DB::table('grade_part_approvals')->where('component_type',$part)->value('grade_part_approval_id');
            $this->postJson('/api/v1/grade-part-approvals/'.$id.'/approve')->assertOk();
        }
    }
    public function test_active_i_is_exempt_during_finalization_without_inventing_marks_or_publishing_early(): void
    {
        $this->otherStudentMarks(); $case=$this->grant();
        self::assertSame(0,app(GradeService::class)->officialAcademicAttempts(Student::findOrFail(1))->count());
        $before=(array)DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        $this->finalizeOffering();
        self::assertEquals($before,(array)DB::table('student_course_results')->where('student_course_registration_id',1)->first());
        self::assertSame(0,DB::table('student_grade_components')->where('student_course_registration_id',1)->count());
        self::assertSame('passed',DB::table('student_course_results as r')->join('result_statuses as s','s.result_status_id','=','r.result_status_id')->where('r.student_course_registration_id',2)->value('s.status_code'));
        self::assertNull(app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['cgpa']);
        $course=app(GradeService::class)->getTranscript(Student::findOrFail(1))['terms'][0]['courses'][0];
        self::assertSame('I',$course['letter_grade']); self::assertNull($course['final_mark']); self::assertNull($course['grade_points']);
        self::assertSame($case['case']['granted_at'],CourseIncompleteCase::first()->granted_at->toISOString());
        $this->assertOrdinaryWritesLocked();
    }
    public function test_expired_f_with_missing_marks_finalizes_others_and_enters_official_gpa_denominator(): void
    {
        $this->otherStudentMarks(); $case=$this->grant(); $this->travelTo(CarbonImmutable::parse('2026-10-18','UTC'));
        self::assertTrue(app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']));
        self::assertFalse(app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']));
        $before=(array)DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        self::assertNull($before['final_mark']); self::assertNull($before['theoretical_total']);
        self::assertSame(0,app(GradeService::class)->officialAcademicAttempts(Student::findOrFail(1))->count());
        $this->finalizeOffering();
        self::assertEquals($before,(array)DB::table('student_course_results')->where('student_course_registration_id',1)->first());
        $gpa=app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1);
        self::assertSame(0.0,$gpa['gpa']); self::assertSame(3,$gpa['total_included_credit_hours']);
        self::assertSame(0,DB::table('student_grade_components')->where('student_course_registration_id',1)->count());
        self::assertSame(2,DB::table('student_course_incomplete_events')->count());
        $event=DB::table('student_course_incomplete_events')->where('action','expired')->first();
        self::assertNull($event->actor_user_id); self::assertSame('system',$event->actor_kind);
        $this->postJson('/api/v1/registrations/1/calculate-result')->assertConflict();
    }
    public function test_expired_z_preserves_completed_work_announcement_and_cannot_become_passed(): void
    {
        $this->otherStudentMarks();
        DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>2,'mark'=>40,'grade_status'=>'approved']);
        $component=(array)DB::table('student_grade_components')->where('student_course_registration_id',1)->first();
        $case=$this->grant('Z');
        // Announcement was already present when the case was granted; never restart its appeal clock.
        $this->travelTo(CarbonImmutable::parse('2026-10-18','UTC'));
        app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']);
        $this->finalizeOffering();
        self::assertEquals($component,(array)DB::table('student_grade_components')->where('student_course_registration_id',1)->first());
        $r=DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        self::assertSame(3,(int)$r->result_status_id); self::assertSame(1,(int)$r->is_deprived); self::assertNull($r->final_mark);
        $this->assertOrdinaryWritesLocked();
    }
    private function assertOrdinaryWritesLocked(): void
    {
        $before=DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        foreach ([fn()=>app(GradeService::class)->calculateRegistrationResult(1,1),
            fn()=>app(GradeService::class)->updateRegistrationGrades(1,['theoretical_mark'=>60,'practical_mark'=>40],1),
            fn()=>\App\Models\StudentCourseResult::where('student_course_registration_id',1)->first()->update(['final_mark'=>100,'result_status_id'=>1])] as $write) {
            try { $write(); self::fail('Ordinary write replaced a lifecycle outcome.'); } catch (\App\Exceptions\GradeException $e) { self::assertSame(409,$e->status); }
        }
        $components=DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray();
        foreach(\App\Models\StudentGradeComponent::where('student_course_registration_id',1)->get() as $component){
            try{$component->update(['mark'=>0]);self::fail('Completed lifecycle work was changed by ordinary CRUD.');}catch(\App\Exceptions\GradeException $e){self::assertSame(409,$e->status);}
        }
        $snapshot=app(\App\Services\GradePartWorkflowService::class)->manualRegistration(StudentCourseRegistration::findOrFail(1));
        $this->putJson('/api/v1/exams/manual-grade-entry/students/1/registrations/1/marks',['revision'=>$snapshot['revision'],'acknowledged'=>true,
            'components'=>[['grade_component_id'=>1,'mark'=>60]]])->assertConflict();
        self::assertEquals($components,DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray());
        self::assertEquals($before,DB::table('student_course_results')->where('student_course_registration_id',1)->first());
    }
    public function test_resolution_of_completed_registration_preserves_practical_and_all_approval_records(): void
    {
        $this->otherStudentMarks();
        DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>2,'mark'=>30,'grade_status'=>'approved']);
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'result_status_id'=>4,'practical_total'=>30,'result_announced_at'=>'2026-10-01 10:00:00']);
        $case=$this->grant(); $this->finalizeOffering();
        DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['registration_status_id'=>4]);
        $approvals=DB::table('grade_approvals')->get()->toArray(); $parts=DB::table('grade_part_approvals')->get()->toArray();
        $other=DB::table('student_course_results')->where('student_course_registration_id',2)->first();
        $practical=DB::table('student_grade_components')->where('student_course_registration_id',1)->first();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>50]])->assertOk()->assertJsonPath('data.case.state','resolved');
        self::assertEquals($approvals,DB::table('grade_approvals')->get()->toArray()); self::assertEquals($parts,DB::table('grade_part_approvals')->get()->toArray());
        self::assertEquals($other,DB::table('student_course_results')->where('student_course_registration_id',2)->first());
        self::assertEquals($practical,DB::table('student_grade_components')->where('student_grade_component_id',$practical->student_grade_component_id)->first());
        self::assertSame('2026-10-01 10:00:00',DB::table('student_course_results')->where('student_course_registration_id',1)->value('result_announced_at'));
        self::assertSame(3.0,app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['cgpa']); $this->assertOrdinaryWritesLocked();
    }
    public function test_resolved_result_is_preserved_by_later_finalization_and_failure_rolls_back_all_new_marks(): void
    {
        $this->otherStudentMarks(); $case=$this->grant();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>50]])->assertConflict();
        self::assertSame(0,DB::table('student_grade_components')->where('student_course_registration_id',1)->count()); self::assertSame(0,DB::table('grade_audit_logs')->count());
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>10],['grade_component_id'=>2,'mark'=>30]])->assertOk();
        $before=DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        self::assertSame(2,(int)$before->result_status_id);
        $this->finalizeOffering(); self::assertEquals($before,DB::table('student_course_results')->where('student_course_registration_id',1)->first());
        $this->assertOrdinaryWritesLocked();
    }
    public function test_deadline_updates_keep_original_grant_and_replay_precedes_stale_revision_check(): void
    {
        $input=['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F','deadline_at'=>'2026-10-17'];
        $first=$this->postJson(self::BASE.'/registrations/1',$input)->assertCreated()->json('data');
        $id=$first['case']['incomplete_case_id'];
        $this->patchJson(self::BASE.'/'.$id.'/deadline',['request_id'=>(string)Str::uuid(),'expected_revision'=>1,'deadline_at'=>'2026-10-25'])->assertOk();
        $this->postJson(self::BASE.'/registrations/1',$input)->assertCreated()->assertJsonPath('data.case.revision',1);
        $this->postJson(self::BASE.'/registrations/1',array_replace($input,['reason'=>'مختلف']))->assertConflict();
        self::assertSame($first['case']['granted_at'],CourseIncompleteCase::first()->granted_at->toISOString());
        self::assertSame(2,DB::table('student_course_incomplete_events')->count());
        $this->patchJson(self::BASE.'/'.$id.'/deadline',['request_id'=>(string)Str::uuid(),'expected_revision'=>1,'deadline_at'=>'2026-10-26'])->assertConflict();
    }
    public function test_deadline_enforced_before_command_and_explicit_extension_allows_resolution_but_terminal_cannot_reopen(): void
    {
        $case=$this->grant(); $this->travelTo(CarbonImmutable::parse('2026-10-18','UTC'));
        $marks=[['grade_component_id'=>1,'mark'=>50],['grade_component_id'=>2,'mark'=>30]];
        $this->resolve($case,$marks)->assertConflict()->assertJsonPath('error_code','incomplete_deadline_elapsed');
        $case=$this->patchJson(self::BASE.'/'.$case['case']['incomplete_case_id'].'/deadline',['request_id'=>(string)Str::uuid(),'expected_revision'=>1,'deadline_at'=>'2026-10-20'])->assertOk()->json('data');
        $this->resolve($case,$marks)->assertOk();
        $this->patchJson(self::BASE.'/'.$case['case']['incomplete_case_id'].'/deadline',['request_id'=>(string)Str::uuid(),'expected_revision'=>3,'deadline_at'=>'2026-10-21'])->assertConflict();
    }
    public function test_auth_scope_strict_payloads_and_no_writes_on_get(): void
    {
        DB::enableQueryLog(); $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.state','no_result');
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)/i',$q['query']); DB::disableQueryLog();
        $this->postJson(self::BASE.'/registrations/1',['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'untrusted'=>true])->assertUnprocessable();
    }
    public function test_http_authority_inactive_scope_and_super_admin_and_delegated_office(): void
    {
        foreach (['doctor_instructor','student','registration_officer'] as $role) {
            DB::table('roles')->where('role_id',1)->update(['role_code'=>$role]); Sanctum::actingAs(User::findOrFail(1));
            $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
            $this->postJson(self::BASE.'/registrations/1',['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F','deadline_at'=>'2026-10-20'])->assertForbidden();
        }
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'exam_officer']);
        DB::table('account_statuses')->where('account_status_id',1)->update(['status_code'=>'inactive']); Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
        DB::table('account_statuses')->where('account_status_id',1)->update(['status_code'=>'active']);
        DB::table('role_permissions')->where('permission_id',2)->delete(); Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>2]);
        DB::table('user_access_scopes')->delete(); Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'super_admin']); Sanctum::actingAs(User::findOrFail(1));
        $response=$this->getJson(self::BASE.'/registrations/1'); self::assertSame(200,$response->status(),'super_admin: '.$response->content());
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'vice_president_administrative']);
        DB::table('user_access_scopes')->insert(['user_id'=>1,'scope_type'=>'university','scope_id'=>1,'is_active'=>1]); Sanctum::actingAs(User::findOrFail(1));
        $response=$this->getJson(self::BASE.'/registrations/1'); self::assertSame(200,$response->status(),'delegated VP: '.$response->content());
        self::assertSame(0,DB::table('student_course_incomplete_cases')->count());
    }
    public function test_offering_scope_is_independent_of_current_student_program_and_replay_is_authorized(): void
    {
        DB::table('departments')->insert(['department_id'=>2,'college_id'=>2]);
        DB::table('colleges')->insert(['college_id'=>2,'college_name'=>'Other']);
        DB::table('academic_programs')->insert(['academic_program_id'=>2,'department_id'=>2]);
        DB::table('user_access_scopes')->update(['scope_type'=>'college','scope_id'=>1]);
        DB::table('course_offerings')->where('course_offering_id',1)->update(['academic_program_id'=>2,'department_id'=>2]);
        $this->getJson(self::BASE.'/registrations/1')->assertForbidden();
        DB::table('user_access_scopes')->update(['scope_type'=>'university','scope_id'=>1]);
        $this->grant();
        DB::table('user_access_scopes')->delete(); Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::BASE.'/1')->assertForbidden(); $this->getJson(self::BASE)->assertOk()->assertJsonCount(0,'data.data');
    }
    public function test_no_result_is_not_a_granted_i_and_ordinary_entry_still_works(): void
    {
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.state','no_result')->assertJsonPath('data.allowed_operations.grant',true);
        $row=$this->getJson('/api/v1/exams/manual-grade-entry/students/1/registrations')->assertOk()->json('data.registrations.0');
        $this->putJson('/api/v1/exams/manual-grade-entry/students/1/registrations/1/marks',['revision'=>$row['revision'],'acknowledged'=>true,'components'=>[['grade_component_id'=>1,'mark'=>0]]])->assertOk();
        self::assertSame(0,DB::table('student_course_incomplete_cases')->count());
        self::assertSame(0,DB::table('student_course_results')->count());
    }
    public function test_b_plus_z_or_f_absent_marks_is_one_point_five_and_i_w_are_not_denominator_hours(): void
    {
        foreach (['failed','deprived','incomplete','withdrawn'] as $status) {
            DB::table('courses')->updateOrInsert(['course_id'=>2],['course_code'=>'B','course_name'=>'B course','credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>1]);
            DB::table('course_offerings')->updateOrInsert(['course_offering_id'=>2],['course_id'=>2,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1,'department_id'=>1]);
            DB::table('student_course_registrations')->updateOrInsert(['student_course_registration_id'=>3],['student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
            DB::table('student_course_results')->updateOrInsert(['student_course_registration_id'=>3],['result_status_id'=>1,'theoretical_total'=>50,'practical_total'=>30,'final_mark'=>80]);
            $id=['failed'=>2,'deprived'=>3,'incomplete'=>4,'withdrawn'=>4][$status];
            DB::table('student_course_results')->updateOrInsert(['student_course_registration_id'=>1],['result_status_id'=>$id,'theoretical_total'=>null,'practical_total'=>null,'final_mark'=>null,'is_deprived'=>$status==='deprived']);
            DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['registration_status_id'=>$status==='withdrawn'?5:1]);
            foreach ([1,2] as $offering) DB::table('grade_approvals')->updateOrInsert(['course_offering_id'=>$offering],['approval_status_id'=>1]);
            $gpa=app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1);
            self::assertSame(in_array($status,['failed','deprived'],true)?1.5:3.0,$gpa['gpa'],$status);
            self::assertSame(in_array($status,['failed','deprived'],true)?6:3,$gpa['total_included_credit_hours']);
        }
    }
    public function test_best_attempt_keeps_failed_history_across_later_w_i_and_passing_retake(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1,'department_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>3,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        foreach ([1,2] as $offering) DB::table('grade_approvals')->insert(['course_offering_id'=>$offering,'approval_status_id'=>1]);
        foreach ([2,3] as $failure) {
            DB::table('student_course_results')->updateOrInsert(['student_course_registration_id'=>1],['result_status_id'=>$failure,'final_mark'=>null,'is_deprived'=>$failure===3]);
            foreach ([4,5] as $later) {
                DB::table('student_course_registrations')->where('student_course_registration_id',3)->update(['registration_status_id'=>$later===5?5:1]);
                DB::table('student_course_results')->updateOrInsert(['student_course_registration_id'=>3],['result_status_id'=>4,'final_mark'=>null]);
                $gpa=app(GradeService::class)->calculateCgpa(Student::findOrFail(1)); self::assertSame(0.0,$gpa['cgpa']); self::assertSame(3,$gpa['total_included_credit_hours']);
            }
            DB::table('student_course_registrations')->where('student_course_registration_id',3)->update(['registration_status_id'=>1]);
            DB::table('student_course_results')->where('student_course_registration_id',3)->update(['result_status_id'=>1,'theoretical_total'=>35,'practical_total'=>30,'final_mark'=>65]);
            self::assertSame(2.25,app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['cgpa']);
            self::assertSame(2,DB::table('student_course_results')->count());
        }
    }
    public function test_calendar_published_non_enforcement_summer_snapshot_and_manual_fallback(): void
    {
        $this->calendar();
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.deadline_suggestion.deadline_at','2026-11-21T20:59:59+00:00');
        $input=['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F'];
        $case=$this->postJson(self::BASE.'/registrations/1',$input)->assertCreated()->json('data.case');
        self::assertSame('calendar',$case['deadline_source']);
        DB::table('academic_calendar_event_versions')->update(['starts_at'=>'2026-12-01 06:00:00']);
        self::assertSame('2026-11-21T20:59:59.000000Z',CourseIncompleteCase::first()->deadline_at->toISOString());
        DB::table('course_offerings')->where('course_offering_id',1)->update(['semester_id'=>2]);
        DB::table('academic_calendar_events')->update(['semester_id'=>3]);
        $suggestion=app(\App\Services\IncompleteDeadlinePolicyService::class)->suggest(StudentCourseRegistration::findOrFail(1));
        self::assertTrue($suggestion['available']); self::assertSame(3,$suggestion['calendar_snapshot']['semester_id']);
        DB::table('academic_calendar_events')->update(['cancelled_at'=>'2026-10-15 00:00:00']);
        self::assertFalse(app(\App\Services\IncompleteDeadlinePolicyService::class)->suggest(StudentCourseRegistration::findOrFail(1))['available']);
    }
    private function calendar(): void
    {
        DB::table('semesters')->insert([['semester_id'=>2,'semester_name'=>'Second','semester_order'=>2],['semester_id'=>3,'semester_name'=>'Summer','semester_order'=>3]]);
        Schema::create('academic_calendar_event_types',fn(Blueprint $t)=>[$t->integer('academic_calendar_event_type_id')->primary(),$t->string('event_type_code'),$t->boolean('is_active')]);
        Schema::create('academic_calendar_events',fn(Blueprint $t)=>[$t->integer('academic_calendar_event_id')->primary(),$t->integer('academic_calendar_event_type_id'),$t->integer('academic_year_id'),$t->integer('semester_id'),$t->dateTime('cancelled_at')->nullable()]);
        Schema::create('academic_calendar_event_versions',fn(Blueprint $t)=>[$t->integer('academic_calendar_event_version_id')->primary(),$t->integer('academic_calendar_event_id'),$t->integer('version_number'),$t->string('publication_status'),$t->dateTime('starts_at'),$t->dateTime('ends_at'),$t->boolean('is_enforcement'),$t->dateTime('published_at')->nullable(),$t->dateTime('superseded_at')->nullable()]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id'=>1,'event_type_code'=>'study_period','is_active'=>1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id'=>1,'academic_calendar_event_type_id'=>1,'academic_year_id'=>1,'semester_id'=>2]);
        DB::table('academic_calendar_event_versions')->insert(['academic_calendar_event_version_id'=>1,'academic_calendar_event_id'=>1,'version_number'=>1,'publication_status'=>'published','starts_at'=>'2026-11-01 06:00:00','ends_at'=>'2027-01-01 00:00:00','published_at'=>'2026-10-01 00:00:00','is_enforcement'=>0]);
    }
    public function test_deadline_inclusive_boundary_and_command_idempotency(): void
    {
        $case=$this->grant(); $this->travelTo(CarbonImmutable::parse($case['case']['deadline_at']));
        self::assertFalse(app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']));
        $this->travelTo(now()->addMicroseconds(500000));
        self::assertFalse(app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']));
        $this->travelTo(now()->addSecond());
        $this->artisan('course-results:expire-incomplete',['--batch'=>1])->assertSuccessful();
        $this->artisan('course-results:expire-incomplete',['--batch'=>1])->assertSuccessful();
        self::assertSame(2,DB::table('student_course_incomplete_events')->count());
    }
    public function test_single_required_part_resolution_has_no_assumed_sixty_forty_input(): void
    {
        DB::table('grade_components')->where('grade_component_id',2)->delete();
        $case=$this->grant(); $this->resolve($case,[['grade_component_id'=>1,'mark'=>50]])->assertOk();
        self::assertNull(DB::table('student_course_results')->value('practical_total'));
        self::assertSame(50.0,(float)DB::table('student_course_results')->value('final_mark'));
    }
    public function test_practical_only_and_multiple_components_use_actual_limits_and_preserve_existing_work(): void
    {
        DB::table('grade_components')->where('grade_component_id',1)->delete();
        DB::table('grade_components')->where('grade_component_id',2)->update(['max_mark'=>100]);
        DB::table('grading_policies')->where('grading_policy_id',1)->update(['practical_max_mark'=>100]);
        $case=$this->grant(); $this->resolve($case,[['grade_component_id'=>2,'mark'=>80]])->assertOk();
        $r=DB::table('student_course_results')->first(); self::assertNull($r->theoretical_total); self::assertSame(80.0,(float)$r->final_mark);
    }
    public function test_full_withdrawal_retains_term_and_has_no_gpa_or_earned_hours_and_reads_do_not_create_terms(): void
    {
        DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['registration_status_id'=>5]);
        $data=app(GradeService::class)->getTranscript(Student::findOrFail(1));
        self::assertCount(1,$data['terms']); self::assertSame('full_withdrawal',$data['terms'][0]['withdrawal_status']);
        self::assertTrue($data['terms'][0]['counts_towards_maximum_study_duration']);
        self::assertSame('W',$data['terms'][0]['courses'][0]['letter_grade']); self::assertNull($data['terms'][0]['courses'][0]['grade_points']);
        self::assertSame(0,$data['summary']['total_attempted_credit_hours']); self::assertSame(0,$data['summary']['total_passed_credit_hours']);
        self::assertSame(0,DB::table('student_course_results')->count());
    }
    public function test_numeric_average_excludes_legacy_i_zero_and_missing_marks(): void
    {
        DB::table('student_course_results')->insert([['student_course_registration_id'=>1,'result_status_id'=>4,'final_mark'=>0],['student_course_registration_id'=>2,'result_status_id'=>1,'final_mark'=>80]]);
        $data=app(GradeService::class)->getResultsSummary(1); self::assertSame(80.0,$data['average_final_mark']); self::assertSame(1,$data['incomplete_count']);
    }
    public function test_pending_registration_cannot_be_hidden_when_deriving_full_withdrawal(): void
    {
        DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['registration_status_id'=>5]);
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1,'department_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>3,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        $transcript=app(GradeService::class)->getTranscript(Student::findOrFail(1));
        self::assertSame('not_full_withdrawal',$transcript['terms'][0]['withdrawal_status']);
        DB::table('student_course_registrations')->where('student_course_registration_id',3)->update(['registration_status_id'=>5]);
        self::assertSame('full_withdrawal',app(GradeService::class)->getTranscript(Student::findOrFail(1))['terms'][0]['withdrawal_status']);
        self::assertSame(0,DB::table('student_course_results')->count());
    }
    public function test_readiness_missing_schema_and_missing_calendar_never_infer_a_grant(): void
    {
        $this->postJson(self::BASE.'/registrations/1',['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F'])->assertUnprocessable();
        self::assertSame(0,DB::table('student_course_results')->count());
        Schema::drop('student_course_incomplete_events');
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.schema_ready',false)->assertJsonPath('data.allowed_operations.grant',false);
        $this->postJson(self::BASE.'/registrations/1',['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F','deadline_at'=>'2026-10-20'])->assertServiceUnavailable();
    }
    public function test_malformed_repeated_component_ids_and_attempt_to_replace_final_result_are_rejected_without_writes(): void
    {
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'result_status_id'=>1,'final_mark'=>80]);
        $payload=['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F','deadline_at'=>'2026-10-20'];
        $this->postJson(self::BASE.'/registrations/1',$payload)->assertConflict();
        DB::table('student_course_results')->delete(); $case=$this->grant();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>50],['grade_component_id'=>'1','mark'=>50]])->assertUnprocessable();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>-1]])->assertUnprocessable();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>1.001]])->assertUnprocessable();
        $this->resolve($case,[['grade_component_id'=>100,'mark'=>50]])->assertUnprocessable();
        self::assertSame(0,DB::table('student_grade_components')->count()); self::assertSame(1,DB::table('student_course_incomplete_events')->count());
    }
    public function test_calendar_year_rollover_ambiguity_and_superseded_versions(): void
    {
        $this->calendar();
        DB::table('academic_years')->insert(['academic_year_id'=>7,'year_name'=>'2027','start_date'=>'2027-01-01','end_date'=>'2027-12-31']);
        DB::table('course_offerings')->where('course_offering_id',1)->update(['semester_id'=>3]);
        DB::table('academic_calendar_events')->update(['academic_year_id'=>7,'semester_id'=>1]);
        DB::table('academic_calendar_event_versions')->update(['starts_at'=>'2027-01-15 06:00:00','ends_at'=>'2027-05-01 00:00:00']);
        $deadline=app(\App\Services\IncompleteDeadlinePolicyService::class);
        self::assertSame('2027-02-04T20:59:59+00:00',$deadline->suggest(StudentCourseRegistration::findOrFail(1))['deadline_at']);
        DB::table('academic_calendar_event_versions')->update(['publication_status'=>'superseded']);
        self::assertFalse($deadline->suggest(StudentCourseRegistration::findOrFail(1))['available']);
        DB::table('academic_calendar_event_versions')->update(['publication_status'=>'published']);
        $copy=(array)DB::table('academic_calendar_event_versions')->first(); $copy['academic_calendar_event_version_id']=2;
        DB::table('academic_calendar_event_versions')->insert($copy);
        self::assertFalse($deadline->suggest(StudentCourseRegistration::findOrFail(1))['available']);
    }
    public function test_expiration_f_cannot_be_replaced_by_retained_passing_marks_during_finalization_or_legacy_writes(): void
    {
        $this->otherStudentMarks();
        foreach([1=>60,2=>40] as $component=>$mark) DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>$component,'mark'=>$mark,'grade_status'=>'approved']);
        $components=DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray();
        $case=$this->grant('F'); $this->travelTo(CarbonImmutable::parse('2026-10-18','UTC'));
        app(IncompleteResultService::class)->expire($case['case']['incomplete_case_id']);
        $before=DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        $this->finalizeOffering();
        self::assertEquals($before,DB::table('student_course_results')->where('student_course_registration_id',1)->first());
        self::assertEquals($components,DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray());
        self::assertSame(2,(int)$before->result_status_id); self::assertNull($before->final_mark);
        self::assertSame(0.0,app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['cgpa']);
        $this->assertOrdinaryWritesLocked();
    }
    public function test_multi_component_pass_settlement_is_not_recalculated_or_remarked_by_later_finalization(): void
    {
        DB::table('grade_components')->where('grade_component_id',1)->update(['max_mark'=>40]);
        DB::table('grade_components')->insert(['grade_component_id'=>3,'course_offering_id'=>1,'component_type'=>'theoretical','component_name'=>'Second theory','max_mark'=>20,'is_required'=>1]);
        foreach([1=>35,2=>30,3=>15] as $id=>$mark) DB::table('student_grade_components')->insert(['student_course_registration_id'=>2,'grade_component_id'=>$id,'mark'=>$mark,'grade_status'=>'draft']);
        DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>2,'mark'=>30,'grade_status'=>'approved']);
        $case=$this->grant(); $this->resolve($case,[['grade_component_id'=>1,'mark'=>35],['grade_component_id'=>3,'mark'=>15]])->assertOk();
        $result=DB::table('student_course_results')->where('student_course_registration_id',1)->first();
        $components=DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray();
        $this->finalizeOffering();
        self::assertEquals($result,DB::table('student_course_results')->where('student_course_registration_id',1)->first());
        self::assertEquals($components,DB::table('student_grade_components')->where('student_course_registration_id',1)->get()->toArray());
        self::assertSame(80.0,(float)$result->final_mark); self::assertSame(3.0,app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['cgpa']);
    }
    public function test_unauthenticated_requests_do_not_reach_lifecycle_reads_or_writes(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->postJson(self::BASE.'/registrations/1',[])->assertUnauthorized();
        self::assertSame(0,DB::table('student_course_incomplete_cases')->count());
    }
    public function test_conflicting_persisted_evidence_denies_finalization_without_repair_or_approval_writes(): void
    {
        $this->otherStudentMarks(); $this->grant();
        $approvalId=null;
        foreach (['theoretical','practical'] as $part) {
            $path='/api/v1/exams/manual-grade-entry/students/2/registrations/2/parts/'.$part;
            $readiness=$this->getJson($path.'/submission-readiness')->assertOk()->json('data');
            $this->postJson($path.'/submit',['revision'=>$readiness['revision'],'confirmed'=>true])->assertOk();
            $approvalId=DB::table('grade_part_approvals')->where('component_type',$part)->value('grade_part_approval_id');
            if($part==='theoretical')$this->postJson('/api/v1/grade-part-approvals/'.$approvalId.'/approve')->assertOk();
        }
        // Simulate inconsistent persisted evidence; an ordinary service must not "repair" it.
        DB::table('student_course_results')->where('student_course_registration_id',1)->update(['final_mark'=>100]);
        $before=[]; foreach(['grade_approvals','grade_part_approvals','grade_part_approval_events','student_course_results','student_grade_components','student_course_incomplete_events'] as $table)$before[$table]=DB::table($table)->get()->toArray();
        $this->getJson(self::BASE.'/registrations/1')->assertOk()->assertJsonPath('data.blocking_code','incomplete_outcome_conflict')->assertJsonPath('data.allowed_operations.resolve',false);
        $this->postJson('/api/v1/grade-part-approvals/'.$approvalId.'/approve')->assertConflict()->assertJsonPath('error_code','incomplete_outcome_conflict');
        foreach($before as $table=>$rows)self::assertEquals($rows,DB::table($table)->get()->toArray(),$table);
        $result=\App\Models\StudentCourseResult::where('student_course_registration_id',1)->first();
        try{$result->update(['student_course_registration_id'=>2]);self::fail('Result was detached from its owned attempt.');}catch(\App\Exceptions\GradeException $e){self::assertSame('incomplete_outcome_locked',$e->errorCode);}
    }
    public function test_explicit_supplementary_deferral_cannot_be_rewritten_as_i(): void
    {
        Schema::create('supplementary_exam_theoretical_deferrals',fn(Blueprint $t)=>[$t->integer('student_course_registration_id'),$t->integer('current_slot'),$t->string('status')]);
        DB::table('supplementary_exam_theoretical_deferrals')->insert(['student_course_registration_id'=>1,'current_slot'=>1,'status'=>'declared']);
        $input=['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'سبب','requirements'=>'متطلب','expiry_outcome'=>'F','deadline_at'=>'2026-10-20'];
        $this->postJson(self::BASE.'/registrations/1',$input)->assertConflict()->assertJsonPath('error_code','incomplete_supplementary_conflict');
        self::assertSame(0,DB::table('student_course_results')->count()); self::assertSame(0,DB::table('student_course_incomplete_cases')->count());
        self::assertSame('declared',DB::table('supplementary_exam_theoretical_deferrals')->value('status'));
    }
    public function test_frozen_term_and_previous_decisions_stay_historical_while_new_progression_uses_settled_gpa(): void
    {
        Schema::create('student_academic_terms',function(Blueprint $t):void{
            $t->increments('student_academic_term_id');
            foreach(['student_id','academic_year_id','semester_id','academic_level_id','total_registered_hours','attempted_hours','earned_hours','finalized_by_user_id'] as $field)$t->integer($field)->nullable();
            $t->decimal('term_gpa',3,2)->nullable(); $t->decimal('cumulative_gpa',3,2)->nullable();
            $t->boolean('is_finalized'); $t->dateTime('finalized_at')->nullable(); $t->timestamps();
        });
        foreach(['student_progression_decisions','student_progression_events','student_graduation_decisions','student_graduation_events'] as $table){
            Schema::create($table,fn(Blueprint $t)=>[$t->increments('id'),$t->integer('student_id'),$t->text('historical_evidence')]);
            DB::table($table)->insert(['student_id'=>1,'historical_evidence'=>'Original approved evidence, never recalculated']);
        }
        Schema::table('academic_levels',fn(Blueprint $t)=>[$t->integer('level_order')->default(1),$t->boolean('is_active')->default(true)]);
        Schema::table('program_courses',fn(Blueprint $t)=>$t->integer('academic_level_id')->nullable());
        $this->otherStudentMarks(); $case=$this->grant(); $this->finalizeOffering();
        DB::table('student_academic_terms')->insert(['student_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'academic_level_id'=>1,
            'term_gpa'=>null,'cumulative_gpa'=>null,'total_registered_hours'=>3,'attempted_hours'=>3,'earned_hours'=>0,'is_finalized'=>1,'finalized_at'=>'2026-10-16 12:00:00']);
        $before=[]; foreach(['student_academic_terms','student_progression_decisions','student_progression_events','student_graduation_decisions','student_graduation_events'] as $table)$before[$table]=DB::table($table)->get()->toArray();
        $this->resolve($case,[['grade_component_id'=>1,'mark'=>50],['grade_component_id'=>2,'mark'=>30]])->assertOk();
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'super_admin']); Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/academic-records/students/1/terms')->assertOk()->assertJsonPath('data.terms.0.term_gpa',null)
            ->assertJsonPath('data.terms.0.snapshot_is_historical',true)->assertJsonPath('data.terms.0.current_metrics.earned_hours',3)
            ->assertJsonPath('data.terms.0.current_metrics.term_gpa',3);
        $this->getJson('/api/v1/academic-progression/students/1/evaluate?academic_year_id=1')->assertOk()->assertJsonPath('data.term_gpa',3)->assertJsonPath('data.earned_hours',3);
        foreach($before as $table=>$rows)self::assertEquals($rows,DB::table($table)->get()->toArray(),$table);
    }
}
