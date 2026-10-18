<?php

namespace Tests\Feature;

use App\Models\{AcademicPlanVersion, Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, ScientificPlanChangeService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Real HTTP + SQLite. Not evidence of MariaDB row-lock concurrency. */
final class ScientificProgramWorkspaceTest extends TestCase
{
    private const API = '/api/v1/vice-presidency/scientific/program-management';
    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
    }
    private function payload(): array
    {
        $plan = app(\App\Services\AcademicPlanWorkflow::class)->currentPlanProjection(User::findOrFail(1), \App\Models\AcademicProgram::findOrFail(1));
        $values = app(ScientificPlanChangeService::class)->values($plan);
        return ['request_id' => (string) Str::uuid(), 'revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true, 'new_courses' => [],
            'targets' => [['academic_program_id' => 1, 'source_version_id' => null, 'courses' => $values['courses'],
                'requirements' => ['total_credit_hours' => $values['total_credit_hours'],
                    'groups' => array_map(function ($g) { unset($g['is_active']); return $g; }, $values['groups'])]]]];
    }
    private function additional(array $p): array
    {
        $p['new_courses'][] = ['key' => 'second', 'course_code' => 'SYN-SECOND', 'course_name' => 'مادة اصطناعية ثانية',
            'credit_hours' => 3, 'theoretical_hours' => 3, 'practical_hours' => 0, 'is_active' => true];
        $p['targets'][0]['courses'][] = ['course_id' => null, 'new_course_key' => 'second', 'requirement_scope' => 'university',
            'course_type' => 'elective', 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'is_active' => true];
        return $p;
    }
    public function test_reads_have_no_writes_and_show_the_real_legacy_curriculum(): void
    {
        $revision = app(AcademicCatalogTransaction::class)->revision(); $logs = DB::table('user_activity_logs')->count();
        $this->getJson(self::API.'/workspace?academic_program_id=1')->assertOk()->assertJsonPath('data.plan.persisted', false)
            ->assertJsonPath('data.meta.total', 1)->assertJsonCount(6, 'data.plan.groups');
        self::assertSame($revision, app(AcademicCatalogTransaction::class)->revision());
        self::assertSame($logs, DB::table('user_activity_logs')->count()); self::assertSame(0, AcademicPlanVersion::count());
    }
    public function test_noop_creates_only_an_operational_receipt_not_a_plan_or_change(): void
    {
        $p = $this->payload();
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', false);
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', false);
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('academic_plan_events')->count());
        self::assertSame(1, DB::table('user_activity_logs')->where('action_code', 'academic_plan.workspace_result')->count());
        $this->getJson(self::API.'/1/history')->assertOk()->assertJsonPath('data.meta.total', 0);
    }
    public function test_save_pins_old_students_then_publishes_and_replays_before_revision_check(): void
    {
        $p = $this->additional($this->payload());
        $result = $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', true)->json('data');
        self::assertSame(2, AcademicPlanVersion::count());
        $old = AcademicPlanVersion::where('status', 'transitional')->sole(); $approved = AcademicPlanVersion::where('status', 'approved')->sole();
        self::assertSame($old->getKey(), AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        self::assertSame(1, DB::table('program_courses')->where('academic_plan_version_id', $old->getKey())->count());
        self::assertSame(2, DB::table('program_courses')->where('academic_plan_version_id', $approved->getKey())->count());
        DB::table('courses')->where('course_id', 1)->update(['description' => 'Unrelated later correction']);
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertExactJson(['success' => true, 'data' => $result]);
        self::assertSame(2, AcademicPlanVersion::count());
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $different = $p; $different['new_courses'][0]['course_name'] = 'Different intent';
        $this->postJson(self::API.'/plan-changes', $different)->assertConflict()->assertJsonPath('error_code', 'academic_plan_request_mismatch');
        $newStudent = DB::transaction(fn () => Student::create(['academic_program_id' => 1]));
        self::assertSame($approved->getKey(), AcademicPlanContext::forStudent($newStudent)->versionId);
    }
    public function test_incomplete_source_is_not_repaired_by_completing_a_new_plan(): void
    {
        DB::table('program_course_requirement_groups')->delete(); DB::table('program_courses')->delete(); DB::table('academic_requirement_groups')->delete();
        DB::table('students')->insert(['student_id' => 2, 'academic_program_id' => 1, 'deleted_at' => now()]);
        $p = $this->payload(); $p['targets'][0]['requirements']['groups'] = [];
        foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            $p['targets'][0]['requirements']['groups'][] = ['requirement_scope' => $scope, 'requirement_type' => $type,
                'required_credit_hours' => $scope === 'university' && $type === 'mandatory' ? 6 : 0];
        }
        $p['targets'][0]['requirements']['total_credit_hours'] = 6;
        $p['targets'][0]['courses'][] = ['course_id' => 1, 'requirement_scope' => 'university', 'course_type' => 'mandatory',
            'academic_level_id' => 1, 'recommended_semester_id' => 1, 'is_active' => true];
        $p = $this->additional($p); $p['targets'][0]['courses'][1]['course_type'] = 'mandatory';
        $p['revision'] = app(AcademicCatalogTransaction::class)->revision();
        $this->postJson(self::API.'/plan-changes', $p)->assertOk();
        $source = AcademicPlanVersion::where('status', 'transitional')->sole();
        self::assertSame('legacy', $source->calculation_policy); self::assertSame(3, $source->total_credit_hours);
        self::assertSame(0, DB::table('program_courses')->where('academic_plan_version_id', $source->getKey())->count());
        self::assertSame(0, DB::table('academic_requirement_groups')->where('academic_plan_version_id', $source->getKey())->count());
        self::assertSame(2, DB::table('student_academic_plan_assignments')->where('academic_plan_version_id', $source->getKey())->count());
        self::assertSame('ready', DB::table('academic_programs')->value('plan_state'));
    }
    public function test_failed_approval_rolls_back_fixation_origins_memberships_and_receipts(): void
    {
        $p = $this->additional($this->payload()); $p['targets'][0]['requirements']['total_credit_hours'] = 99;
        $before = DB::table('courses')->count(); $this->postJson(self::API.'/plan-changes', $p)->assertUnprocessable();
        self::assertSame($before, DB::table('courses')->count()); self::assertSame(0, AcademicPlanVersion::count());
        self::assertSame(0, DB::table('academic_plan_events')->count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame('legacy', DB::table('academic_programs')->value('plan_state'));
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertOk()->assertJsonPath('data.status', 'not_found');
    }
    public function test_result_and_mutation_require_current_permissions_and_actor_ownership(): void
    {
        $p = $this->payload(); $this->postJson(self::API.'/plan-changes', $p)->assertOk();
        DB::table('permissions')->where('permission_code', \App\Support\ScientificProgramAccess::ASSIGN)->update(['is_active' => false]);
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertForbidden();
        $this->postJson(self::API.'/plan-changes', $p)->assertForbidden();
    }

    public function test_unchanged_missing_group_placeholders_do_not_fix_a_legacy_program(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->delete();
        DB::table('academic_requirement_groups')->where('requirement_group_id', 2)->update(['required_credit_hours' => null]);
        $p = $this->payload();
        $p['targets'][0]['requirements']['groups'][] = ['requirement_scope' => 'department', 'requirement_type' => 'elective', 'required_credit_hours' => null];
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', false);
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('academic_plan_events')->count());
        self::assertSame(5, DB::table('academic_requirement_groups')->count());
        self::assertNull(DB::table('academic_requirement_groups')->where('requirement_group_id', 2)->value('required_credit_hours'));
    }

    public function test_unmapped_legacy_membership_remains_readable_and_unchanged_not_silently_repaired(): void
    {
        DB::table('program_course_requirement_groups')->delete();
        DB::table('academic_requirement_groups')->where('requirement_group_id', 2)->update(['is_active' => false]);
        $p = $this->payload(); self::assertNull($p['targets'][0]['courses'][0]['requirement_scope']);
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', false);
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('program_course_requirement_groups')->count());
        $changed = $this->additional($p); $changed['request_id'] = (string) Str::uuid();
        $this->postJson(self::API.'/plan-changes', $changed)->assertUnprocessable();
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
    }

    public function test_noop_on_a_published_plan_leaves_version_and_change_history_counts_unchanged(): void
    {
        $this->postJson(self::API.'/plan-changes', $this->additional($this->payload()))->assertOk();
        $snapshot = $this->getJson(self::API.'/workspace?academic_program_id=1')->assertOk()->json('data');
        $target = ['academic_program_id' => 1, 'source_version_id' => $snapshot['plan']['version']['academic_plan_version_id'], 'courses' => $snapshot['values']['courses'],
            'requirements' => ['total_credit_hours' => $snapshot['values']['total_credit_hours'], 'groups' => array_map(function ($g) { unset($g['is_active']); return $g; }, $snapshot['values']['groups'])]];
        $p = ['request_id' => (string) Str::uuid(), 'revision' => $snapshot['revision'], 'confirmed' => true, 'targets' => [$target], 'new_courses' => []];
        $events = DB::table('academic_plan_events')->count(); $versions = AcademicPlanVersion::count();
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', false);
        self::assertSame($events, DB::table('academic_plan_events')->count()); self::assertSame($versions, AcademicPlanVersion::count());
    }

    public function test_nonempty_incomplete_source_preserves_ids_null_budget_and_configuration_error(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->delete();
        DB::table('academic_requirement_groups')->where('requirement_group_id', 2)->update(['required_credit_hours' => null]);
        $groups = DB::table('academic_requirement_groups')->get(['requirement_group_id', 'required_credit_hours', 'requirement_scope', 'requirement_type'])->toArray();
        $membership = DB::table('program_courses')->first(); $mapping = DB::table('program_course_requirement_groups')->first();
        $failure = function () {
            try { app(\App\Services\AcademicRequirementService::class)->forStudent(Student::findOrFail(1))->assertProgramGraduationConfiguration(1); self::fail('Legacy incompleteness hidden'); }
            catch (\App\Exceptions\AcademicRequirementConfigurationException $e) { return [$e->getMessage(), $e->context, $e->errorCode, $e->status]; }
        };
        $before = $failure(); $p = $this->additional($this->payload());
        foreach ($p['targets'][0]['requirements']['groups'] as &$g) if ($g['required_credit_hours'] === null) $g['required_credit_hours'] = 0; unset($g);
        $p['targets'][0]['requirements']['groups'][] = ['requirement_scope' => 'department', 'requirement_type' => 'elective', 'required_credit_hours' => 0];
        $this->postJson(self::API.'/plan-changes', $p)->assertOk();
        $source = AcademicPlanVersion::where('status', 'transitional')->sole();
        self::assertEquals($groups, DB::table('academic_requirement_groups')->where('academic_plan_version_id', $source->getKey())->get(['requirement_group_id', 'required_credit_hours', 'requirement_scope', 'requirement_type'])->toArray());
        self::assertSame($membership->course_id, DB::table('program_courses')->where('program_course_id', $membership->program_course_id)->value('course_id'));
        self::assertSame($mapping->requirement_group_id, DB::table('program_course_requirement_groups')->where('program_course_requirement_group_id', $mapping->program_course_requirement_group_id)->value('requirement_group_id'));
        self::assertSame(3, $source->total_credit_hours); self::assertSame($before, $failure());
        self::assertSame(6, DB::table('academic_requirement_groups')->where('academic_plan_version_id', '<>', $source->getKey())->count());
    }

    public function test_real_official_progress_transcript_gpa_graduation_and_curriculum_eligibility_survive_save(): void
    {
        foreach ([1 => 80, 2 => 100] as $id => $mark) {
            DB::table('course_offerings')->insert(['course_offering_id' => $id, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open']);
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $id, 'student_id' => 1, 'course_offering_id' => $id, 'registration_status_id' => 1]);
            DB::table('grade_approvals')->insert(['course_offering_id' => $id, 'approval_status_id' => 1]);
            DB::table('student_course_results')->insert(['student_course_registration_id' => $id, 'result_status_id' => 1, 'final_mark' => $mark]);
        }
        $snapshot = function () {
            $student = Student::findOrFail(1);
            app(\App\Services\RegistrationService::class)->assertSelfRegistrationAllowed($student, \App\Models\CourseOffering::findOrFail(1));
            return [app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($student),
                app(\App\Services\GradeService::class)->getTranscript($student), app(\App\Services\GradeService::class)->calculateCGPA($student),
                app(\App\Services\GraduationEligibilityService::class)->evaluate($student)];
        };
        $before = $snapshot(); $this->postJson(self::API.'/plan-changes', $this->additional($this->payload()))->assertOk();
        self::assertEquals($before, $snapshot());
        $old = AcademicPlanVersion::where('status', 'transitional')->sole();
        self::assertSame(2, DB::table('student_course_registrations')->where('academic_plan_version_id', $old->getKey())->where('plan_program_course_id', 1)->count());
    }

    public function test_only_selected_programs_receive_shared_origin_with_independent_allocation_and_real_diffs(): void
    {
        DB::table('academic_levels')->insert(['academic_level_id' => 2, 'level_name' => 'الثاني']);
        DB::table('semesters')->insert(['semester_id' => 2, 'semester_name' => 'الثاني']);
        $p = $this->additional($this->payload()); $other = $p['targets'][0]; $other['academic_program_id'] = 2;
        $other['courses'][0]['course_id'] = 2; $other['courses'][1]['academic_level_id'] = 2; $other['courses'][1]['recommended_semester_id'] = 2; $other['courses'][1]['requirement_scope'] = 'college';
        $p['targets'][] = $other;
        DB::table('academic_programs')->insert(['academic_program_id' => 3, 'department_id' => 2, 'program_name' => 'غير مختار', 'total_credit_hours' => 3]);
        $p['revision'] = app(AcademicCatalogTransaction::class)->revision();
        $before = app(ScientificPlanChangeService::class)->values(app(\App\Services\AcademicPlanWorkflow::class)->currentPlanProjection(User::findOrFail(1), \App\Models\AcademicProgram::findOrFail(1)));
        $result = $this->postJson(self::API.'/plan-changes', $p)->assertOk()->json('data');
        self::assertSame(1, DB::table('courses')->where('course_code', 'SYN-SECOND')->count());
        self::assertSame(0, AcademicPlanVersion::where('academic_program_id', 3)->count());
        $shared = $result['created_courses']['second'];
        foreach ($result['targets'] as $target) {
            $row = DB::table('program_courses')->where('academic_plan_version_id', $target['academic_plan_version_id'])->where('course_id', $shared)->sole();
            self::assertSame($target['academic_program_id'], (int) $row->academic_level_id);
        }
        $history = $this->getJson(self::API.'/1/history')->assertOk()->json('data.data');
        $event = collect($history)->firstWhere('action', 'workspace_saved');
        self::assertSame($before, $event['before']);
        $after = app(ScientificPlanChangeService::class)->values(app(\App\Services\AcademicPlanWorkflow::class)->versionProjection(User::findOrFail(1), \App\Models\AcademicProgram::findOrFail(1), AcademicPlanVersion::findOrFail($result['targets'][0]['academic_plan_version_id'])));
        self::assertSame($after, $event['after']);
        self::assertSame([1, 2], $event['selected_program_ids']); self::assertSame('SYN-SECOND', $event['course_labels'][$shared]['course_code']);
    }

    public function test_result_is_actor_owned_and_rechecks_scope_and_read_authorization(): void
    {
        $p = $this->payload(); $this->postJson(self::API.'/plan-changes', $p)->assertOk();
        DB::table('users')->insert(['user_id' => 2, 'username' => 'other-scientific', 'account_status_id' => 1]);
        DB::table('user_roles')->insert(['user_id' => 2, 'role_id' => 1]); DB::table('user_access_scopes')->insert(['user_id' => 2, 'scope_type' => 'university', 'scope_id' => 1]);
        Sanctum::actingAs(User::findOrFail(2));
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertForbidden(); $this->postJson(self::API.'/plan-changes', $p)->assertForbidden();
        Sanctum::actingAs(User::findOrFail(1)); DB::table('user_access_scopes')->where('user_id', 1)->update(['scope_type' => 'college', 'scope_id' => 2]);
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertForbidden();
        $this->getJson(self::API.'/workspace?academic_program_id=1')->assertNotFound();
    }

    public function test_stale_aba_unknown_fields_and_failed_audit_write_nothing(): void
    {
        $p = $this->additional($this->payload());
        DB::table('courses')->where('course_id', 1)->update(['description' => 'temporary']); DB::table('courses')->where('course_id', 1)->update(['description' => null]);
        $this->postJson(self::API.'/plan-changes', $p)->assertConflict()->assertJsonPath('error_code', 'academic_catalog_stale');
        $p['revision'] = app(AcademicCatalogTransaction::class)->revision(); $invalid = $p; $invalid['targets'][0]['override'] = true;
        $this->postJson(self::API.'/plan-changes', $invalid)->assertUnprocessable();
        DB::unprepared("CREATE TRIGGER fail_workspace_receipt BEFORE INSERT ON user_activity_logs WHEN NEW.action_code='academic_plan.workspace_result' BEGIN SELECT RAISE(ABORT,'synthetic receipt failure'); END");
        $this->postJson(self::API.'/plan-changes', $p)->assertStatus(500);
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame(0, DB::table('academic_plan_events')->count()); self::assertSame(2, DB::table('courses')->count());
    }

    public function test_workspace_reads_are_eager_bounded_and_do_not_write_as_course_count_grows(): void
    {
        $queries = function () {
            DB::flushQueryLog(); DB::enableQueryLog();
            $response = $this->getJson(self::API.'/workspace?academic_program_id=1&per_page=5')->assertOk();
            $log = DB::getQueryLog(); DB::disableQueryLog();
            foreach ($log as $query) self::assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update)\b/i', $query['query']);
            return [count($log), $response];
        };
        [$before] = $queries();
        for ($id = 3; $id <= 25; $id++) {
            DB::table('courses')->insert(['course_id' => $id, 'course_code' => 'SYN-'.$id, 'course_name' => 'مادة اصطناعية '.$id, 'credit_hours' => 3]);
            $pc = DB::table('program_courses')->insertGetId(['academic_program_id' => 1, 'course_id' => $id, 'course_type' => 'mandatory'], 'program_course_id');
            DB::table('program_course_requirement_groups')->insert(['program_course_id' => $pc, 'requirement_group_id' => 1]);
        }
        [$after, $response] = $queries();
        self::assertLessThanOrEqual($before + 2, $after); $response->assertJsonPath('data.meta.total', 24)->assertJsonCount(5, 'data.rows');
    }

    public function test_catalogue_text_correction_history_reads_the_actual_nested_audit_context(): void
    {
        app(\App\Services\ScientificCourseManagementService::class)->saveCourse(User::findOrFail(1), 1,
            ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'course_name' => 'تصحيح نصي اصطناعي']);
        $event = $this->getJson(self::API.'/1/history')->assertOk()->json('data.data.0');
        self::assertTrue($event['details_available']); self::assertSame('مادة 1', $event['before']['course_name']);
        self::assertSame('تصحيح نصي اصطناعي', $event['after']['course_name']);
        self::assertSame('scientific_catalog.course.update', $event['action']);
    }

    public function test_empty_saved_draft_requires_explicit_choice_then_accepts_full_setup_atomically(): void
    {
        DB::table('academic_programs')->where('academic_program_id', 2)->update(['plan_state' => 'preparing']);
        $draft = AcademicPlanVersion::create(['academic_program_id' => 2, 'version_number' => 1, 'label' => 'خطة لإكمال الإعداد', 'status' => 'draft',
            'calculation_policy' => 'explicit_zero_v1', 'total_credit_hours' => 3, 'created_by_user_id' => 1]);
        $this->getJson(self::API.'/workspace?academic_program_id=2')->assertOk()->assertJsonPath('data.requires_source_selection', true)->assertJsonCount(1, 'data.source_choices');
        $this->getJson(self::API.'/workspace?academic_program_id=2&version_id='.$draft->getKey())->assertOk()->assertJsonPath('data.can_save', true)->assertJsonPath('data.meta.total', 0);
        $p = $this->payload(); $p['targets'][0]['academic_program_id'] = 2; $p['targets'][0]['source_version_id'] = $draft->getKey(); $p['targets'][0]['courses'][0]['course_id'] = 2;
        $this->postJson(self::API.'/plan-changes', $p)->assertOk();
        self::assertSame(1, AcademicPlanVersion::where('academic_program_id', 2)->count()); self::assertSame('approved', $draft->fresh()->status);
        self::assertSame($draft->getKey(), (int) DB::table('academic_programs')->where('academic_program_id', 2)->value('default_academic_plan_version_id'));
    }

    public function test_unrelated_course_change_cannot_silently_activate_a_requirement_group_and_failed_approval_rolls_back(): void
    {
        // Department elective group is unrelated to both university courses and has unchanged zero hours.
        DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->update(['is_active' => false]);
        $before = collect(['academic_requirement_groups', 'program_courses', 'program_course_requirement_groups', 'academic_programs', 'courses'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toArray()])->all();
        $p = $this->additional($this->payload());
        $p['targets'][0]['courses'][0]['academic_level_id'] = 1; $p['targets'][0]['courses'][0]['recommended_semester_id'] = 1;
        // Existing clients omit is_active. Omission must preserve the locked source, never infer activation.
        $this->postJson(self::API.'/plan-changes', $p)->assertUnprocessable()->assertJsonValidationErrors('plan');
        foreach ($before as $table => $rows) self::assertEquals($rows, DB::table($table)->get()->toArray(), $table.' must roll back');
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame(0, DB::table('academic_plan_events')->count());
        self::assertSame(0, DB::table('user_activity_logs')->where('action_code', 'academic_plan.workspace_result')->count());
        $this->getJson(self::API.'/plan-changes/'.$p['request_id'])->assertOk()->assertJsonPath('data.status', 'not_found');
    }

    public function test_explicit_state_only_activation_with_identical_hours_is_saved_audited_and_does_not_change_old_students(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->update(['is_active' => false]);
        $p = $this->payload();
        foreach ($p['targets'][0]['requirements']['groups'] as &$g) if ($g['requirement_scope'] === 'department' && $g['requirement_type'] === 'elective') $g['is_active'] = true; unset($g);
        $this->postJson(self::API.'/plan-changes', $p)->assertOk()->assertJsonPath('data.changed', true);
        $source = AcademicPlanVersion::where('status', 'transitional')->sole(); $approved = AcademicPlanVersion::where('status', 'approved')->sole();
        $oldGroup = DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->sole();
        self::assertSame($source->getKey(), (int) $oldGroup->academic_plan_version_id); self::assertSame(0, (int) $oldGroup->required_credit_hours); self::assertFalse((bool) $oldGroup->is_active);
        self::assertSame($source->getKey(), AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        $newGroup = DB::table('academic_requirement_groups')->where('academic_plan_version_id', $approved->getKey())->where('requirement_scope', 'department')->where('requirement_type', 'elective')->sole();
        self::assertSame(0, (int) $newGroup->required_credit_hours); self::assertTrue((bool) $newGroup->is_active);
        $event = collect($this->getJson(self::API.'/1/history')->assertOk()->json('data.data'))->firstWhere('action', 'workspace_saved');
        $key = fn ($g) => $g['requirement_scope'] === 'department' && $g['requirement_type'] === 'elective';
        $before = collect($event['before']['groups'])->first($key); $after = collect($event['after']['groups'])->first($key);
        self::assertFalse($before['is_active']); self::assertTrue($after['is_active']); self::assertSame($before['required_credit_hours'], $after['required_credit_hours']);
        self::assertSame($event['before']['courses'], $event['after']['courses']);
        // Explicitly preserved false is also authoritative, not a hidden activation directive.
        $snapshot = $this->getJson(self::API.'/workspace?academic_program_id=1')->assertOk()->json('data');
        $next = $p; $next['request_id'] = (string) Str::uuid(); $next['revision'] = $snapshot['revision']; $next['targets'][0]['source_version_id'] = $approved->getKey();
        foreach ($next['targets'][0]['requirements']['groups'] as &$g) if ($key($g)) $g['is_active'] = false; unset($g);
        $this->postJson(self::API.'/plan-changes', $next)->assertUnprocessable();
        self::assertSame(2, AcademicPlanVersion::count()); self::assertTrue((bool) DB::table('academic_requirement_groups')->where('requirement_group_id', $newGroup->requirement_group_id)->value('is_active'));
    }

    public function test_existing_requirements_endpoint_preserves_omitted_activity_and_records_explicit_choice(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->update(['is_active' => false]);
        $workflow = app(\App\Services\AcademicPlanWorkflow::class); $actor = User::findOrFail(1);
        $fixed = $workflow->fixTransition($actor, 1, ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true]);
        $draft = $workflow->copy($actor, 1, $fixed['current_version_id'], ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'label' => 'مسودة اختبار صريحة']);
        $version = $draft['version']->getKey();
        $body = ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'total_credit_hours' => 3,
            'groups' => collect($draft['groups'])->map(fn ($g) => ['requirement_scope' => $g->requirement_scope, 'requirement_type' => $g->requirement_type, 'required_credit_hours' => $g->required_credit_hours])->all()];
        $url = self::API.'/1/versions/'.$version;
        $this->putJson($url.'/requirements', $body)->assertOk();
        self::assertFalse((bool) DB::table('academic_requirement_groups')->where('academic_plan_version_id', $version)->where('requirement_scope', 'department')->where('requirement_type', 'elective')->value('is_active'));
        $this->postJson($url.'/approve', ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true])->assertUnprocessable();
        foreach ($body['groups'] as &$g) if ($g['requirement_scope'] === 'department' && $g['requirement_type'] === 'elective') $g['is_active'] = true; unset($g);
        $body['revision'] = app(AcademicCatalogTransaction::class)->revision(); $this->putJson($url.'/requirements', $body)->assertOk();
        $this->postJson($url.'/approve', ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true])->assertOk();
        $events = collect($this->getJson(self::API.'/1/history')->assertOk()->json('data.data'))->where('action', 'requirements_saved');
        self::assertTrue($events->contains(fn ($e) => $e['details_available'] && collect($e['before']['groups'])->contains(fn ($g) => $g['is_active'] === false)
            && !collect($e['after']['groups'])->contains(fn ($g) => $g['is_active'] === false)));
        self::assertFalse((bool) DB::table('academic_requirement_groups')->where('requirement_group_id', 6)->value('is_active'));
    }
}
