<?php

namespace Tests\Feature;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicRequirementService;
use App\Services\CatalogCompletion\ApprovedCatalogManifest;
use App\Services\ExamStudentAcademicRecordService;
use App\Services\UserIdentityService;
use App\Support\HistoricalExamSchema;
use App\Support\OfficialGradeScale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ExamTranscriptFixture;
use Tests\Support\SyntheticApprovedExamSources;
use Tests\TestCase;

class ExamTranscriptExportTest extends TestCase
{
    private const URL = '/api/v1/students/1/exam-transcript';

    protected function setUp(): void
    {
        parent::setUp();
        ExamTranscriptFixture::install();
        $requirements = $this->createMock(AcademicRequirementService::class);
        $requirements->method('getStudentRequirementProgress')->willThrowException(
            new AcademicRequirementConfigurationException('Synthetic unavailable plan.')
        );
        $this->app->instance(AcademicRequirementService::class, $requirements);
        $identity = $this->createMock(UserIdentityService::class);
        $identity->method('documentGenerator')->willReturn(['display_name' => 'موظف امتحانات اصطناعي']);
        $this->app->instance(UserIdentityService::class, $identity);
        Sanctum::actingAs(User::findOrFail(1));
    }

    public function test_weighted_running_gpa_repeated_attempts_historical_level_and_no_writes(): void
    {
        DB::table('courses')->insert(['course_id' => 2, 'course_code' => 'M-102', 'course_name' => 'الرياضيات', 'credit_hours' => 2]);
        $first = ExamTranscriptFixture::attempt(1, 1, 1, 80);
        ExamTranscriptFixture::attempt(2, 1, 1, 50);
        ExamTranscriptFixture::attempt(1, 1, 2, 98);
        // Persisted GPA is deliberately stale; only the historical level is read.
        DB::table('student_academic_terms')->insert([
            'student_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1,
            'academic_level_id' => 1, 'term_gpa' => 0, 'cumulative_gpa' => 0,
        ]);
        // Final marks remain authoritative even when old component totals differ.
        DB::table('student_course_results')->where('student_course_registration_id', $first)->update(['theoretical_total' => 40]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->getJson(self::URL)->assertOk();
        $terms = $response->json('data.exam_export.terms');
        self::assertSame(2.4, $terms[0]['term_gpa']);
        self::assertSame(2.4, $terms[0]['cumulative_gpa']);
        self::assertSame(4, $terms[1]['term_gpa']);
        self::assertSame(3, $terms[1]['cumulative_gpa']);
        self::assertSame('المستوى الأول', $terms[0]['historical_academic_level']);
        self::assertNull($terms[1]['historical_academic_level']);
        self::assertSame(80, $terms[0]['courses'][0]['final_mark']);
        self::assertSame('B', $terms[0]['courses'][0]['letter_grade']);
        self::assertEquals(OfficialGradeScale::BANDS, $response->json('data.exam_export.grade_scale.bands'));
        self::assertNull($response->json('data.exam_export.identity.place_of_birth'));
        self::assertSame('2002-03-14', $response->json('data.exam_export.identity.date_of_birth'));
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
        }
        self::assertSame(1, DB::table('student_academic_terms')->count());
        self::assertSame(0.0, (float) DB::table('student_academic_terms')->value('cumulative_gpa'));
        $this->evidence('short', $response->json('data'));
    }

