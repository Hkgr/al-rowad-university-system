<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ExamStudentAcademicRecordController;
use App\Models\Student;
use App\Models\User;
use App\Services\ExamStudentAcademicRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExamStudentAcademicRecordAuthorizationTest extends TestCase
{
    public function test_missing_grades_view_is_rejected_before_policy_and_snapshot(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnFalse();
        $request = $this->request($actor);
        $student = $this->student();
        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::never())->method('snapshot');
        Gate::shouldReceive('authorize')->never();

        try {
            (new ExamStudentAcademicRecordController())->show($request, $student, $records);
            self::fail('Expected grades.view authorization failure.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_student_policy_denial_blocks_direct_id_access_before_snapshot(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnTrue();
        $request = $this->request($actor);
        $student = $this->student();
        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::never())->method('snapshot');
        Gate::shouldReceive('authorize')->once()->with('view', $student)->andThrow(new AuthorizationException());

        $this->expectException(AuthorizationException::class);
        (new ExamStudentAcademicRecordController())->show($request, $student, $records);
    }

    public function test_authorized_direct_id_access_returns_additive_snapshot_and_official_identity(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnTrue();
        $request = $this->request($actor);
        $student = $this->student();
        Gate::shouldReceive('authorize')->once()->with('view', $student)->andReturn(Response::allow());
        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::once())->method('snapshot')->with($student, $actor)->willReturn([
            'transcript' => ['summary' => ['cgpa' => 3.5], 'terms' => []],
            'requirements' => ['status' => 'unavailable'],
            'generation' => ['generated_at' => '2026-08-30T10:00:00+00:00'],
        ]);

        $response = (new ExamStudentAcademicRecordController())->show($request, $student, $records);
        $payload = $response->getData(true)['data'];

        self::assertSame(3.5, $payload['transcript']['summary']['cgpa']);
        self::assertSame('unavailable', $payload['requirements']['status']);
        self::assertSame(44, $payload['student']['student_id']);
        self::assertSame('20260044', $payload['student']['student_number']);
        self::assertSame('ليان أحمد', $payload['student']['full_name']);
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/api/v1/students/44/academic-record', 'GET');
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }

    private function student(): Student
    {
        $student = new Student();
        $student->forceFill([
            'student_id' => 44,
            'student_number' => '20260044',
            'first_name' => 'ليان',
            'last_name' => 'أحمد',
        ]);
        $student->setRelation('academicProgram', null);
        $student->setRelation('currentAcademicLevel', null);
        $student->setRelation('studentStatus', null);

        return $student;
    }
}
