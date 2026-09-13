<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AcademicCatalogTransaction;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ScientificCourseDistributionTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/scientific/course-management';
    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\ScientificCatalogFixture::initialize();
        Sanctum::actingAs(User::findOrFail(1));
        DB::table('departments')->insert(['department_id' => 3, 'college_id' => 1, 'department_name' => 'قسم ثالث']);
        DB::table('academic_programs')->insert(['academic_program_id' => 3, 'department_id' => 3, 'program_name' => 'برنامج ثالث']);
        foreach ([1, 2, 3] as $id) foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            DB::table('academic_requirement_groups')->insert(['academic_program_id' => $id, 'group_code' => "$id-$scope-$type", 'group_name' => 'متطلب',
                'requirement_scope' => $scope, 'requirement_type' => $type, 'required_credit_hours' => 3]);
        }
    }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function body(array $scope): array
    {
        return ['revision' => $this->revision(), 'course_code' => 'NEW', 'course_name' => 'جديدة', 'credit_hours' => 3, 'is_active' => true,
            'departments' => [['department_id' => 1, 'is_primary' => true]], 'distribution' => $scope, 'distribution_confirmed' => true,
            'academic_level_id' => 1, 'recommended_semester_id' => 1];
    }
    private function state(): array
    {
        return collect(['courses', 'program_courses', 'program_course_requirement_groups', 'academic_requirement_groups', 'user_activity_logs', 'academic_catalog_control'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
    }
    public function test_university_college_and_department_select_all_existing_programs_not_a_client_subset(): void
    {
        foreach ([['scope' => 'university'], ['scope' => 'college', 'college_id' => 1], ['scope' => 'department', 'college_id' => 1, 'department_id' => 3]] as $i => $scope) {
            $scope['course_type'] = $i === 1 ? 'elective' : 'mandatory';
            $before = $this->state();
            $preview = $this->getJson(self::URL.'/distribution-preview?'.http_build_query($scope))->assertOk()->assertJsonPath('data.can_apply', true)->json('data');
            self::assertSame($before, $this->state(), 'Preview writes nothing');
            self::assertSame([[1, 2, 3], [1, 3], [3]][$i], array_column($preview['targets'], 'academic_program_id'));
            $body = $this->body($scope); $body['course_code'] .= $i;
            $id = $this->postJson(self::URL.'/courses', $body)->assertOk()->json('data.data.course_id');
            self::assertSame([[1, 2, 3], [1, 3], [3]][$i], DB::table('program_courses')->where('course_id', $id)->orderBy('academic_program_id')->pluck('academic_program_id')->all());
            self::assertSame([$scope['course_type']], DB::table('program_courses')->where('course_id', $id)->distinct()->pluck('course_type')->all());
            self::assertSame($before['academic_requirement_groups'], $this->state()['academic_requirement_groups']);
        }
        self::assertSame(3, DB::table('user_activity_logs')->where('action_code', 'scientific_catalog.course.distribute')->count());
    }
    public function test_used_or_unconfigured_target_blocks_the_whole_operation_without_partial_course_or_audit(): void
    {
        $scope = ['scope' => 'university', 'course_type' => 'mandatory'];
        foreach (['history', 'groups'] as $case) {
            if ($case === 'history') DB::table('students')->insert(['academic_program_id' => 2]);
            else { DB::table('students')->delete(); DB::table('academic_requirement_groups')->where('academic_program_id', 2)->delete(); }
            $before = $this->state();
            $this->getJson(self::URL.'/distribution-preview?'.http_build_query($scope))->assertOk()->assertJsonPath('data.can_apply', false);
            $this->postJson(self::URL.'/courses', $this->body($scope))->assertUnprocessable()->assertJsonValidationErrors('distribution');
            self::assertSame($before, $this->state());
        }
    }
    public function test_new_target_after_preview_requires_explicit_revision_refresh(): void
    {
        $body = $this->body(['scope' => 'university', 'course_type' => 'mandatory']);
        DB::table('academic_programs')->insert(['academic_program_id' => 4, 'department_id' => 1, 'program_name' => 'جديد بعد المعاينة']);
        $before = $this->state();
        $this->postJson(self::URL.'/courses', $body)->assertConflict()->assertJsonPath('error_code', 'academic_catalog_stale');
        self::assertSame($before, $this->state());
    }
    public function test_scope_pairing_explicit_confirmation_and_no_partial_scope_authority(): void
    {
        $scope = ['scope' => 'department', 'college_id' => 1, 'department_id' => 2, 'course_type' => 'mandatory'];
        $this->getJson(self::URL.'/distribution-preview?'.http_build_query($scope))->assertUnprocessable();
        $body = $this->body(['scope' => 'university', 'course_type' => 'mandatory']); unset($body['distribution_confirmed']);
        $this->postJson(self::URL.'/courses', $body)->assertUnprocessable();
        DB::table('user_access_scopes')->update(['scope_type' => 'program', 'scope_id' => 1]);
        $this->getJson(self::URL.'/distribution-preview?scope=university&course_type=mandatory')->assertForbidden();
        $this->getJson(self::URL.'/distribution-preview?scope=college&college_id=1&course_type=mandatory')->assertForbidden();
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }
    public function test_audit_failure_rolls_back_the_course_and_every_membership(): void
    {
        DB::unprepared("CREATE TRIGGER fail_distribution BEFORE INSERT ON user_activity_logs WHEN NEW.action_code='scientific_catalog.course.distribute' BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");
        $before = $this->state();
        $this->postJson(self::URL.'/courses', $this->body(['scope' => 'university', 'course_type' => 'mandatory']))->assertServerError();
        self::assertSame($before, $this->state());
    }
    public function test_preview_queries_are_bounded_as_program_count_increases(): void
    {
        $count = function () { DB::enableQueryLog(); DB::flushQueryLog(); $this->getJson(self::URL.'/distribution-preview?scope=university&course_type=mandatory')->assertOk(); $n = count(DB::getQueryLog()); DB::disableQueryLog(); return $n; };
        $count(); // Warm framework/schema metadata, not dependent on target count.
        $before = $count();
        foreach (range(4, 25) as $id) DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => 1, 'program_name' => "P$id"]);
        self::assertSame($before, $count());
    }
    public function test_instructor_read_projection_is_safe_and_includes_role_not_contact_data(): void
    {
        DB::table('employees')->insert(['employee_id' => 1, 'first_name' => 'مدرس', 'last_name' => 'اختبار']);
        DB::table('faculty_members')->insert(['faculty_member_id' => 1, 'employee_id' => 1]);
        DB::table('course_instructors')->insert(['course_id' => 1, 'faculty_member_id' => 1, 'is_primary' => 1]);
        $row = $this->getJson(self::URL.'/courses?q=C1')->assertOk()->json('data.data.0.instructors.0');
        self::assertSame(['course_id', 'faculty_member_id', 'is_primary', 'is_active', 'first_name', 'last_name'], array_keys($row));
        self::assertSame('مدرس', $row['first_name']);
    }
    public function test_course_filter_does_not_hide_other_visible_associations_and_scope_still_filters_them(): void
    {
        foreach ([1, 2] as $program) DB::table('program_courses')->insert(['course_id' => 1, 'academic_program_id' => $program, 'course_type' => 'mandatory']);
        $rows = $this->getJson(self::URL.'/courses?academic_program_id=1')->assertOk()->json('data.data');
        self::assertCount(2, $rows[0]['program_courses']);
        self::assertSame(2, $rows[0]['program_courses'][1]['academic_program']['department']['college']['college_id']);
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::URL.'/courses?academic_program_id=1')->assertOk()->assertJsonCount(1, 'data.data.0.program_courses');
    }
    public function test_inactive_hierarchy_and_unknown_fields_are_not_silently_skipped(): void
    {
        DB::table('departments')->where('department_id', 3)->update(['is_active' => false]);
        $scope = ['scope' => 'college', 'college_id' => 1, 'course_type' => 'mandatory'];
        $this->getJson(self::URL.'/distribution-preview?'.http_build_query($scope))->assertOk()->assertJsonPath('data.program_count', 2)->assertJsonPath('data.can_apply', false);
        $before = $this->state();
        $this->postJson(self::URL.'/courses', $this->body($scope))->assertUnprocessable();
        $this->postJson(self::URL.'/courses', $this->body($scope + ['program_ids' => [1]]))->assertUnprocessable();
        self::assertSame($before, $this->state());
    }
}
