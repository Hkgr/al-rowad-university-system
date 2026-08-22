<?php

namespace Tests\Feature;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\AccountStatus;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Permission;
use App\Models\ProgramCourse;
use App\Models\RegistrationStatus;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingEvent;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamResult;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Models\UserRole;
use App\Support\SupplementaryExamOfferingGovernance;
use App\Support\SupplementaryExamPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplementaryExamOfferingFeatureTest extends TestCase
{
    private AccountStatus $activeAccount;

    private College $collegeA;

    private College $collegeB;

    private AcademicProgram $programA;

    private AcademicProgram $programB;

    private AcademicYear $year;

    private AcademicYear $otherYear;

    private Semester $summer;

    private Semester $first;

    private Semester $second;

    private Course $course;

    private Course $curriculumOnly;

    private Course $practicalOnly;

    private User $dean;

    private User $otherDean;

    private array $statuses = [];

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seedWorld();
    }

    public function test_supp_offer_01_dean_with_manage_may_open(): void
    {
        $period = $this->announcedPeriod($this->first);
        $source = $this->genuineOffering($this->first, 'registered');

        $response = $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period));

        $response->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
        $this->assertTrue(SupplementaryExamOfferingSource::query()->where('course_offering_id', $source->course_offering_id)->exists());
    }

    public function test_supp_offer_02_dean_without_manage_denied(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        RolePermission::query()
            ->whereHas('permission', fn ($permission) => $permission->where('permission_code', 'supplementary_exams.offerings.manage'))
            ->delete();

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertForbidden();
        $this->assertSame(0, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_03_super_admin_virtual_cannot_mutate(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $admin = $this->makeUser('root', 'super_admin', [], $this->collegeA);

        $this->assertTrue($admin->hasPermission(SupplementaryExamOfferingGovernance::PERMISSION_MANAGE));
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertForbidden();
        $this->assertFalse($admin->isDean());
        $this->assertSame(0, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_04_scientific_vp_cannot_manage(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $vp = $this->makeUser('sci-vp', 'vice_president_scientific', ['supplementary_exams.periods.decide'], $this->collegeA);

        $this->actingAs($vp, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertForbidden();
    }

    public function test_supp_offer_05_administrative_vp_denied(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $vp = $this->makeUser('adm-vp', 'vice_president_administrative', [], $this->collegeA);

        $this->actingAs($vp, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertForbidden();
    }

    public function test_supp_offer_06_wrong_college_dean_denied(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');

        $this->actingAs($this->otherDean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'supplementary_exam_program_out_of_scope');
    }

    public function test_supp_offer_07_legacy_period_cannot_be_managed(): void
    {
        $period = $this->period($this->first, 'legacy');
        $this->genuineOffering($this->first, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'supplementary_exam_period_not_manageable');
    }

    public function test_supp_offer_08_non_announced_period_cannot_be_managed(): void
    {
        $period = $this->period($this->first, 'registration_open');
        $this->genuineOffering($this->first, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'supplementary_exam_period_not_manageable');
    }

    public function test_supp_offer_09_order_1_accepts_only_same_semester(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->second, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');
    }

    public function test_supp_offer_10_order_2_accepts_only_same_semester(): void
    {
        $period = $this->announcedPeriod($this->second);
        $this->genuineOffering($this->first, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');
    }

    public function test_supp_offer_11_order_3_accepts_orders_1_2_3(): void
    {
        $period = $this->announcedPeriod($this->summer);
        $this->genuineOffering($this->first, 'registered');
        $this->genuineOffering($this->second, 'completed');
        $this->genuineOffering($this->summer, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
        $this->assertSame(3, SupplementaryExamOfferingSource::query()->count());
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_12_source_year_must_equal_period_year(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered', year: $this->otherYear);

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');
    }

    public function test_supp_offer_13_semester_id_is_not_summer_policy(): void
    {
        $this->assertNotSame(3, (int) $this->summer->semester_id);
        $this->assertSame(3, (int) $this->second->semester_id);
        $this->assertSame([1, 2, 3], SupplementaryExamPolicy::allowedSourceSemesterOrdersForOrder((int) $this->summer->semester_order));
        $this->assertSame([2], SupplementaryExamPolicy::allowedSourceSemesterOrdersForOrder((int) $this->second->semester_order));

        $period = $this->announcedPeriod($this->second);
        $this->genuineOffering($this->summer, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422);
    }

    public function test_supp_offer_14_unsupported_semester_order_fails_closed(): void
    {
        $unsupported = Semester::query()->create([
            'semester_code' => 'x', 'semester_name' => 'Other', 'semester_order' => 9, 'is_active' => true,
        ]);
        $period = $this->announcedPeriod($unsupported);
        $this->genuineOffering($this->first, 'registered');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_unsupported_semester_policy');
    }

    public function test_supp_offer_15_program_course_without_offering_is_not_candidate(): void
    {
        $period = $this->announcedPeriod($this->first);
        ProgramCourse::query()->create([
            'academic_program_id' => $this->programA->academic_program_id,
            'course_id' => $this->curriculumOnly->course_id,
            'is_active' => true,
        ]);

        $catalog = $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/dean/supplementary-exam-offerings/catalog?'.$this->catalogQuery($period))
            ->assertOk()
            ->json('data.available_courses');
        $ids = collect($catalog)->pluck('course_id')->all();
        $this->assertNotContains($this->curriculumOnly->course_id, $ids);
    }

    public function test_supp_offer_16_prepared_offering_without_registration_is_not_candidate(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->offering($this->first, status: 'open');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');
    }

    public function test_supp_offer_17_dropped_only_is_not_candidate(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'dropped');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422);
    }

    public function test_supp_offer_18_withdrawn_only_is_not_candidate(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'withdrawn');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(422);
    }

    public function test_supp_offer_19_registered_makes_source_genuine(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
    }

    public function test_supp_offer_20_completed_makes_source_genuine(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'completed');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
    }

    public function test_supp_offer_21_source_may_currently_be_closed(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered', offeringStatus: 'closed');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
    }

    public function test_supp_offer_22_and_23_one_offering_attaches_all_sources(): void
    {
        $period = $this->announcedPeriod($this->summer);
        $a = $this->genuineOffering($this->first, 'registered');
        $b = $this->genuineOffering($this->second, 'completed');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
        $ids = SupplementaryExamOfferingSource::query()->pluck('course_offering_id')->sort()->values()->all();
        $this->assertSame([$a->course_offering_id, $b->course_offering_id], $ids);
    }

    public function test_supp_offer_24_source_ids_cannot_be_client_supplied(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period) + [
                'source_course_offering_ids' => [999],
                'college_id' => $this->collegeA->college_id,
                'status' => 'closed',
            ])
            ->assertStatus(422);
        $this->assertSame(0, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_25_and_26_identity_unique_and_race_conflict(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'supplementary_exam_offering_exists');
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_27_28_29_close_preserves_row_sources_and_event(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $created = $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->json('data');
        $id = $created['supplementary_exam_offering_id'];
        $this->actingAs($this->dean, 'sanctum')
            ->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
        $this->assertSame(1, SupplementaryExamOfferingSource::query()->count());
        $this->assertTrue(SupplementaryExamOfferingEvent::query()->where('event_type', 'closed')->exists());
    }

    public function test_supp_offer_30_31_reopen_event_and_stale_source(): void
    {
        $period = $this->announcedPeriod($this->first);
        $source = $this->genuineOffering($this->first, 'registered');
        $id = $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->json('data.supplementary_exam_offering_id');
        $this->actingAs($this->dean, 'sanctum')->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/close");
        $this->actingAs($this->dean, 'sanctum')
            ->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        $this->assertTrue(SupplementaryExamOfferingEvent::query()->where('event_type', 'reopened')->exists());

        $this->actingAs($this->dean, 'sanctum')->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/close");
        StudentCourseRegistration::query()->delete();
        $this->actingAs($this->dean, 'sanctum')
            ->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/reopen")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'supplementary_exam_source_stale');
        $this->assertNotNull($source->fresh());
    }

    public function test_supp_offer_32_no_delete_endpoint(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $id = $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->json('data.supplementary_exam_offering_id');
        $this->actingAs($this->dean, 'sanctum')
            ->deleteJson("/api/v1/dean/supplementary-exam-offerings/{$id}")
            ->assertStatus(405);
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_33_34_35_no_academic_mutations(): void
    {
        $period = $this->announcedPeriod($this->first);
        $offering = $this->genuineOffering($this->first, 'registered', offeringStatus: 'closed');
        $offerings = CourseOffering::query()->count();
        $registrations = StudentCourseRegistration::query()->count();
        SupplementaryExamResult::query()->create([
            'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
            'student_course_registration_id' => StudentCourseRegistration::query()->value('student_course_registration_id'),
            'theoretical_mark' => 40,
            'entered_by_user_id' => $this->dean->user_id,
            'entered_at' => now(),
        ]);
        $results = SupplementaryExamResult::query()->count();

        $this->actingAs($this->dean, 'sanctum')->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))->assertCreated();
        $this->assertSame($offerings, CourseOffering::query()->count());
        $this->assertSame('closed', $offering->fresh()->status);
        $this->assertSame($registrations, StudentCourseRegistration::query()->count());
        $this->assertSame($results, SupplementaryExamResult::query()->count());
        $this->assertSame(40.0, (float) SupplementaryExamResult::query()->value('theoretical_mark'));
    }

    public function test_supp_offer_36_37_summer_limit_is_policy_only(): void
    {
        $summer = $this->announcedPeriod($this->summer);
        $this->genuineOffering($this->first, 'registered');
        $catalog = $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/dean/supplementary-exam-offerings/catalog?'.$this->catalogQuery($summer))
            ->assertOk()
            ->json('data.period');
        $this->assertSame(3, $catalog['student_course_limit']);
        $this->assertSame([1, 2, 3], $catalog['source_semester_orders']);

        $first = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $catalogFirst = $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/dean/supplementary-exam-offerings/catalog?'.$this->catalogQuery($first))
            ->json('data.period');
        $this->assertNull($catalogFirst['student_course_limit']);
        $this->assertFalse(Schema::hasTable('supplementary_exam_registrations'));
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/app/Services/SupplementaryExamEligibilityService.php');
    }

    public function test_supp_offer_38_39_40_catalog_genuine_sources_and_summer_merge(): void
    {
        $period = $this->announcedPeriod($this->summer);
        $this->genuineOffering($this->first, 'registered');
        $this->genuineOffering($this->second, 'completed');
        $this->offering($this->summer);
        ProgramCourse::query()->create([
            'academic_program_id' => $this->programA->academic_program_id,
            'course_id' => $this->curriculumOnly->course_id,
            'is_active' => true,
        ]);

        $courses = $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/dean/supplementary-exam-offerings/catalog?'.$this->catalogQuery($period))
            ->assertOk()
            ->json('data.available_courses');
        $this->assertCount(1, $courses);
        $this->assertSame($this->course->course_id, $courses[0]['course_id']);
        $this->assertCount(2, $courses[0]['source_offerings']);
        $orders = collect($courses[0]['source_offerings'])->pluck('semester_order')->sort()->values()->all();
        $this->assertSame([1, 2], $orders);
    }

    public function test_supp_offer_41_practical_only_is_not_catalog_candidate(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered', course: $this->practicalOnly);

        $catalog = $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/dean/supplementary-exam-offerings/catalog?'.$this->catalogQuery($period))
            ->assertOk()
            ->json('data.available_courses');
        $ids = collect($catalog)->pluck('course_id')->all();
        $this->assertNotContains($this->practicalOnly->course_id, $ids);
        $this->assertSame(0, (int) $this->practicalOnly->theoretical_hours);
    }

    public function test_supp_offer_42_practical_only_cannot_be_opened(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered', course: $this->practicalOnly);

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', [
                'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
                'academic_program_id' => $this->programA->academic_program_id,
                'course_id' => $this->practicalOnly->course_id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');
        $this->assertSame(0, SupplementaryExamOffering::query()->count());
    }

    public function test_supp_offer_43_theoretical_hours_positive_remains_eligible(): void
    {
        $this->assertGreaterThan(0, (int) $this->course->theoretical_hours);
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertCreated();
    }

    public function test_supp_offer_44_orders_1_and_2_require_exact_period_semester_row(): void
    {
        $altFirst = Semester::query()->create([
            'semester_code' => 'first-b', 'semester_name' => 'First B', 'semester_order' => 1, 'is_active' => true,
        ]);
        $periodFirst = $this->announcedPeriod($this->first);
        $this->genuineOffering($altFirst, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($periodFirst))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');

        $this->genuineOffering($this->first, 'registered');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($periodFirst))
            ->assertCreated();

        $altSecond = Semester::query()->create([
            'semester_code' => 'second-b', 'semester_name' => 'Second B', 'semester_order' => 2, 'is_active' => true,
        ]);
        $periodSecond = $this->announcedPeriod($this->second);
        $this->genuineOffering($altSecond, 'completed');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($periodSecond))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'supplementary_exam_no_actual_source_offering');

        $this->genuineOffering($this->second, 'completed');
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($periodSecond))
            ->assertCreated();
    }

    public function test_closed_identity_instructs_reopen(): void
    {
        $period = $this->announcedPeriod($this->first);
        $this->genuineOffering($this->first, 'registered');
        $id = $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->json('data.supplementary_exam_offering_id');
        $this->actingAs($this->dean, 'sanctum')->postJson("/api/v1/dean/supplementary-exam-offerings/{$id}/close");
        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/dean/supplementary-exam-offerings', $this->openPayload($period))
            ->assertStatus(409)
            ->assertJsonPath('data.use_reopen', true)
            ->assertJsonPath('data.status', 'closed');
        $this->assertSame(1, SupplementaryExamOffering::query()->count());
    }

    private function openPayload(SupplementaryExamPeriod $period): array
    {
        return [
            'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
            'academic_program_id' => $this->programA->academic_program_id,
            'course_id' => $this->course->course_id,
        ];
    }

    private function catalogQuery(SupplementaryExamPeriod $period): string
    {
        return http_build_query([
            'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
            'academic_program_id' => $this->programA->academic_program_id,
        ]);
    }

    private function announcedPeriod(Semester $semester): SupplementaryExamPeriod
    {
        return $this->period($semester, 'announced');
    }

    private function period(Semester $semester, string $status): SupplementaryExamPeriod
    {
        $period = new SupplementaryExamPeriod;
        $period->academic_year_id = $this->year->academic_year_id;
        $period->semester_id = $semester->semester_id;
        $period->period_name = 'دورة '.$semester->semester_code;
        $period->start_date = '2026-08-01';
        $period->end_date = '2026-08-20';
        $period->status = $status;
        $period->is_active = $status === 'announced';
        $period->opened_by_user_id = $status === 'announced' ? $this->dean->user_id : null;
        $period->opened_at = $status === 'announced' ? now() : null;
        $period->save();

        return $period;
    }

    private function genuineOffering(
        Semester $semester,
        string $statusCode,
        ?AcademicYear $year = null,
        string $offeringStatus = 'open',
        ?Course $course = null,
    ): CourseOffering {
        $offering = $this->offering($semester, $year, $offeringStatus, $course);
        StudentCourseRegistration::query()->create([
            'student_id' => $this->student->student_id,
            'course_offering_id' => $offering->course_offering_id,
            'registration_date' => '2026-02-01',
            'registered_by_user_id' => $this->dean->user_id,
            'registration_status_id' => $this->statuses[$statusCode]->registration_status_id,
        ]);

        return $offering;
    }

    private function offering(Semester $semester, ?AcademicYear $year = null, string $status = 'open', ?Course $course = null): CourseOffering
    {
        return CourseOffering::query()->create([
            'course_id' => ($course ?? $this->course)->course_id,
            'academic_year_id' => ($year ?? $this->year)->academic_year_id,
            'semester_id' => $semester->semester_id,
            'department_id' => $this->programA->department_id,
            'academic_program_id' => $this->programA->academic_program_id,
            'capacity' => 40,
            'available_seats' => 40,
            'status' => $status,
        ]);
    }

    private function makeUser(string $username, string $roleCode, array $permissions, College $college): User
    {
        $role = Role::query()->firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'is_system_role' => true, 'is_active' => true]
        );
        $user = User::query()->create([
            'username' => $username,
            'email' => $username.'@test.invalid',
            'password_hash' => 'unused',
            'account_status_id' => $this->activeAccount->account_status_id,
        ]);
        UserRole::query()->create([
            'user_id' => $user->user_id,
            'role_id' => $role->role_id,
            'is_active' => true,
        ]);
        foreach ($permissions as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_code' => $code],
                ['module_id' => 1, 'permission_name' => $code, 'is_active' => true]
            );
            RolePermission::query()->firstOrCreate([
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
            ], ['granted_at' => now()]);
        }
        $scope = new UserAccessScope;
        $scope->user_id = $user->user_id;
        $scope->scope_type = 'college';
        $scope->scope_id = $college->college_id;
        $scope->is_active = true;
        $scope->save();

        return $user->fresh();
    }

    private function seedWorld(): void
    {
        $this->activeAccount = AccountStatus::query()->create(['status_code' => 'active', 'status_name' => 'Active', 'is_active' => true]);
        $this->collegeA = College::query()->create(['college_code' => 'A', 'college_name' => 'College A', 'is_active' => true]);
        $this->collegeB = College::query()->create(['college_code' => 'B', 'college_name' => 'College B', 'is_active' => true]);
        $deptA = Department::query()->create(['college_id' => $this->collegeA->college_id, 'department_code' => 'DA', 'department_name' => 'Dept A', 'is_active' => true]);
        $deptB = Department::query()->create(['college_id' => $this->collegeB->college_id, 'department_code' => 'DB', 'department_name' => 'Dept B', 'is_active' => true]);
        $this->programA = AcademicProgram::query()->create(['department_id' => $deptA->department_id, 'program_code' => 'PA', 'program_name' => 'Program A', 'is_active' => true]);
        $this->programB = AcademicProgram::query()->create(['department_id' => $deptB->department_id, 'program_code' => 'PB', 'program_name' => 'Program B', 'is_active' => true]);
        $this->year = AcademicYear::query()->create(['year_name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => true, 'is_active' => true]);
        $this->otherYear = AcademicYear::query()->create(['year_name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'is_current' => false, 'is_active' => true]);
        $this->summer = Semester::query()->create(['semester_code' => 'summer', 'semester_name' => 'Summer', 'semester_order' => 3, 'is_active' => true]);
        $this->first = Semester::query()->create(['semester_code' => 'first', 'semester_name' => 'First', 'semester_order' => 1, 'is_active' => true]);
        $this->second = Semester::query()->create(['semester_code' => 'second', 'semester_name' => 'Second', 'semester_order' => 2, 'is_active' => true]);
        $this->course = Course::query()->create([
            'course_code' => 'ACC1', 'course_name' => 'محاسبة 1', 'credit_hours' => 3,
            'theoretical_hours' => 3, 'practical_hours' => 0, 'is_active' => true,
        ]);
        $this->curriculumOnly = Course::query()->create([
            'course_code' => 'CUR1', 'course_name' => 'خطة فقط', 'credit_hours' => 3,
            'theoretical_hours' => 2, 'practical_hours' => 0, 'is_active' => true,
        ]);
        $this->practicalOnly = Course::query()->create([
            'course_code' => 'LAB1', 'course_name' => 'مختبر عملي', 'credit_hours' => 3,
            'theoretical_hours' => 0, 'practical_hours' => 3, 'is_active' => true,
        ]);
        foreach (['registered', 'completed', 'dropped', 'withdrawn'] as $code) {
            $this->statuses[$code] = RegistrationStatus::query()->create(['status_code' => $code, 'status_name' => $code, 'is_active' => true]);
        }
        $this->student = Student::query()->create([
            'student_number' => 'S-1', 'first_name' => 'A', 'last_name' => 'B',
            'enrollment_date' => '2026-09-01', 'academic_program_id' => $this->programA->academic_program_id,
        ]);
        $this->dean = $this->makeUser('dean-a', 'dean', [
            'supplementary_exams.offerings.view',
            'supplementary_exams.offerings.manage',
        ], $this->collegeA);
        $this->otherDean = $this->makeUser('dean-b', 'dean', [
            'supplementary_exams.offerings.view',
            'supplementary_exams.offerings.manage',
        ], $this->collegeB);
    }

    private function createSchema(): void
    {
        Schema::create('account_statuses', function (Blueprint $t): void {
            $t->integer('account_status_id')->autoIncrement();
            $t->string('status_code');
            $t->string('status_name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->integer('role_id')->autoIncrement();
            $t->string('role_code');
            $t->string('role_name');
            $t->boolean('is_system_role')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t): void {
            $t->integer('permission_id')->autoIncrement();
            $t->integer('module_id')->nullable();
            $t->string('permission_code');
            $t->string('permission_name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->integer('role_permission_id')->autoIncrement();
            $t->integer('role_id');
            $t->integer('permission_id');
            $t->timestamp('granted_at')->nullable();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->integer('user_id')->autoIncrement();
            $t->string('username');
            $t->string('email');
            $t->string('password_hash');
            $t->integer('account_status_id');
            $t->integer('student_id')->nullable();
            $t->integer('employee_id')->nullable();
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t): void {
            $t->integer('user_role_id')->autoIncrement();
            $t->integer('user_id');
            $t->integer('role_id');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('user_access_scopes', function (Blueprint $t): void {
            $t->integer('user_access_scope_id')->autoIncrement();
            $t->integer('user_id');
            $t->string('scope_type');
            $t->integer('scope_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('colleges', function (Blueprint $t): void {
            $t->integer('college_id')->autoIncrement();
            $t->string('college_code');
            $t->string('college_name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('departments', function (Blueprint $t): void {
            $t->integer('department_id')->autoIncrement();
            $t->integer('college_id');
            $t->string('department_code');
            $t->string('department_name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $t): void {
            $t->integer('academic_program_id')->autoIncrement();
            $t->integer('department_id');
            $t->string('program_code');
            $t->string('program_name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $t): void {
            $t->integer('academic_year_id')->autoIncrement();
            $t->string('year_name');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->boolean('is_current')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('semesters', function (Blueprint $t): void {
            $t->integer('semester_id')->autoIncrement();
            $t->string('semester_code');
            $t->string('semester_name');
            $t->integer('semester_order');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('courses', function (Blueprint $t): void {
            $t->integer('course_id')->autoIncrement();
            $t->string('course_code');
            $t->string('course_name');
            $t->integer('credit_hours')->default(3);
            $t->integer('theoretical_hours')->default(0);
            $t->integer('practical_hours')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('program_courses', function (Blueprint $t): void {
            $t->integer('program_course_id')->autoIncrement();
            $t->integer('academic_program_id');
            $t->integer('course_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->integer('course_offering_id')->autoIncrement();
            $t->integer('course_id');
            $t->integer('academic_year_id');
            $t->integer('semester_id');
            $t->integer('department_id')->nullable();
            $t->integer('academic_program_id')->nullable();
            $t->integer('capacity')->default(0);
            $t->integer('available_seats')->default(0);
            $t->string('status')->default('open');
            $t->timestamps();
        });
        Schema::create('registration_statuses', function (Blueprint $t): void {
            $t->integer('registration_status_id')->autoIncrement();
            $t->string('status_code');
            $t->string('status_name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t): void {
            $t->integer('student_id')->autoIncrement();
            $t->string('student_number');
            $t->string('first_name');
            $t->string('last_name');
            $t->date('enrollment_date');
            $t->integer('academic_program_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('student_course_registrations', function (Blueprint $t): void {
            $t->integer('student_course_registration_id')->autoIncrement();
            $t->integer('student_id');
            $t->integer('course_offering_id');
            $t->date('registration_date');
            $t->integer('registered_by_user_id');
            $t->integer('registration_status_id');
            $t->timestamps();
        });
        Schema::create('supplementary_exam_periods', function (Blueprint $t): void {
            $t->integer('supplementary_exam_period_id')->autoIncrement();
            $t->integer('academic_year_id');
            $t->integer('semester_id');
            $t->string('period_name');
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('is_active')->default(false);
            $t->string('status', 32);
            $t->integer('opened_by_user_id')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['academic_year_id', 'semester_id']);
        });
        Schema::create('supplementary_exam_period_events', function (Blueprint $t): void {
            $t->integer('supplementary_exam_period_event_id')->autoIncrement();
            $t->integer('supplementary_exam_period_id');
            $t->string('event_type', 64);
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->integer('actor_user_id');
            $t->text('notes')->nullable();
            $t->timestamp('created_at');
            $t->index('supplementary_exam_period_id');
            $t->index('actor_user_id');
            $t->index(['event_type', 'to_status']);
            $t->foreign('supplementary_exam_period_id')->references('supplementary_exam_period_id')->on('supplementary_exam_periods');
            $t->foreign('actor_user_id')->references('user_id')->on('users');
        });
        Schema::create('supplementary_exam_results', function (Blueprint $t): void {
            $t->integer('supplementary_exam_result_id')->autoIncrement();
            $t->integer('supplementary_exam_period_id');
            $t->integer('student_course_registration_id');
            $t->decimal('theoretical_mark', 5, 2);
            $t->integer('entered_by_user_id');
            $t->dateTime('entered_at');
            $t->timestamps();
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $t): void {
            $t->integer('supplementary_exam_offering_id')->autoIncrement();
            $t->integer('supplementary_exam_period_id');
            $t->integer('academic_program_id');
            $t->integer('course_id');
            $t->string('status', 16);
            $t->integer('opened_by_user_id');
            $t->dateTime('opened_at');
            $t->integer('closed_by_user_id')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->unique(['supplementary_exam_period_id', 'academic_program_id', 'course_id'], 'uq_seo_period_program_course');
            $t->index('supplementary_exam_period_id');
            $t->foreign('supplementary_exam_period_id')->references('supplementary_exam_period_id')->on('supplementary_exam_periods');
            $t->foreign('academic_program_id')->references('academic_program_id')->on('academic_programs');
            $t->foreign('course_id')->references('course_id')->on('courses');
            $t->foreign('opened_by_user_id')->references('user_id')->on('users');
            $t->foreign('closed_by_user_id')->references('user_id')->on('users');
        });
        Schema::create('supplementary_exam_offering_sources', function (Blueprint $t): void {
            $t->integer('supplementary_exam_offering_source_id')->autoIncrement();
            $t->integer('supplementary_exam_offering_id');
            $t->integer('course_offering_id');
            $t->timestamp('created_at');
            $t->unique(['supplementary_exam_offering_id', 'course_offering_id'], 'uq_seos_offering_course_offering');
            $t->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings');
            $t->foreign('course_offering_id')->references('course_offering_id')->on('course_offerings');
        });
        Schema::create('supplementary_exam_offering_events', function (Blueprint $t): void {
            $t->integer('supplementary_exam_offering_event_id')->autoIncrement();
            $t->integer('supplementary_exam_offering_id');
            $t->string('event_type', 64);
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->integer('actor_user_id');
            $t->text('notes')->nullable();
            $t->timestamp('created_at');
            $t->index('supplementary_exam_offering_id');
            $t->index('actor_user_id');
            $t->index(['event_type', 'to_status']);
            $t->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings');
            $t->foreign('actor_user_id')->references('user_id')->on('users');
        });
    }
}