    public function test_f_z_i_w_missing_identity_and_empty_gpa_remain_truthful(): void
    {
        DB::table('students')->where('student_id', 1)->update(['father_name' => null, 'mother_name' => null, 'date_of_birth' => null, 'gender' => null, 'nationality' => null]);
        foreach (['failed', 'deprived', 'incomplete', 'withdrawn'] as $index => $status) {
            $course = $index + 10;
            DB::table('courses')->insert(['course_id' => $course, 'course_code' => 'S-'.$course, 'course_name' => 'حالة اصطناعية '.$status, 'credit_hours' => 3]);
            ExamTranscriptFixture::attempt($course, 1, 1, null, $status);
        }
        $response = $this->getJson(self::URL)->assertOk();
        $courses = $response->json('data.exam_export.terms.0.courses');
        self::assertSame(['F', 'Z', 'I', 'W'], array_column($courses, 'letter_grade'));
        self::assertSame([null, null, null, null], array_column($courses, 'final_mark'));
        self::assertSame([0, 0, null, null], array_column($courses, 'grade_points'));
        self::assertNull($response->json('data.exam_export.identity.father_name'));
        $this->evidence('states-missing', $response->json('data'));
        DB::table('student_course_registrations')->where('student_id', 1)->where('registration_status_id', '<>', 5)->delete();
        $onlyWithdrawal = $this->getJson(self::URL)->assertOk();
        self::assertNull($onlyWithdrawal->json('data.exam_export.terms.0.term_gpa'));
        self::assertNull($onlyWithdrawal->json('data.exam_export.terms.0.cumulative_gpa'));
        $this->evidence('withdrawal-only', $onlyWithdrawal->json('data'));
    }

