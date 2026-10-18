<?php

namespace Tests\Feature;

use App\Exceptions\AcademicPlanException;
use App\Models\{Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow, GradeService};
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApprovedCatalogCompletionTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); \Tests\Support\ApprovedCatalogFixture::initialize(); }
    private function service(): CatalogCompletionService { return app(CatalogCompletionService::class); }
    private function actor(): User { return User::findOrFail(1); }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function apply(?string $id = null): array { return $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $this->revision(), $id ?? (string) Str::uuid()); }

    public function test_all_nine_sheets_formulas_decisions_and_exact_hours_are_pinned(): void
    {
        $m = new ApprovedCatalogManifest;
        self::assertCount(9, $m->data['sheets']); self::assertCount(67, $m->data['formulas']);
        self::assertCount(15, $m->programs()); self::assertSame(909, array_sum(array_column($m->programs(), 'students_baseline')));
        self::assertCount(24, $m->rows('Decisions'));
        foreach ($m->programs() as $p) foreach ($m->rows('Requirements', (int) $p['program_id']) as $group) {
            $sum = collect($m->rows('Curriculum', (int) $p['program_id']))->filter(fn ($r) => $r['requirement_scope'] === $group['requirement_scope'] && $r['course_type'] === $group['requirement_type'])->sum('target_credits');
            self::assertSame((int) $group['required_credit_hours'], (int) $sum, $p['program_id'].':'.$group['requirement_scope'].':'.$group['requirement_type']);
        }
        $sports = collect($m->rows('Curriculum', 40))->keyBy('target_code');
        self::assertSame('3', $sports['TS423']['target_credits']);
        self::assertSame('college', $sports['TS221']['requirement_scope']);
        self::assertSame('department', $sports['ES223']['requirement_scope']);
        self::assertSame(141, array_sum(array_column($m->rows('Requirements', 40), 'required_credit_hours')));
        $tif = collect($m->rows('Curriculum', 41))->firstWhere('target_code', 'TIF125');
        self::assertSame(['3', '2', '2'], [$tif['target_credits'], $tif['theoretical_hours'], $tif['practical_hours']]);
    }

    public function test_preview_is_read_only_and_unresolved_rows_block_their_program_not_independent_groups(): void
    {
        $before = $this->revision();
        $r = $this->service()->preview($this->actor(), new ApprovedCatalogManifest);
        self::assertSame($before, $this->revision());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
        self::assertSame('budget_only', collect($r['rows'])->first(fn ($row) => ($row['source']['entry_type'] ?? null) === 'CHOICE_SLOT')['state']);
        self::assertNotEmpty($r['programs'][29]['blockers']);
        self::assertEmpty($r['programs'][40]['blockers']);
        self::assertSame('reuse', $r['rows']['P29-R0029']['state']);
        self::assertSame(464, $r['rows']['P29-R0029']['course_id']);
        self::assertSame(464, $r['rows']['P30-R0084']['course_id']);
        self::assertSame('create', $r['rows']['P34-R0529']['state']); // PSB remains separate from same-name PSC subjects.
    }

    public function test_empty_sports_and_both_institutes_publish_atomically_preserve_old_context_and_share_real_origins(): void
    {
        $transcript = app(GradeService::class)->getTranscript(Student::findOrFail(1040));
        $r = $this->apply();
        self::assertSame('PARTIAL', $r['status']);
        foreach ([40 => 141, 41 => 82, 42 => 82] as $id => $hours) {
            $version = AcademicPlanContext::forProgram($id)->versionId;
            self::assertNotNull($version, json_encode($r['groups']));
            self::assertSame($hours, (int) DB::table('academic_plan_versions')->where('academic_plan_version_id', $version)->value('total_credit_hours'), json_encode($r['groups']));
            self::assertSame($version, AcademicPlanContext::forStudent(Student::findOrFail(1000 + $id))->versionId);
            $old = DB::table('academic_plan_versions')->where('academic_program_id', $id)->where('status', 'transitional')->sole();
            self::assertSame($id === 41 ? 1 : 0, DB::table('program_courses')->where('academic_plan_version_id', $old->academic_plan_version_id)->count());
            self::assertNull($old->approved_at);
            if ($id === 41) {
                $group = DB::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->sole();
                self::assertNull($group->required_credit_hours); self::assertSame(0, (int) $group->is_active);
                self::assertSame((int) $old->academic_plan_version_id, (int) $group->academic_plan_version_id);
            }
        }
        self::assertSame(147, (int) DB::table('academic_programs')->where('academic_program_id', 40)->value('total_credit_hours'));
        $visible = app(\App\Services\ScientificProgramManagementService::class)->detail($this->actor(), 40);
        self::assertSame(141, (int) $visible['program']['plan_total_credit_hours']);
        // D02 intentionally corrects the current display name, not any academic result.
        array_walk_recursive($transcript, function (&$value, $key) {
            if ($key === 'program_name') $value = 'برنامج الإجازة في التدريب الرياضي';
        });
        self::assertEquals($transcript, app(GradeService::class)->getTranscript(Student::findOrFail(1040)));
        foreach (['TIF211', 'TIF226'] as $code) {
            self::assertSame(1, DB::table('courses')->where('course_code', $code)->count());
            self::assertSame(2, DB::table('program_courses as pc')->join('academic_plan_versions as v', 'v.academic_plan_version_id', '=', 'pc.academic_plan_version_id')
                ->join('courses as c', 'c.course_id', '=', 'pc.course_id')->where('c.course_code', $code)->where('v.status', 'approved')->count());
        }
        self::assertSame(0, DB::table('courses')->where('course_code', 'like', '%CHOICE%')->count());
        self::assertSame(2, (int) DB::table('courses')->where('course_code', 'TIF125')->value('practical_hours'));
        $verified = $this->service()->verify($this->actor(), new ApprovedCatalogManifest);
        self::assertSame(['memberships' => 1, 'requirement_groups' => 1, 'total_credit_hours' => 82], $verified['programs'][41]['before']);
        self::assertSame(['memberships' => 32, 'requirement_groups' => 6, 'total_credit_hours' => 82], $verified['programs'][41]['after']);
    }

    public function test_lost_response_replay_precedes_stale_revision_and_noop_does_not_duplicate_plans(): void
    {
        $id = (string) Str::uuid(); $revision = $this->revision();
        $first = $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $revision, $id);
        $versions = DB::table('academic_plan_versions')->count(); $courses = DB::table('courses')->count(); $events = DB::table('academic_plan_events')->count();
        self::assertSame($first, $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $revision, $id));
        self::assertSame($first, $this->service()->status($this->actor(), new ApprovedCatalogManifest, $id));
        $resumed = $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $this->revision(), $id, true);
        self::assertContains(1040, $resumed['students'][40]['already_assigned']);
        $this->apply();
        self::assertSame($versions, DB::table('academic_plan_versions')->count());
        self::assertSame($courses, DB::table('courses')->count());
        self::assertSame($events, DB::table('academic_plan_events')->count());
    }

    public function test_pending_registration_and_archived_students_are_reported_without_bypassing_blockers(): void
    {
        DB::table('students')->where('student_id', 1040)->update(['deleted_at' => now()]);
        DB::table('student_registration_requests')->insert(['student_id' => 1041, 'status' => 'submitted']);
        $r = $this->apply();
        self::assertContains(1040, $r['students'][40]['transferred']);
        self::assertSame(1041, $r['students'][41]['blocked'][0]['student_id']);
        $old = DB::table('academic_plan_versions')->where('academic_program_id', 41)->where('status', 'transitional')->value('academic_plan_version_id');
        self::assertSame((int) $old, AcademicPlanContext::forStudent(Student::findOrFail(1041))->versionId);
        self::assertSame('PARTIAL', $r['status']);
    }

    public function test_unauthorized_actor_and_stale_revision_write_nothing(): void
    {
        DB::table('user_roles')->delete();
        try { $this->apply(); self::fail('Unauthorized completion accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        try { $this->service()->apply($this->actor(), new ApprovedCatalogManifest, '0', (string) Str::uuid()); self::fail('Stale completion accepted.'); }
        catch (AcademicPlanException $e) { self::assertSame('academic_catalog_stale', $e->errorCode); }
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_console_defaults_to_preview_and_rejects_incomplete_apply_flags_without_writing(): void
    {
        $revision = $this->revision();
        $this->artisan('academic-catalog:complete', ['--actor' => 1])->assertSuccessful();
        $this->artisan('academic-catalog:complete', ['--actor' => 1, '--apply' => true])->assertFailed();
        self::assertSame($revision, $this->revision());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_failed_approval_rolls_back_fixation_origins_memberships_and_assignments(): void
    {
        DB::unprepared("CREATE TRIGGER reject_completion_approval BEFORE INSERT ON academic_plan_events WHEN NEW.action='approved' BEGIN SELECT RAISE(ABORT,'academic_plan_approval_probe'); END");
        $before = DB::table('courses')->orderBy('course_id')->get()->toArray();
        try { $this->apply(); self::fail('Expected the injected persistence failure.'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('academic_plan_approval_probe', $e->getMessage()); }
        self::assertEquals($before, DB::table('courses')->orderBy('course_id')->get()->toArray());
        self::assertSame(0, DB::table('academic_plan_versions')->count());
        self::assertSame(0, DB::table('student_academic_plan_assignments')->count());
        self::assertSame(0, DB::table('academic_plan_events')->count());
        self::assertNull(DB::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->value('required_credit_hours'));
    }

    public function test_assignment_replay_rejects_different_student_content_before_current_revision_check(): void
    {
        $this->apply();
        $receipt = json_decode(DB::table('user_activity_logs')->where('action_code', 'catalog_completion.assignment')->first()->description, true);
        $studentId = $receipt['result']['student_ids'][0];
        $program = (int) Student::withTrashed()->findOrFail($studentId)->academic_program_id;
        $input = ['revision' => '0', 'request_id' => $receipt['request_id'], 'manifest_hash' => ApprovedCatalogManifest::SHA256, 'student_ids' => [$studentId]];
        self::assertSame($receipt['result'], app(AcademicPlanWorkflow::class)->transferCatalogCompletion($this->actor(), $program, $receipt['result']['academic_plan_version_id'], $input));
        $input['student_ids'][] = 99999;
        try { app(AcademicPlanWorkflow::class)->transferCatalogCompletion($this->actor(), $program, $receipt['result']['academic_plan_version_id'], $input); self::fail('Mismatched replay accepted.'); }
        catch (AcademicPlanException $e) { self::assertSame('catalog_completion_request_mismatch', $e->errorCode); }
    }

    public function test_preview_and_verification_are_nonlocking_read_paths_with_complete_mysql_grammar_fixture(): void
    {
        $this->apply(); // Include saved-version and publication-proof branches, not only legacy plans.
        $connection = DB::connection(); $grammar = $connection->getQueryGrammar();
        $connection->getPdo()->sqliteCreateFunction('json_unquote', fn ($value) => $value);
        $connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection));
        $before = $this->revision(); $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        try {
            $this->service()->preview($this->actor(), new ApprovedCatalogManifest);
            $this->service()->verify($this->actor(), new ApprovedCatalogManifest);
            self::assertSame($before, $this->revision());
            self::assertNotEmpty($queries);
            foreach ($queries as $sql) {
                self::assertDoesNotMatchRegularExpression('/for update|lock in share mode/i', $sql);
                self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
            }
        } finally { $connection->setQueryGrammar($grammar); }
    }

    private function changeDefault(int $program = 40): int
    {
        $workflow = app(AcademicPlanWorkflow::class); $source = AcademicPlanContext::forProgram($program)->versionId;
        $copy = $workflow->copy($this->actor(), $program, $source, ['revision' => $this->revision(), 'label' => 'Synthetic other writer']);
        $id = (int) $copy['version']->getKey();
        $workflow->approve($this->actor(), $program, $id, ['revision' => $this->revision(), 'confirmed' => true]);
        $workflow->setDefault($this->actor(), $program, $id, ['revision' => $this->revision(), 'confirmed' => true]);
        return $id;
    }

    /** Single-connection deterministic stage seam; independent connections are tested in MariaDB. */
    private function beforeAssignments(callable $action): void
    {
        $armed = true;
        DB::listen(function ($q) use (&$armed, $action) {
            if ($armed && preg_match('/^select ["`]student_id["`] from ["`]students["`] where ["`]academic_program_id["`] = \? order by/i', $q->sql)
                && (int) ($q->bindings[0] ?? 0) === 40) { $armed = false; $action(); }
        });
    }

    public function test_civil_historical_readers_keep_recorded_codes_and_expose_corrected_catalog_without_changing_results(): void
    {
        foreach ([780, 892] as $id) {
            DB::table('program_courses')->insert(['academic_program_id' => 28, 'course_id' => $id, 'course_type' => 'mandatory']);
            DB::table('course_offerings')->insert(['course_offering_id' => $id, 'academic_program_id' => 28, 'course_id' => $id, 'academic_year_id' => 1, 'semester_id' => 1]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $id, 'student_id' => 1028, 'course_offering_id' => $id, 'registration_status_id' => 1]);
            DB::table('student_course_results')->insert(['student_course_registration_id' => $id, 'result_status_id' => 1, 'final_mark' => 80, 'theoretical_total' => 80]);
            DB::table('grade_approvals')->insert(['course_offering_id' => $id, 'approval_status_id' => 1]);
        }
        $grades = app(GradeService::class); $student = fn () => Student::findOrFail(1028);
        $before = $grades->getTranscript($student()); $gpa = $grades->calculateGpa($student(), 1, 1);
        $records = app(\App\Services\ExamStudentAcademicRecordService::class);
        self::assertSame($before, $records->snapshot($student(), $this->actor())['transcript']);
        $results = DB::table('student_course_results')->get()->toArray();
        app(AcademicPlanWorkflow::class)->fixTransition($this->actor(), 28, ['revision' => $this->revision(), 'confirmed' => true]);
        $fields = fn () => collect([780, 892])->map(fn ($id) => app(\App\Services\CatalogCompletion\CatalogCompletionProjection::class)->fields(\App\Models\Course::findOrFail($id)))->all();
        $proof = ['manifest_hash' => ApprovedCatalogManifest::SHA256, 'drawing_id' => 780, 'chemistry_id' => 892, 'before' => $fields(),
            'affected_memberships' => DB::table('program_courses')->where('academic_program_id', 28)->get()->toArray(),
            'recorded_registrations' => DB::table('student_course_registrations as r')->join('course_offerings as o', 'o.course_offering_id', '=', 'r.course_offering_id')
                ->where('r.student_id', 1028)->get(['r.student_course_registration_id', 'o.course_id', 'r.academic_plan_version_id', 'r.plan_program_course_id'])->toArray()];
        // Projection-only SQLite fixture; the exact permit/correction writer is exercised by the MariaDB harness.
        DB::table('courses')->where('course_id', 780)->update(['course_code' => 'SYN-SWAP']);
        DB::table('courses')->where('course_id', 892)->update(['course_code' => 'FE114']);
        DB::table('courses')->where('course_id', 780)->update(['course_code' => 'FE113']);
        DB::table('academic_plan_events')->insert(['academic_program_id' => 28, 'action' => 'catalog_completion_code_applied', 'actor_user_id' => 1,
            'context' => json_encode($proof + ['after' => $fields()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $after = $grades->getTranscript($student());
        self::assertSame($after, $records->snapshot($student(), $this->actor())['transcript']);
        $oldCourses = collect($before['terms'][0]['courses'])->keyBy('course_id');
        foreach ($after['terms'][0]['courses'] as $course) {
            $id = $course['course_id'];
            self::assertSame($oldCourses[$id]['course_code'], $course['course_code']);
            self::assertSame($course['course_code'], $course['recorded_course_code']);
            self::assertSame($id === 780 ? 'FE113' : 'FE114', $course['current_course_code']);
            self::assertSame($oldCourses[$id]['grades'], $course['grades']);
            self::assertSame($oldCourses[$id]['course_name'], $course['course_name']);
            $registration = \App\Models\StudentCourseRegistration::with('courseOffering.course')->findOrFail($id);
            $resource = (new \App\Http\Resources\RegistrationSummaryItemResource($registration))->resolve();
            self::assertSame($course['recorded_course_code'], $resource['course_code']);
            self::assertSame($course['current_course_code'], $grades->getRegistrationGrades($id)['course']['current_course_code']);
        }
        self::assertEquals($results, DB::table('student_course_results')->get()->toArray());
        self::assertSame($after, $grades->getTranscript($student()));
        self::assertSame($gpa['gpa'], $grades->calculateGpa($student(), 1, 1)['gpa']);
        // A new registration on even the same pinned plan is not claimed to predate the correction.
        $old = \App\Models\StudentCourseRegistration::findOrFail(780);
        DB::table('course_offerings')->insert(['course_offering_id' => 1780, 'academic_program_id' => 28, 'course_id' => 780, 'academic_year_id' => 1, 'semester_id' => 2]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 1780, 'student_id' => 1028, 'course_offering_id' => 1780,
            'registration_status_id' => 1, 'academic_plan_version_id' => $old->academic_plan_version_id, 'plan_program_course_id' => $old->plan_program_course_id]);
        $new = \App\Support\HistoricalCourseCode::fields(\App\Models\StudentCourseRegistration::findOrFail(1780));
        self::assertSame('FE113', $new['course_code']); self::assertNull($new['recorded_course_code']);
        self::assertSame('FE114', $new['original_plan_course_code']);
        self::assertSame('FE113', \App\Models\Course::findOrFail(780)->course_code);
        self::assertSame(array_column($before['terms'][0]['courses'], 'course_id'), array_column($after['terms'][0]['courses'], 'course_id'));
        $historyQueries = 0;
        DB::listen(function ($q) use (&$historyQueries) { if (in_array('catalog_completion_code_applied', $q->bindings, true)) $historyQueries++; });
        $references = \App\Support\HistoricalCourseCode::index([780, 892, 1780]);
        self::assertCount(3, $references); self::assertSame(1, $historyQueries);
        // Actual student HTTP reader and middleware; no response interception or mocked grade service.
        $permission = DB::table('permissions')->insertGetId(['permission_code' => 'grades.view'], 'permission_id');
        DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $permission]);
        DB::table('users')->where('user_id', 1)->update(['student_id' => 1028]);
        \Laravel\Sanctum\Sanctum::actingAs($this->actor());
        $this->getJson('/api/v1/student/transcript')->assertOk()
            ->assertJsonPath('data.terms.0.courses.0.course_id', 892)
            ->assertJsonPath('data.terms.0.courses.0.course_code', 'FE113')
            ->assertJsonPath('data.terms.0.courses.0.current_course_code', 'FE114')
            ->assertJsonPath('data.terms.0.courses.0.course_code_reference', 'recorded_registration');
    }

    public function test_interrupted_resume_revalidates_saved_stage_and_keeps_other_writers_default(): void
    {
        $operation = (string) Str::uuid();
        $this->beforeAssignments(fn () => throw new \RuntimeException('synthetic_interruption'));
        try { $this->apply($operation); self::fail('Expected interruption'); }
        catch (\RuntimeException $e) { self::assertSame('synthetic_interruption', $e->getMessage()); }
        $alternate = $this->changeDefault();
        $before = DB::table('academic_plan_versions')->count();
        $r = $this->service()->apply($this->actor(), new ApprovedCatalogManifest, $this->revision(), $operation, true);
        $group = collect($r['groups'])->first(fn ($g) => in_array(40, $g['program_ids'], true));
        self::assertSame('BLOCKED', $group['status']);
        self::assertContains('catalog_completion_default_changed', $group['blockers']);
        self::assertSame($alternate, AcademicPlanContext::forProgram(40)->versionId);
        self::assertSame('transitional', DB::table('academic_plan_versions')->where('academic_plan_version_id', AcademicPlanContext::forStudent(Student::findOrFail(1040))->versionId)->value('status'));
        self::assertSame($before, DB::table('academic_plan_versions')->count());
    }

    public function test_default_change_after_publication_blocks_new_assignments_without_restoring_default(): void
    {
        $alternate = null; $this->beforeAssignments(function () use (&$alternate) { $alternate = $this->changeDefault(); });
        $r = $this->apply();
        self::assertNotNull($alternate);
        self::assertContains('catalog_completion_default_changed', $r['students'][40]['blocked'][0]['blockers']);
        self::assertContains('catalog_completion_default_changed', $r['students'][40]['context_blockers']);
        self::assertSame($alternate, AcademicPlanContext::forProgram(40)->versionId);
        self::assertNotSame('COMPLETE', $r['status']);
    }

    public function test_final_confirmation_rechecks_default_after_last_assignment_and_pure_replay_stays_readonly(): void
    {
        $armed = true; $alternate = null;
        DB::listen(function ($q) use (&$armed, &$alternate) {
            if ($armed && str_starts_with($q->sql, 'insert into "user_activity_logs"') && in_array('catalog_completion.assignment', $q->bindings, true)) {
                foreach ($q->bindings as $value) if (is_string($value) && str_contains($value, '"student_ids":[1042]')) {
                    $armed = false; $alternate = $this->changeDefault(42); break;
                }
            }
        });
        $operation = (string) Str::uuid(); $r = $this->apply($operation);
        self::assertNotNull($alternate);
        self::assertSame(0, $r['students'][42]['remaining_count_at_confirmation']);
        self::assertContains('catalog_completion_default_changed', $r['students'][42]['context_blockers']);
        self::assertNotSame('COMPLETE', $r['status']);
        $counts = [DB::table('academic_plan_events')->count(), DB::table('student_academic_plan_assignments')->count(), DB::table('user_activity_logs')->count()];
        self::assertSame($r, $this->service()->apply($this->actor(), new ApprovedCatalogManifest, '0', $operation));
        self::assertSame($counts, [DB::table('academic_plan_events')->count(), DB::table('student_academic_plan_assignments')->count(), DB::table('user_activity_logs')->count()]);
    }

    public function test_publication_validation_rejects_changed_content_and_historical_source_without_writes(): void
    {
        $this->apply();
        $id = AcademicPlanContext::forProgram(40)->versionId;
        $source = DB::table('academic_plan_versions')->where('academic_plan_version_id', $id)->value('source_version_id');
        $target = ['academic_program_id' => 40, 'academic_plan_version_id' => $id];
        $events = DB::table('academic_plan_events')->count();
        $validate = fn () => app(AcademicCatalogTransaction::class)->snapshot(fn () => app(\App\Services\CatalogCompletion\CatalogCompletionPublication::class)
            ->assertTarget($this->actor(), $target, ApprovedCatalogManifest::SHA256));
        // SQLite-only corruption probes; installed MariaDB guards are never disabled to create these states.
        DB::table('academic_plan_versions')->where('academic_plan_version_id', $id)->update(['total_credit_hours' => 140]);
        try { $validate(); self::fail('Changed publication accepted'); }
        catch (AcademicPlanException $e) { self::assertSame('catalog_completion_published_content_changed', $e->errorCode); }
        DB::table('academic_plan_versions')->where('academic_plan_version_id', $id)->update(['total_credit_hours' => 141]);
        DB::table('academic_requirement_groups')->insert(['academic_program_id' => 40, 'academic_plan_version_id' => $source,
            'group_code' => 'SYN-CORRUPTED-SOURCE', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => null]);
        try { $validate(); self::fail('Changed source accepted'); }
        catch (AcademicPlanException $e) { self::assertSame('catalog_completion_historical_source_changed', $e->errorCode); }
        self::assertSame($events, DB::table('academic_plan_events')->count());
    }
}
