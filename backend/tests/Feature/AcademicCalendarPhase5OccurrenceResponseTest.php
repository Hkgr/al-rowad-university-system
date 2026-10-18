<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GradePartWorkflowController;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicAuthorizationService;
use App\Services\GradePartWorkflowService;
use App\Services\RegularExamOccurrenceService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\RegularExamOccurrenceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

class AcademicCalendarPhase5OccurrenceResponseTest extends TestCase
{
    public function test_workflow_response_is_authorized_then_additively_extended(): void
    {
        $order = 0;
        $user = new User();
        $user->setAttribute('user_id', 7);
        $request = Request::create('/api/v1/course-offerings/41/grade-parts-workflow');
        $request->setUserResolver(static fn (): User => $user);
        $offering = new CourseOffering();
        $offering->setRawAttributes([
            'course_offering_id' => 41,
            'academic_year_id' => 5,
            'semester_id' => 2,
        ]);

        $authorization = $this->createMock(AcademicAuthorizationService::class);
        $authorization->expects(self::once())
            ->method('assertCanViewGradeParts')
            ->with($user, 41)
            ->willReturnCallback(function () use (&$order): void {
                self::assertSame(0, $order++);
            });

        $existing = [
            'course_offering_id' => 41,
            'course' => ['course_id' => 3, 'course_name' => 'Existing field'],
            'parts' => ['practical' => ['can_edit' => true]],
            'students' => [['registration_id' => 90]],
        ];
        $workflow = $this->createMock(GradePartWorkflowService::class);
        $workflow->expects(self::once())
            ->method('workflow')
            ->with(41, $user)
            ->willReturnCallback(function () use (&$order, $existing): array {
                self::assertSame(1, $order++);

                return $existing;
            });

        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');
        $practical = $this->result(AcademicCalendarPolicyStatus::OPEN, 'practical_exams', $at);
        $theoretical = $this->result(AcademicCalendarPolicyStatus::CLOSED, 'theoretical_exams', $at);
        $occurrence = $this->createMock(RegularExamOccurrenceService::class);
        $occurrence->expects(self::once())
            ->method('snapshotForOffering')
            ->with($offering)
            ->willReturnCallback(function () use (&$order, $at, $practical, $theoretical): RegularExamOccurrenceSnapshot {
                self::assertSame(2, $order++);

                return new RegularExamOccurrenceSnapshot(41, 5, 2, $at, $practical, $theoretical);
            });

        $response = (new GradePartWorkflowController())->show(
            $offering,
            $request,
            $authorization,
            $workflow,
            $occurrence,
        );
        $data = $response->getData(true)['data'];

        self::assertSame(3, $order);
        foreach ($existing as $key => $value) {
            self::assertArrayHasKey($key, $data);
            self::assertSame($value, $data[$key]);
        }
        self::assertSame('2026-09-03T12:00:00+00:00', $data['regular_exam_occurrence']['evaluated_at']);
        self::assertSame('open', $data['regular_exam_occurrence']['practical']['status']);
        self::assertTrue($data['regular_exam_occurrence']['practical']['is_occurring']);
        self::assertSame('closed', $data['regular_exam_occurrence']['theoretical']['status']);
        self::assertFalse($data['regular_exam_occurrence']['theoretical']['is_occurring']);
        self::assertArrayNotHasKey('change_reason', $data['regular_exam_occurrence']['practical']);
        self::assertArrayNotHasKey('cancellation_reason', $data['regular_exam_occurrence']['practical']);
    }

    private function result(
        AcademicCalendarPolicyStatus $status,
        string $code,
        CarbonImmutable $at,
    ): AcademicCalendarPolicyResult {
        return new AcademicCalendarPolicyResult(
            $status,
            $code,
            5,
            2,
            $at,
            $status === AcademicCalendarPolicyStatus::OPEN ? 1 : 0,
            $status === AcademicCalendarPolicyStatus::OPEN ? 'effective_window_found' : 'no_effective_window',
        );
    }
}
