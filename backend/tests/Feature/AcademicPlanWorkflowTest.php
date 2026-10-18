<?php

namespace Tests\Feature;

use App\Exceptions\{AcademicCatalogException, AcademicPlanException};
use App\Models\{AcademicPlanVersion, Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AcademicPlanWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
    }

    private function workflow(): AcademicPlanWorkflow { return app(AcademicPlanWorkflow::class); }
    private function actor(): User { return User::findOrFail(1); }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function confirm(): array { return ['revision' => $this->revision(), 'confirmed' => true]; }
    private function fixed(): int
    {
        $this->workflow()->begin($this->actor(), 1, $this->confirm());
        $this->workflow()->fixTransition($this->actor(), 1, $this->confirm());
        return (int) AcademicPlanVersion::sole()->getKey();
    }

    public function test_real_official_progress_and_transcript_are_identical_across_transition(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 1, 'student_id' => 1, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        DB::table('student_course_results')->insert(['student_course_registration_id' => 1, 'result_status_id' => 1, 'final_mark' => 80, 'theoretical_total' => 80]);
        $snapshot = function () {
            $student = Student::findOrFail(1);
            return [app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($student),
                app(\App\Services\GradeService::class)->getTranscript($student),
                app(\App\Services\GraduationEligibilityService::class)->evaluate($student)];
        };
        $before = $snapshot(); $source = $this->fixed(); $after = $snapshot();
        self::assertEquals($before, $after);
        self::assertSame($source, (int) DB::table('student_course_registrations')->value('academic_plan_version_id'));
        self::assertSame(1, (int) DB::table('student_course_registrations')->value('plan_program_course_id'));
    }

    public function test_repeated_official_attempts_keep_identical_progress_gpa_and_graduation_after_fixation(): void
    {
        foreach ([1 => 80, 2 => 100] as $offeringId => $mark) {
            DB::table('course_offerings')->insert(['course_offering_id' => $offeringId, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $offeringId, 'student_id' => 1, 'course_offering_id' => $offeringId, 'registration_status_id' => 1]);
            DB::table('grade_approvals')->insert(['course_offering_id' => $offeringId, 'approval_status_id' => 1]);
            DB::table('student_course_results')->insert(['student_course_registration_id' => $offeringId, 'result_status_id' => 1, 'final_mark' => $mark]);
        }
        $snapshot = fn () => [app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress(Student::findOrFail(1)),
            app(\App\Services\GradeService::class)->getTranscript(Student::findOrFail(1)),
            app(\App\Services\GraduationEligibilityService::class)->evaluate(Student::findOrFail(1))];
        $before = $snapshot(); $source = $this->fixed();
        self::assertEquals($before, $snapshot());
        self::assertSame(2, DB::table('student_course_registrations')->where('academic_plan_version_id', $source)->where('plan_program_course_id', 1)->count());
    }

    public function test_fixation_preserves_incomplete_configuration_instead_of_repairing_or_hiding_it(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['required_credit_hours' => 77]);
        $inspect = function (): array {
            try { app(\App\Services\AcademicRequirementService::class)->forStudent(Student::findOrFail(1))->assertProgramGraduationConfiguration(1); self::fail('Invalid configuration hidden'); }
            catch (\App\Exceptions\AcademicRequirementConfigurationException $e) { return [$e::class, $e->getMessage()]; }
        };
        $before = $inspect(); $this->fixed();
        self::assertSame($before, $inspect());
        self::assertSame(77, (int) DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->value('required_credit_hours'));
    }

    public function test_preview_is_read_only_and_fix_preserves_existing_ids_without_approval_or_default(): void
    {
        $this->workflow()->begin($this->actor(), 1, $this->confirm());
        $before = DB::table('program_courses')->first(); $revision = $this->revision();
        $preview = $this->workflow()->previewTransition($this->actor(), 1);
        self::assertTrue($preview['can_fix']); self::assertSame($revision, $this->revision());
        $this->workflow()->fixTransition($this->actor(), 1, ['revision' => $preview['revision'], 'confirmed' => true]);
        $version = AcademicPlanVersion::sole();
        self::assertSame('transitional', $version->status); self::assertNull($version->approved_at);
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', 1)->value('default_academic_plan_version_id'));
        self::assertSame('preparing', DB::table('academic_programs')->where('academic_program_id', 1)->value('plan_state'));
        self::assertSame($before->program_course_id, DB::table('program_courses')->sole()->program_course_id);
        self::assertSame($before->course_id, DB::table('program_courses')->sole()->course_id);
        self::assertSame($version->getKey(), AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
    }

    public function test_changed_then_restored_curriculum_invalidates_transition_preview(): void
    {
        $this->workflow()->begin($this->actor(), 1, $this->confirm()); $preview = $this->workflow()->previewTransition($this->actor(), 1);
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['required_credit_hours' => 4]);
        DB::table('academic_requirement_groups')->where('requirement_group_id', 1)->update(['required_credit_hours' => 3]);
        try { $this->workflow()->fixTransition($this->actor(), 1, ['revision' => $preview['revision'], 'confirmed' => true]); self::fail('Stale preview accepted'); }
        catch (AcademicCatalogException $e) { self::assertSame('academic_catalog_stale', $e->errorCode); }
        self::assertSame(0, AcademicPlanVersion::count()); self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
    }

    public function test_incomplete_draft_null_is_not_zero_and_approval_does_not_select_default(): void
    {
        $source = $this->fixed();
        $copy = $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'خطة جديدة']);
        $id = (int) $copy['version']->getKey();
        $groups = collect($copy['groups'])->map(fn ($g) => ['requirement_scope' => $g->requirement_scope, 'requirement_type' => $g->requirement_type, 'required_credit_hours' => $g->required_credit_hours])->all();
        $groups[0]['required_credit_hours'] = null;
        $draft = $this->workflow()->saveRequirements($this->actor(), 1, $id, ['revision' => $this->revision(), 'total_credit_hours' => 3, 'groups' => $groups]);
        self::assertFalse($draft['configuration']['complete']);
        try { $this->workflow()->approve($this->actor(), 1, $id, $this->confirm()); self::fail('Incomplete plan approved'); }
        catch (ValidationException $e) { self::assertArrayHasKey('plan', $e->errors()); }
        $groups[0]['required_credit_hours'] = 0;
        $this->workflow()->saveRequirements($this->actor(), 1, $id, ['revision' => $this->revision(), 'total_credit_hours' => 3, 'groups' => $groups]);
        $this->workflow()->approve($this->actor(), 1, $id, $this->confirm());
        self::assertNull(DB::table('academic_programs')->where('academic_program_id', 1)->value('default_academic_plan_version_id'));
        $this->workflow()->setDefault($this->actor(), 1, $id, $this->confirm());
        self::assertSame('ready', DB::table('academic_programs')->where('academic_program_id', 1)->value('plan_state'));
        self::assertSame($source, AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        self::assertSame($id, AcademicPlanContext::forProgram(1)->versionId);
    }

    public function test_transitional_reference_cannot_be_assigned_as_default(): void
    {
        $source = $this->fixed();
        $this->expectException(AcademicPlanException::class);
        $this->workflow()->setDefault($this->actor(), 1, $source, $this->confirm());
    }

    public function test_transfer_previews_target_classification_and_preserves_old_registration_context(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 1, 'student_id' => 1, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        DB::table('student_course_results')->insert(['student_course_registration_id' => 1, 'result_status_id' => 1, 'final_mark' => 80]);
        $source = $this->fixed();
        $draft = $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'تصنيف اختياري']);
        $id = (int) $draft['version']->getKey();
        $this->workflow()->saveMembership($this->actor(), 1, $id, 1, ['revision' => $this->revision(), 'course_type' => 'elective', 'requirement_scope' => 'university',
            'academic_level_id' => null, 'recommended_semester_id' => null, 'is_active' => true]);
        $groups = collect($draft['groups'])->map(fn ($g) => ['requirement_scope' => $g->requirement_scope, 'requirement_type' => $g->requirement_type,
            'required_credit_hours' => $g->requirement_scope === 'university' && $g->requirement_type === 'elective' ? 3 : 0])->all();
        $this->workflow()->saveRequirements($this->actor(), 1, $id, ['revision' => $this->revision(), 'total_credit_hours' => 3, 'groups' => $groups]);
        $this->workflow()->approve($this->actor(), 1, $id, $this->confirm());
        $beforeTranscript = app(\App\Services\GradeService::class)->getTranscript(Student::findOrFail(1));
        $preview = $this->workflow()->previewTransfer($this->actor(), 1, $id, ['student_ids' => [1]]);
        self::assertTrue($preview['can_transfer']);
        self::assertSame('mandatory', $preview['students'][0]['before']['counted_courses'][0]['requirement_type']);
        self::assertSame('elective', $preview['students'][0]['after']['counted_courses'][0]['requirement_type']);
        self::assertSame(3, $preview['students'][0]['after']['counted_hours']);
        $this->workflow()->transfer($this->actor(), 1, $id, ['student_ids' => [1], 'revision' => $preview['revision'], 'reason' => 'قرار صريح', 'confirmed' => true]);
        self::assertSame($id, AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        self::assertSame($source, (int) DB::table('student_course_registrations')->value('academic_plan_version_id'));
        self::assertEquals($beforeTranscript, app(\App\Services\GradeService::class)->getTranscript(Student::findOrFail(1)));
        self::assertSame(2, DB::table('student_academic_plan_assignments')->count());
    }

    public function test_missing_student_assignment_never_falls_back_to_program_default(): void
    {
        $this->fixed(); DB::table('student_academic_plan_assignments')->delete();
        $this->expectException(AcademicPlanException::class);
        AcademicPlanContext::forStudent(Student::findOrFail(1));
    }

    public function test_two_plans_share_actual_offering_with_exact_student_membership_and_archived_operations(): void
    {
        $source = $this->fixed();
        $copy = $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'الخطة الثانية']);
        $target = (int) $copy['version']->getKey();
        $this->workflow()->saveMembership($this->actor(), 1, $target, 1, ['revision' => $this->revision(), 'course_type' => 'elective',
            'requirement_scope' => 'university', 'academic_level_id' => null, 'recommended_semester_id' => null, 'is_active' => true]);
        $groups = collect($copy['groups'])->map(fn ($g) => ['requirement_scope' => $g->requirement_scope, 'requirement_type' => $g->requirement_type,
            'required_credit_hours' => $g->requirement_scope === 'university' && $g->requirement_type === 'elective' ? 3 : 0])->all();
        $this->workflow()->saveRequirements($this->actor(), 1, $target, ['revision' => $this->revision(), 'total_credit_hours' => 3, 'groups' => $groups]);
        $this->workflow()->approve($this->actor(), 1, $target, $this->confirm());
        $this->workflow()->setDefault($this->actor(), 1, $target, $this->confirm());
        $newStudent = DB::transaction(fn () => Student::create(['academic_program_id' => 1]));
        $oldStudent = Student::findOrFail(1);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open']);
        $offering = \App\Models\CourseOffering::findOrFail(1);
        $registrationService = app(\App\Services\RegistrationService::class);
        foreach ([$oldStudent, $newStudent] as $student) $registrationService->assertSelfRegistrationAllowed($student, $offering);
        $oldMembership = AcademicPlanContext::forStudent($oldStudent)->membership(1);
        $newMembership = AcademicPlanContext::forStudent($newStudent)->membership(1);
        self::assertNotSame($oldMembership->getKey(), $newMembership->getKey());
        self::assertSame('mandatory', $oldMembership->course_type); self::assertSame('elective', $newMembership->course_type);
        foreach ([$oldStudent, $newStudent] as $student) DB::transaction(function () use ($student) {
            $r = \App\Models\StudentCourseRegistration::create(['student_id' => $student->getKey(), 'course_offering_id' => 1, 'registration_status_id' => 2]);
            \App\Services\AcademicPlanRecords::pinRegistration($r, $student, 1);
            self::assertSame(AcademicPlanContext::forStudent($student)->membership(1)->getKey(), (int) $r->plan_program_course_id);
        });
        $before = app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($oldStudent);
        app(\App\Services\ScientificProgramManagementService::class)->archive($this->actor(), 1, $this->confirm());
        foreach ([$oldStudent, $newStudent] as $student) $registrationService->assertSelfRegistrationAllowed($student, $offering);
        self::assertEquals($before, app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($oldStudent));
        self::assertSame('open', $offering->fresh()->status);
        self::assertTrue((bool) DB::table('academic_programs')->where('academic_program_id', 1)->value('is_active'));
    }

    public function test_transfer_preview_is_invalidated_by_new_official_registration_and_never_rebinds_history(): void
    {
        $source = $this->fixed();
        $id = (int) $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'المستهدفة'])['version']->getKey();
        $this->workflow()->approve($this->actor(), 1, $id, $this->confirm());
        $preview = $this->workflow()->previewTransfer($this->actor(), 1, $id, ['student_ids' => [1]]);
        self::assertTrue($preview['can_transfer']);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open']);
        DB::table('student_course_registrations')->insert(['student_id' => 1, 'course_offering_id' => 1, 'registration_status_id' => 2, 'academic_plan_version_id' => $source]);
        try { $this->workflow()->transfer($this->actor(), 1, $id, ['student_ids' => [1], 'revision' => $preview['revision'], 'confirmed' => true, 'reason' => 'نقل']); self::fail('Stale transfer accepted'); }
        catch (AcademicCatalogException $e) { self::assertSame('academic_catalog_stale', $e->errorCode); }
        self::assertSame($source, AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        self::assertFalse($this->workflow()->previewTransfer($this->actor(), 1, $id, ['student_ids' => [1]])['can_transfer']);
    }

    public function test_failed_audit_rolls_back_all_transition_rows_and_links(): void
    {
        $this->workflow()->begin($this->actor(), 1, $this->confirm());
        DB::unprepared("CREATE TRIGGER fail_plan_audit BEFORE INSERT ON user_activity_logs BEGIN SELECT RAISE(ABORT,'test audit failure'); END");
        try { $this->workflow()->fixTransition($this->actor(), 1, $this->confirm()); self::fail('Audit failure ignored'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('test audit failure', $e->getMessage()); }
        self::assertSame(0, AcademicPlanVersion::count());
        self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertNull(DB::table('program_courses')->sole()->academic_plan_version_id);
    }

    public function test_transfer_waits_for_supplementary_materialization_and_closed_grade_appeals(): void
    {
        $source = $this->fixed();
        $target = (int) $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'نقل بعد التسوية'])['version']->getKey();
        $this->workflow()->approve($this->actor(), 1, $target, $this->confirm());
        $preview = $this->workflow()->previewTransfer($this->actor(), 1, $target, ['student_ids' => [1]]);
        self::assertTrue($preview['can_transfer']);
        DB::table('supplementary_exam_registrations')->insert(['supplementary_exam_registration_id' => 1, 'student_id' => 1, 'status' => 'registered']);
        try { $this->workflow()->transfer($this->actor(), 1, $target, ['revision' => $preview['revision'], 'confirmed' => true, 'reason' => 'نقل', 'student_ids' => [1]]); self::fail('Intervening supplementary request ignored'); }
        catch (AcademicCatalogException $e) { self::assertSame('academic_catalog_stale', $e->errorCode); }
        self::assertFalse($this->workflow()->previewTransfer($this->actor(), 1, $target, ['student_ids' => [1]])['can_transfer']);
        DB::table('supplementary_exam_materializations')->insert(['supplementary_exam_registration_id' => 1]);
        DB::table('appeal_statuses')->insert(['appeal_status_id' => 1, 'status_code' => 'submitted']);
        DB::table('grade_appeals')->insert(['student_id' => 1, 'appeal_status_id' => 1]);
        self::assertFalse($this->workflow()->previewTransfer($this->actor(), 1, $target, ['student_ids' => [1]])['can_transfer']);
        DB::table('appeal_statuses')->update(['status_code' => 'closed']);
        self::assertTrue($this->workflow()->previewTransfer($this->actor(), 1, $target, ['student_ids' => [1]])['can_transfer']);
        self::assertSame('registered', DB::table('supplementary_exam_registrations')->value('status'), 'Transfer review never mutates supplementary state');
    }

    public function test_preparing_blocks_new_student_but_retains_existing_student_context(): void
    {
        $this->workflow()->begin($this->actor(), 1, $this->confirm());
        self::assertNull(AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        try { DB::transaction(fn () => Student::create(['academic_program_id' => 1])); self::fail('Admission reopened too early'); }
        catch (AcademicPlanException $e) { self::assertSame('academic_plan_initialization_incomplete', $e->errorCode); }
        self::assertSame(1, Student::count());
        $this->workflow()->fixTransition($this->actor(), 1, $this->confirm());
        try { DB::transaction(fn () => Student::create(['academic_program_id' => 1])); self::fail('Transitional reference used as admission approval'); }
        catch (AcademicPlanException $e) { self::assertSame('academic_plan_initialization_incomplete', $e->errorCode); }
        self::assertSame(1, Student::count());
    }

    public function test_approved_explicit_default_is_pinned_with_new_student_but_not_existing_student(): void
    {
        $source = $this->fixed();
        $copy = $this->workflow()->copy($this->actor(), 1, $source, ['revision' => $this->revision(), 'label' => 'الجديدة']);
        $id = (int) $copy['version']->getKey();
        $this->workflow()->approve($this->actor(), 1, $id, $this->confirm());
        $this->workflow()->setDefault($this->actor(), 1, $id, $this->confirm());
        $student = DB::transaction(fn () => Student::create(['academic_program_id' => 1]));
        self::assertSame($id, AcademicPlanContext::forStudent($student)->versionId);
        self::assertSame($source, AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        DB::table('academic_programs')->where('academic_program_id', 1)->update(['archived_at' => now()]);
        self::assertSame($id, AcademicPlanContext::forStudent($student)->versionId);
        try { DB::transaction(fn () => Student::create(['academic_program_id' => 1])); self::fail('Archived admission allowed'); }
        catch (AcademicPlanException $e) { self::assertSame('academic_program_archived', $e->errorCode); }
    }
}
