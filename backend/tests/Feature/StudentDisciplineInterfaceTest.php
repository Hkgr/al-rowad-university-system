<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StudentDisciplineFixture as Fixture;
use Tests\TestCase;

class StudentDisciplineInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Fixture::install();
    }

    public function test_http_read_is_scoped_paginated_searchable_private_and_does_not_write(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $before = DB::table('student_disciplinary_cases')->get()->toJson();
        $this->getJson('/api/v1/disciplinary-cases?per_page=1')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/disciplinary-cases?q=طالب اختبار')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/disciplinary-cases?q=SYN-11')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/disciplinary-cases?q=')->assertOk()->assertJsonPath('data.meta.total', 2);
        $response = $this->getJson('/api/v1/disciplinary-cases/3')->assertOk();
        self::assertSame('SYN-13', $response->json('data.student.student_number'), 'archived student history retained');
        self::assertArrayNotHasKey('mother_name', $response->json('data.student'));
        self::assertArrayNotHasKey('phone_number', $response->json('data.student'));
        $this->getJson('/api/v1/disciplinary-cases/2')->assertNotFound();
        $this->getJson('/api/v1/students/12/disciplinary-cases')->assertNotFound();
        $this->getJson('/api/v1/disciplinary-cases/options?student_id=11')->assertOk()->assertJsonPath('data.offerings.0.course_code', 'SYN101');
        self::assertSame($before, DB::table('student_disciplinary_cases')->get()->toJson());
        self::assertSame(0, DB::table('disciplinary_case_appeals')->count());
    }

    public function test_recording_case_uses_existing_service_without_changing_official_results(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $results = DB::table('student_course_results')->get()->toJson();
        $created = $this->postJson('/api/v1/disciplinary-cases', $this->proposal())->assertCreated()->assertJsonPath('data.student_id', 11);
        $this->assertDatabaseHas('student_disciplinary_cases', ['case_id' => $created->json('data.case_id'), 'reported_by_user_id' => auth()->id(), 'decision_number' => 'TEST-NEW']);
        self::assertSame($results, DB::table('student_course_results')->get()->toJson());
    }

    public function test_appeal_and_recorded_decision_are_scoped_and_cannot_overwrite_a_decided_appeal(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $results = DB::table('student_course_results')->get()->toJson();
        $appeal = $this->postJson('/api/v1/disciplinary-case-appeals', ['case_id' => 1, 'appeal_reason' => 'سبب طعن اصطناعي'])->assertCreated()->json('data.appeal_id');
        $detail = $this->getJson('/api/v1/disciplinary-case-appeals/'.$appeal)->assertOk();
        self::assertArrayNotHasKey('student', $detail->json('data.disciplinary_case'));
        $this->getJson('/api/v1/disciplinary-cases/1')->assertOk()->assertJsonPath('data.appeals.0.can_record_decision', true);
        $this->postJson('/api/v1/disciplinary-case-appeals/'.$appeal.'/decide', ['status_code' => 'rejected', 'notes' => 'نتيجة صادرة'])->assertOk();
        $before = DB::table('disciplinary_case_appeals')->get()->toJson();
        $this->postJson('/api/v1/disciplinary-case-appeals/'.$appeal.'/decide', ['status_code' => 'accepted'])->assertConflict();
        self::assertSame($before, DB::table('disciplinary_case_appeals')->get()->toJson());
        self::assertSame($results, DB::table('student_course_results')->get()->toJson());
        $this->postJson('/api/v1/disciplinary-case-appeals', ['case_id' => 2, 'appeal_reason' => 'غير مخول'])->assertNotFound();
    }

    public function test_accepting_non_cascading_case_preserves_unrelated_official_marks_and_history(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $before = DB::table('student_course_results')->get()->toJson();
        $id = $this->postJson('/api/v1/disciplinary-case-appeals', ['case_id' => 1, 'appeal_reason' => 'طعن'])->assertCreated()->json('data.appeal_id');
        $this->postJson('/api/v1/disciplinary-case-appeals/'.$id.'/decide', ['status_code' => 'accepted'])->assertOk();
        $this->assertDatabaseHas('student_disciplinary_cases', ['case_id' => 1, 'case_status' => 'overturned']);
        self::assertSame($before, DB::table('student_course_results')->get()->toJson());
        $this->postJson('/api/v1/disciplinary-case-appeals', ['case_id' => 1, 'appeal_reason' => 'طعن آخر'])->assertConflict();
        self::assertSame(1, DB::table('disciplinary_case_appeals')->count());
    }

    public function test_missing_marks_prerequisites_roll_back_a_cascading_case(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $before = DB::table('student_course_results')->get()->toJson();
        $this->postJson('/api/v1/disciplinary-cases', $this->proposal() + ['trigger_course_offering_id' => 1])->assertCreated();
        $proposal = $this->proposal();
        $proposal['penalty_type_id'] = 2;
        $proposal['trigger_course_offering_id'] = 1;
        $count = DB::table('student_disciplinary_cases')->count();
        $this->postJson('/api/v1/disciplinary-cases', $proposal)->assertUnprocessable();
        self::assertSame($count, DB::table('student_disciplinary_cases')->count());
        self::assertSame($before, DB::table('student_course_results')->get()->toJson());
        self::assertSame(0, DB::table('disciplinary_case_affected_courses')->count());
    }

    public function test_read_only_staff_cannot_mutate_and_personal_or_unscoped_accounts_are_denied(): void
    {
        $this->actingAs(Fixture::actor(permissions: ['students.view']), 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases/options')->assertOk()->assertJsonPath('data.capabilities.create', false);
        $this->postJson('/api/v1/disciplinary-cases', $this->proposal())->assertForbidden();
        foreach ([Fixture::actor(scope: null), Fixture::actor(role: 'student'), Fixture::actor(role: 'doctor_instructor')] as $actor) {
            $this->actingAs($actor, 'sanctum');
            $this->getJson('/api/v1/disciplinary-cases')->assertForbidden();
            $this->postJson('/api/v1/disciplinary-case-appeals', ['case_id' => 1, 'appeal_reason' => 'سبب'])->assertForbidden();
        }
    }

    public function test_administrative_vp_uses_existing_grants_and_university_scope_without_staff_role(): void
    {
        $this->actingAs(Fixture::actor(role: 'vice_president_administrative', scope: 'university'), 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases')->assertOk()->assertJsonPath('data.meta.total', 3);
        $this->postJson('/api/v1/disciplinary-cases', $this->proposal())->assertCreated();
        $this->actingAs(Fixture::actor(role: 'vice_president_administrative'), 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases')->assertForbidden();
        $this->actingAs(Fixture::actor(role: 'super_admin', permissions: [], scope: null), 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases')->assertOk();
    }

    public function test_schema_absence_and_invalid_queries_are_controlled_not_an_empty_history(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases?q[]=bad')->assertUnprocessable();
        $this->getJson('/api/v1/disciplinary-cases?case_status=unknown')->assertUnprocessable();
        Schema::drop('disciplinary_case_appeals');
        $this->getJson('/api/v1/disciplinary-cases')->assertStatus(503);
    }

    public function test_eager_read_query_count_does_not_grow_with_case_count(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $sample = (array) DB::table('student_disciplinary_cases')->where('case_id', 1)->first();
        unset($sample['case_id']);
        for ($i = 0; $i < 12; $i++) {
            DB::table('student_disciplinary_cases')->insert($sample);
        }
        $this->getJson('/api/v1/disciplinary-cases?per_page=1')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/disciplinary-cases?per_page=1')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/v1/disciplinary-cases?per_page=15')->assertOk()->assertJsonCount(14, 'data.data');
        self::assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_scope_and_role_revocation_and_inactive_accounts_are_enforced_on_http(): void
    {
        $actor = Fixture::actor();
        $this->actingAs($actor, 'sanctum');
        $this->getJson('/api/v1/disciplinary-cases')->assertOk();
        DB::table('user_access_scopes')->where('user_id', $actor->user_id)->update(['is_active' => 0]);
        $this->postJson('/api/v1/disciplinary-cases', $this->proposal())->assertForbidden();
        self::assertSame(3, DB::table('student_disciplinary_cases')->count());
        $actor = Fixture::actor(role: 'vice_president_administrative', scope: 'university');
        DB::table('user_roles')->where('user_id', $actor->user_id)->update(['assigned_at' => now()->addDay()]);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/disciplinary-cases')->assertForbidden();
        $actor = Fixture::actor();
        $actor->account_status_id = 2;
        $actor->save();
        $this->actingAs($actor->fresh(), 'sanctum')->getJson('/api/v1/disciplinary-cases')->assertForbidden();
    }

    public function test_foreign_student_and_unrelated_trigger_cannot_record_a_case(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        $proposal = $this->proposal();
        $proposal['student_id'] = 12;
        $this->postJson('/api/v1/disciplinary-cases', $proposal)->assertNotFound();
        $proposal['student_id'] = 13;
        $proposal['trigger_course_offering_id'] = 1;
        $this->postJson('/api/v1/disciplinary-cases', $proposal)->assertNotFound();
        self::assertSame(3, DB::table('student_disciplinary_cases')->count());
    }

    public function test_offering_lookup_can_find_an_older_registration_without_loading_all_history(): void
    {
        $this->actingAs(Fixture::actor(), 'sanctum');
        DB::table('courses')->insert(['course_id' => 2, 'course_code' => 'OLD-SYN', 'course_name' => 'مقرر قديم اصطناعي']);
        DB::table('course_offerings')->insert(['course_offering_id' => 2, 'course_id' => 2, 'academic_program_id' => 1, 'department_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 2, 'student_id' => 11, 'course_offering_id' => 2]);
        for ($id = 3; $id < 103; $id++) {
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $id, 'student_id' => 11, 'course_offering_id' => 1]);
        }
        $this->getJson('/api/v1/disciplinary-cases/options?student_id=11&offering_q=OLD-SYN')->assertOk()
            ->assertJsonCount(1, 'data.offerings')->assertJsonPath('data.offerings.0.course_offering_id', 2);
    }

    private function proposal(): array
    {
        return ['student_id' => 11, 'violation_type_id' => 1, 'penalty_type_id' => 1, 'violation_description' => 'واقعة اختبار فقط',
            'violation_date' => '2026-10-01', 'decision_date' => '2026-10-02', 'decided_by_authority' => 'dean_or_institute_director', 'decision_number' => 'TEST-NEW'];
    }
}
