<?php

namespace Tests\Support;

use App\Models\{CourseIncompleteCase, Student, StudentCourseRegistration, User};
use App\Services\{GradeService, IncompleteResultService};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Additional real HTTP/kernel + database evidence, ONLY inside the guarded disposable harness. */
final class IncompleteMariaDbAcceptance
{
    private const BASE = '/api/v1/exams/incomplete-results';

    public static function run(): void
    {
        \check(app()->environment('testing') && DB::getDriverName() === 'mysql', 'Isolated MariaDB test context required.');
        IncompleteCatalogFixture::assertIsolated();
        // context() belongs to the harness, which verifies the new datadir, marker and database first.
        foreach ([2000, 2001, 2002, 2020, 2021, 2022, 2023, 2030, 2031] as $id) \context($id, false);
        self::httpLifecycle();
        self::calendar();
        self::calculations();
        echo "HTTP_CALCULATION_CALENDAR | PASS | real kernel/middleware; synthetic records; no browser acceptance\n";
    }

    private static function http(string $method, string $path, array $input = [], int $status = 200): array
    {
        // Real Sanctum test guard; no middleware/controller/service is disabled or mocked.
        app('auth')->forgetGuards();
        Sanctum::actingAs(User::findOrFail(1));
        $request = Request::create(self::BASE.$path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], $method === 'GET' ? null : json_encode($input, JSON_THROW_ON_ERROR));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        \check($response->getStatusCode() === $status, "$method $path expected $status: ".$response->getContent());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        \check(is_array($body) && ($body['success'] ?? null) === ($status < 400), 'Unexpected real HTTP envelope.');
        return $body;
    }

    private static function proposal(array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'expected_revision' => 0,
            'reason' => 'Synthetic internal reason', 'requirements' => 'Synthetic outstanding theory',
            'expiry_outcome' => 'F', 'deadline_at' => '2026-10-20'], $changes);
    }

    private static function httpLifecycle(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        DB::enableQueryLog();
        $read = self::http('GET', '/registrations/2000')['data'];
        \check($read['schema_ready'] && $read['state'] === 'no_result', 'Initial real registration read failed.');
        foreach (DB::getQueryLog() as $query) \check(!preg_match('/^\s*(insert|update|delete|replace)/i', $query['query']), 'GET wrote to the database.');
        DB::disableQueryLog(); DB::flushQueryLog();
        self::http('POST', '/registrations/2000', self::proposal(['expiry_outcome' => '']), 422);
        $proposal = self::proposal();
        $original = self::http('POST', '/registrations/2000', $proposal, 201)['data'];
        $id = $original['case']['incomplete_case_id'];
        self::http('PATCH', "/$id/deadline", ['request_id' => (string) Str::uuid(), 'expected_revision' => 1, 'deadline_at' => '2026-10-22']);
        \check(self::http('POST', '/registrations/2000', $proposal, 201)['data'] === $original, 'Replay did not return the original acknowledgement.');
        self::http('POST', '/registrations/2000', array_replace($proposal, ['reason' => 'Different proposal']), 409);
        \check(CourseIncompleteCase::findOrFail($id)->revision === 2, 'Replay changed the current revision.');
        $list = self::http('GET', '?student_id=2000&state=active&per_page=1')['data'];
        \check($list['total'] === 1 && count($list['data']) === 1 && $list['data'][0]['course']['id'] === 2000, 'Filtered list identity/pagination mismatch.');
        $part = DB::table('grade_components')->where('course_offering_id', 2000)->where('component_type', 'theoretical')->value('grade_component_id');
        StudentCourseRegistration::findOrFail(2000)->update(['registration_status_id' => 4]);
        $resolve = ['request_id' => (string) Str::uuid(), 'expected_revision' => 2, 'components' => [['grade_component_id' => $part, 'mark' => 50]]];
        self::http('POST', "/$id/resolve", $resolve);
        \check(!self::http('GET', "/$id")['data']['is_official'], 'Pending offering was made official.');
        DB::table('grade_approvals')->insert(['course_offering_id' => 2000, 'approval_status_id' => 1]);
        $official = self::http('GET', "/$id")['data'];
        \check($official['is_official'] && $official['state'] === 'incomplete_resolved', 'Official resolved presentation mismatch.');
        \check(self::http('POST', '/registrations/2000', $proposal, 201)['data'] === $original, 'Terminal current state invalidated a legitimate original replay.');
        self::http('PATCH', "/$id/deadline", ['request_id' => (string) Str::uuid(), 'expected_revision' => 3, 'deadline_at' => '2026-10-24'], 409);
        \check(app(GradeService::class)->calculateCgpa(Student::findOrFail(2000))['cgpa'] === 3.0, 'Completed-registration settlement GPA mismatch.');

        // Ordinary role, inactive account, missing assigned permission and independent offering scope.
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'doctor_instructor']);
        self::http('GET', '/registrations/2001', [], 403);
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'exam_officer']);
        DB::table('account_statuses')->where('account_status_id', 1)->update(['status_code' => 'inactive']);
        self::http('GET', '/registrations/2001', [], 403);
        DB::table('account_statuses')->where('account_status_id', 1)->update(['status_code' => 'active']);
        DB::table('permissions')->where('permission_code', 'grades.manage')->update(['is_active' => 0]);
        self::http('GET', '/registrations/2001', [], 403);
        DB::table('permissions')->where('permission_code', 'grades.manage')->update(['is_active' => 1]);
        DB::table('user_access_scopes')->where('user_id', 1)->update(['scope_type' => 'college', 'scope_id' => 1]);
        DB::table('colleges')->insert(['college_id' => 2, 'college_name' => 'Synthetic outside college']);
        DB::table('departments')->insert(['department_id' => 2, 'college_id' => 2]);
        DB::table('academic_programs')->insert(['academic_program_id' => 2, 'department_id' => 2]);
        DB::table('course_offerings')->where('course_offering_id', 2001)->update(['academic_program_id' => 2, 'department_id' => 2]);
        self::http('GET', '/registrations/2001', [], 403);
        DB::table('user_access_scopes')->where('user_id', 1)->update(['scope_type' => 'university', 'scope_id' => 1]);
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'vice_president_administrative']);
        self::http('GET', '/registrations/2001');
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'super_admin']);
        DB::table('user_access_scopes')->where('user_id', 1)->update(['is_active' => 0]);
        self::http('GET', '/registrations/2001');
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'exam_officer']);
        self::http('GET', '/registrations/2001', [], 403);
        DB::table('user_access_scopes')->where('user_id', 1)->update(['is_active' => 1]);
        echo "HTTP_LIFECYCLE | PASS | GET no writes; strict input; original/terminal replay; completed settlement; pending/official; officer/VP/super-admin/scope denial\n";
    }

    private static function calendar(): void
    {
        DB::table('semesters')->insert([['semester_id' => 2, 'semester_name' => 'Second', 'semester_order' => 2], ['semester_id' => 3, 'semester_name' => 'Summer', 'semester_order' => 3]]);
        DB::table('academic_years')->insert(['academic_year_id' => 7, 'year_name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        Schema::create('academic_calendar_event_types', fn (Blueprint $t) => [$t->integer('academic_calendar_event_type_id')->primary(), $t->string('event_type_code'), $t->boolean('is_active')]);
        Schema::create('academic_calendar_events', fn (Blueprint $t) => [$t->integer('academic_calendar_event_id')->primary(), $t->integer('academic_calendar_event_type_id'), $t->integer('academic_year_id'), $t->integer('semester_id'), $t->dateTime('cancelled_at')->nullable()]);
        Schema::create('academic_calendar_event_versions', fn (Blueprint $t) => [$t->integer('academic_calendar_event_version_id')->primary(), $t->integer('academic_calendar_event_id'), $t->integer('version_number'), $t->string('publication_status'), $t->dateTime('starts_at'), $t->dateTime('ends_at'), $t->boolean('is_enforcement'), $t->dateTime('published_at')->nullable(), $t->dateTime('superseded_at')->nullable()]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id' => 1, 'event_type_code' => 'study_period', 'is_active' => 1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id' => 1, 'academic_calendar_event_type_id' => 1, 'academic_year_id' => 1, 'semester_id' => 2]);
        DB::table('academic_calendar_event_versions')->insert(['academic_calendar_event_version_id' => 1, 'academic_calendar_event_id' => 1, 'version_number' => 1, 'publication_status' => 'published', 'starts_at' => '2026-11-01 06:00:00', 'ends_at' => '2027-01-01 00:00:00', 'published_at' => '2026-10-01 00:00:00', 'is_enforcement' => 0]);
        $read = self::http('GET', '/registrations/2020')['data'];
        \check($read['deadline_suggestion']['deadline_at'] === '2026-11-21T20:59:59+00:00', 'Day-one/21-day Damascus boundary mismatch.');
        $input = self::proposal(); unset($input['deadline_at']);
        $case = self::http('POST', '/registrations/2020', $input, 201)['data']['case'];
        \check($case['deadline_source'] === 'calendar', 'Non-enforcement published calendar was rejected.');
        DB::table('academic_calendar_events')->update(['cancelled_at' => '2026-10-16 00:00:00']);
        \check(!self::http('GET', '/registrations/2021')['data']['deadline_suggestion']['available'], 'Cancelled calendar remained eligible.');
        DB::table('academic_calendar_events')->update(['cancelled_at' => null]);
        DB::table('academic_calendar_event_versions')->where('academic_calendar_event_version_id', 1)->update(['superseded_at' => '2026-10-16 00:00:00', 'publication_status' => 'superseded']);
        \check(!self::http('GET', '/registrations/2021')['data']['deadline_suggestion']['available'], 'Superseded calendar remained eligible.');
        DB::table('academic_calendar_event_versions')->insert(['academic_calendar_event_version_id' => 2, 'academic_calendar_event_id' => 1, 'version_number' => 2, 'publication_status' => 'published', 'starts_at' => '2026-11-05 06:00:00', 'ends_at' => '2027-01-01 00:00:00', 'published_at' => '2026-10-16 00:00:00', 'is_enforcement' => 0]);
        \check(self::http('GET', '/registrations/2021')['data']['deadline_suggestion']['deadline_at'] === '2026-11-25T20:59:59+00:00', 'Replacement calendar was not used for a new case.');
        \check(CourseIncompleteCase::findOrFail($case['incomplete_case_id'])->deadline_at->toISOString() === $case['deadline_at'], 'Existing accepted calendar was rewritten.');
        DB::table('course_offerings')->where('course_offering_id', 2022)->update(['semester_id' => 2]);
        DB::table('academic_calendar_events')->update(['semester_id' => 3]);
        \check(self::http('GET', '/registrations/2022')['data']['deadline_suggestion']['calendar_snapshot']['semester_id'] === 3, 'Summer was not next term.');
        DB::table('course_offerings')->where('course_offering_id', 2023)->update(['semester_id' => 3]);
        DB::table('academic_calendar_events')->update(['academic_year_id' => 7, 'semester_id' => 1]);
        DB::table('academic_calendar_event_versions')->where('academic_calendar_event_version_id', 2)->update(['starts_at' => '2027-01-15 06:00:00', 'ends_at' => '2027-05-01 00:00:00']);
        \check(self::http('GET', '/registrations/2023')['data']['deadline_suggestion']['deadline_at'] === '2027-02-04T20:59:59+00:00', 'Next-year rollover mismatch.');
        $service = app(IncompleteResultService::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($case['deadline_at'])->addMicroseconds(500000));
        \check(!$service->expire($case['incomplete_case_id']), 'Inclusive final second expired early.');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($case['deadline_at'])->addSecond());
        \check($service->expire($case['incomplete_case_id']), 'Case did not expire after its frozen deadline.');
        CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
        echo "CALENDAR | PASS | Damascus inclusive boundary; non-enforcement; summer/year rollover; cancellation/replacement; frozen acceptance\n";
    }

    private static function calculations(): void
    {
        // Two distinct equal-credit approved courses, one student: canonical B(80)=3 points + F/Z=0 => 1.50.
        DB::table('student_course_registrations')->where('student_course_registration_id', 2031)->update(['student_id' => 2030]);
        foreach ([2030, 2031] as $id) DB::table('grade_approvals')->insert(['course_offering_id' => $id, 'approval_status_id' => 1]);
        DB::table('student_course_results')->insert(['student_course_registration_id' => 2030, 'result_status_id' => 1, 'theoretical_total' => 50, 'practical_total' => 30, 'final_mark' => 80]);
        $grades = app(GradeService::class); $student = Student::findOrFail(2030);
        foreach ([2, 3] as $status) {
            DB::table('student_course_results')->updateOrInsert(['student_course_registration_id' => 2031], ['result_status_id' => $status, 'theoretical_total' => null, 'practical_total' => null, 'final_mark' => null, 'is_deprived' => $status === 3]);
            $gpa = $grades->calculateCgpa($student);
            \check($gpa['cgpa'] === 1.5 && $gpa['total_included_credit_hours'] === 6, 'B plus absent-mark F/Z GPA mismatch.');
        }
        DB::table('student_course_results')->where('student_course_registration_id', 2031)->update(['theoretical_total' => 60, 'practical_total' => 40, 'final_mark' => 100]);
        \check($grades->calculateCgpa($student)['cgpa'] === 1.5, 'Retained high Z marks became a pass.');
        DB::table('student_course_results')->where('student_course_registration_id', 2031)->update(['result_status_id' => 4, 'final_mark' => 0, 'is_deprived' => false]);
        \check($grades->calculateCgpa($student)['cgpa'] === 3.0, 'Unresolved I entered GPA.');
        DB::table('student_course_results')->where('student_course_registration_id', 2031)->delete();
        DB::table('student_course_registrations')->where('student_course_registration_id', 2031)->update(['registration_status_id' => 5]);
        \check($grades->calculateCgpa($student)['cgpa'] === 3.0, 'W without result entered GPA.');
        DB::table('student_course_registrations')->where('student_course_registration_id', 2030)->update(['registration_status_id' => 5]);
        $term = $grades->getTranscript($student)['terms'][0];
        \check($term['withdrawal_status'] === 'full_withdrawal' && $term['counts_towards_maximum_study_duration'], 'Wholly withdrawn nonempty term was hidden.');
        DB::table('student_course_registrations')->where('student_course_registration_id', 2031)->update(['registration_status_id' => 1]);
        \check($grades->getTranscript($student)['terms'][0]['withdrawal_status'] === 'not_full_withdrawal', 'Pending registration falsely produced full withdrawal.');
        // Later I/W for the same course cannot erase an earlier eligible failure.
        DB::table('student_course_registrations')->where('student_course_registration_id', 2030)->update(['registration_status_id' => 1]);
        DB::table('student_course_results')->where('student_course_registration_id', 2030)->update(['result_status_id' => 2, 'final_mark' => null, 'theoretical_total' => null, 'practical_total' => null]);
        DB::table('course_offerings')->where('course_offering_id', 2031)->update(['course_id' => 2030]);
        foreach ([1, 5] as $registrationStatus) {
            DB::table('student_course_registrations')->where('student_course_registration_id', 2031)->update(['registration_status_id' => $registrationStatus]);
            DB::table('student_course_results')->updateOrInsert(['student_course_registration_id' => 2031], ['result_status_id' => 4, 'final_mark' => null]);
            $gpa = $grades->calculateCgpa($student);
            \check($gpa['cgpa'] === 0.0 && $gpa['total_included_credit_hours'] === 3, 'Later I/W erased eligible failure.');
        }
        DB::table('student_course_registrations')->where('student_course_registration_id', 2031)->update(['registration_status_id' => 1]);
        DB::table('student_course_results')->where('student_course_registration_id', 2031)->update(['result_status_id' => 1, 'theoretical_total' => 35, 'practical_total' => 30, 'final_mark' => 65]);
        \check($grades->calculateCgpa($student)['cgpa'] === 2.25, 'Best eligible passing retake was not selected.');
        echo "CALCULATIONS | PASS | B+F/Z=1.50; missing/high Z marks; I/W exclusion; withdrawal visibility; eligible repeats\n";
    }
}