    public function test_export_requires_exam_office_grades_permission_and_student_scope(): void
    {
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'registration_officer']);
        $this->getJson(self::URL)->assertForbidden();
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'exam_officer']);
        DB::table('role_permissions')->where('permission_id', 4)->delete();
        $this->getJson(self::URL)->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => 4]);
        DB::table('user_access_scopes')->delete();
        $this->getJson(self::URL)->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'program', 'scope_id' => 999, 'is_active' => 1]);
        $this->getJson(self::URL)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_attempt_hours_precede_pinned_plan_and_origin_and_recorded_code_survives(): void
    {
        Schema::create('academic_catalog_control', function (Blueprint $table): void {
            $table->integer('control_id')->primary();
            $table->integer('schema_version');
            $table->integer('revision');
            $table->boolean('is_ready');
        });
        DB::table('academic_catalog_control')->insert(['control_id' => 1, 'schema_version' => 1, 'revision' => 1, 'is_ready' => true]);
        Schema::create('academic_plan_events', function (Blueprint $table): void {
            $table->increments('academic_plan_event_id');
            $table->string('action');
            $table->text('context');
        });
        Schema::create('program_course_requirement_groups', function (Blueprint $table): void {
            $table->increments('program_course_requirement_group_id');
            $table->integer('program_course_id');
            $table->integer('requirement_group_id');
        });
        Schema::table('student_course_registrations', function (Blueprint $table): void {
            $table->integer('academic_plan_version_id')->nullable();
            $table->integer('plan_program_course_id')->nullable();
        });
        SyntheticApprovedExamSources::schema();
        DB::table('courses')->insert(['course_id' => 780, 'course_code' => 'FE113', 'course_name' => 'الرسم الهندسي — مثال تاريخي اصطناعي', 'credit_hours' => 3]);
        $first = ExamTranscriptFixture::attempt(780, 1, 1, 98);
        $second = ExamTranscriptFixture::attempt(1, 1, 1, 50);
        foreach ([[$first, 780, 6], [$second, 1, 2]] as [$registration, $course, $hours]) {
            $membership = DB::table('program_courses')->insertGetId(['academic_program_id' => 1, 'course_id' => $course, 'is_active' => true], 'program_course_id');
            DB::table('student_course_registrations')->where('student_course_registration_id', $registration)->update(['plan_program_course_id' => $membership]);
            DB::table(HistoricalExamSchema::PLAN)->insert([
                'program_course_id' => $membership, 'revision' => 1, 'credit_hours' => $hours, 'academic_plan_event_id' => 1, 'created_at' => now(),
            ]);
        }
        DB::table(HistoricalExamSchema::CREDITS)->insert([
            'student_course_registration_id' => $first, 'revision' => 1, 'credit_hours' => 4,
            'basis' => 'synthetic_recorded_attempt', 'work_item_id' => null,
            'operation_id' => 'synthetic-operation', 'action_id' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'actor_user_id' => 1, 'created_at' => now(),
        ]);
        DB::table('academic_plan_events')->insert([
            'action' => 'catalog_completion_code_applied',
            'context' => json_encode([
                'manifest_hash' => ApprovedCatalogManifest::SHA256, 'drawing_id' => 780, 'chemistry_id' => 892,
                'before' => [['course_code' => 'FE114'], ['course_code' => 'FE113']],
                'after' => [['course_code' => 'FE113'], ['course_code' => 'FE114']],
                'affected_memberships' => [],
                'recorded_registrations' => [[
                    'student_course_registration_id' => $first, 'course_id' => 780, 'academic_plan_version_id' => null,
                    'plan_program_course_id' => DB::table('student_course_registrations')->where('student_course_registration_id', $first)->value('plan_program_course_id'),
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $queries = [];
        $historyReadLevels = [];
        DB::listen(function ($query) use (&$queries, &$historyReadLevels): void {
            $queries[] = $query->sql;
            if (str_contains($query->sql, 'student_academic_terms')) {
                $historyReadLevels[] = $query->connection->transactionLevel();
            }
        });
        $response = $this->getJson(self::URL)->assertOk();
        $courses = collect($response->json('data.exam_export.terms.0.courses'))->keyBy('course_id');
        self::assertSame(4, $courses[780]['credit_hours']);
        self::assertSame('recorded_attempt_credit', $courses[780]['credit_hours_reference']);
        self::assertSame('FE114', $courses[780]['course_code']);
        self::assertSame('FE113', $courses[780]['current_course_code']);
        self::assertSame(2, $courses[1]['credit_hours']);
        self::assertSame('pinned_plan_membership_credit', $courses[1]['credit_hours_reference']);
        self::assertSame(3.17, $response->json('data.exam_export.terms.0.term_gpa'));
        self::assertSame(3, DB::table('courses')->where('course_id', 780)->value('credit_hours'));
        self::assertSame([1], $historyReadLevels);
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
        }
        $this->evidence('historical', $response->json('data'));
    }

    public function test_other_offices_and_self_snapshot_do_not_receive_exam_presentation(): void
    {
        ExamTranscriptFixture::attempt(1, 1, 1, 80);
        $this->getJson('/api/v1/students/1/academic-record')->assertOk()->assertJsonMissingPath('data.exam_export');
        $payload = app(ExamStudentAcademicRecordService::class)->snapshot(Student::findOrFail(1), User::findOrFail(1));
        self::assertArrayNotHasKey('exam_export', $payload);
    }

    public function test_six_years_summer_extra_courses_and_long_names_are_all_exported(): void
    {
        $expected = 0;
        for ($year = 1; $year <= 6; $year++) {
            if ($year > 1) {
                DB::table('academic_years')->insert([
                    'academic_year_id' => $year, 'year_name' => (2024 + $year).'/'.(2025 + $year),
                    'start_date' => (2025 + $year).'-01-01', 'end_date' => (2025 + $year).'-12-31',
                ]);
            }
            foreach ([1, 2, 3] as $semester) {
                $count = $year === 1 && $semester === 1 ? 30 : 6;
                for ($index = 0; $index < $count; $index++) {
                    $course = 100 + $expected;
                    DB::table('courses')->insert([
                        'course_id' => $course, 'course_code' => 'SYN-'.$year.'-'.$semester.'-'.$index,
                        'course_name' => $index % 4 === 0
                            ? 'التصميم والتحليل المتقدم للمنظومات الحاسوبية والشبكات والاتصالات الرقمية وتطبيقاتها العملية في هندسة تقانة المعلومات'
                            : 'مقرر اصطناعي '.$index,
                        'credit_hours' => 2 + ($index % 2),
                    ]);
                    ExamTranscriptFixture::attempt($course, $year, $semester, $index % 3 === 0 ? 87.5 : 80);
                    $expected++;
                }
            }
        }
        $response = $this->getJson(self::URL)->assertOk();
        $terms = $response->json('data.exam_export.terms');
        self::assertCount(18, $terms);
        self::assertSame($expected, array_sum(array_map(fn (array $term): int => count($term['courses']), $terms)));
        self::assertSame('الفصل الصيفي', $terms[2]['semester']['semester_name']);
        $this->evidence('long', $response->json('data'));
    }

    private function evidence(string $name, array $snapshot): void
    {
        $directory = getenv('EXAM_TRANSCRIPT_EVIDENCE_DIR');
        if ($directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$name.'.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
