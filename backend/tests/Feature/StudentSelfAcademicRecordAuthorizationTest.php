<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StudentSelfAcademicRecordController;
use App\Models\Student;
use App\Models\User;
use App\Services\ExamStudentAcademicRecordService;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentSelfAcademicRecordAuthorizationTest extends TestCase
{
    public function test_missing_grades_view_is_rejected_before_student_resolution_and_snapshot(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnFalse();
        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::never())->method('snapshot');

        try {
            (new StudentSelfAcademicRecordController())->show($this->request($actor), $records);
            self::fail('Expected grades.view authorization failure.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_account_without_linked_student_is_rejected_before_snapshot(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnTrue();
        $actor->setRelation('student', null);
        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::never())->method('snapshot');

        try {
            (new StudentSelfAcademicRecordController())->show($this->request($actor), $records);
            self::fail('Expected missing student-link authorization failure.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_self_endpoint_uses_only_authenticated_user_student_and_ignores_query_identifier(): void
    {
        $student = $this->student(44);
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->forceFill(['student_id' => 44, 'username' => 'student.44']);
        $actor->setRelation('student', $student);
        $actor->shouldReceive('hasPermission')->once()->with('grades.view')->andReturnTrue();

        $records = $this->createMock(ExamStudentAcademicRecordService::class);
        $records->expects(self::once())->method('snapshot')->with($student, $actor)->willReturn([
            'transcript' => ['summary' => ['cgpa' => 3.1], 'terms' => []],
            'requirements' => ['status' => 'available', 'progress' => []],
            'generation' => ['generated_at' => '2026-09-02T08:00:00+00:00'],
        ]);

        $request = $this->request($actor, '/api/v1/student/academic-record?student_id=999');
        $response = (new StudentSelfAcademicRecordController())->show($request, $records);
        $payload = $response->getData(true)['data'];

        self::assertSame(44, $payload['student']['student_id']);
        self::assertSame('20260044', $payload['student']['student_number']);
        self::assertSame(3.1, $payload['transcript']['summary']['cgpa']);
        self::assertArrayHasKey('requirements', $payload);
        self::assertArrayHasKey('generation', $payload);
    }

    private function request(User $actor, string $uri = '/api/v1/student/academic-record'): Request
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }

    private function student(int $id): Student
    {
        $student = new Student();
        $student->forceFill([
            'student_id' => $id,
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
