<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ScientificVicePresidentSupplementaryExamPeriodController;
use App\Http\Controllers\Api\SupplementaryExamGradingController;
use App\Http\Controllers\Api\SupplementaryExamPeriodController;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\User;
use App\Services\SupplementaryExamGradingService;
use App\Services\SupplementaryExamOccurrenceService;
use App\Services\SupplementaryExamPeriodGovernanceService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\SupplementaryExamOccurrenceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class AcademicCalendarPhase6SupplementaryOccurrenceResponseTest extends TestCase
{
    public function test_professor_roster_is_authorized_then_additively_extended(): void
    {
        $order = 0;
        $user = $this->user();
        $request = $this->request($user, '/api/v1/professor/supplementary-exams/41/grades');
        $period = $this->period();
        $offering = new SupplementaryExamOffering();
        $offering->setRawAttributes([
            'supplementary_exam_offering_id' => 41,
            'supplementary_exam_period_id' => 12,
        ]);
        $offering->setRelation('period', $period);

        $existing = [
            'offering' => ['supplementary_exam_offering_id' => 41],
            'period_status' => 'grading_open',
            'workflow_status' => 'draft',
            'can_edit' => true,
            'roster' => [['supplementary_exam_registration_id' => 90]],
        ];
        $grading = $this->createMock(SupplementaryExamGradingService::class);
        $grading->expects(self::once())
            ->method('roster')
            ->with($user, $offering)
            ->willReturnCallback(function () use (&$order, $existing): array {
                self::assertSame(0, $order++);

                return $existing;
            });
        $occurrence = $this->createMock(SupplementaryExamOccurrenceService::class);
        $occurrence->expects(self::once())
            ->method('snapshotForPeriod')
            ->with($period)
            ->willReturnCallback(function () use (&$order, $period): SupplementaryExamOccurrenceSnapshot {
                self::assertSame(1, $order++);

                return $this->snapshot($period, AcademicCalendarPolicyStatus::OPEN);
            });

        $response = (new SupplementaryExamGradingController())->professorGrades(
            $request,
            $offering,
            $grading,
            $occurrence,
        );
        $data = $response->getData(true)['data'];

        self::assertSame(2, $order);
        foreach ($existing as $key => $value) {
            self::assertSame($value, $data[$key]);
        }
        $this->assertPublicOccurrence($data['supplementary_exam_occurrence']);
    }

    public function test_unauthorized_professor_cannot_trigger_occurrence_evaluation(): void
    {
        $user = $this->user();
        $request = $this->request($user, '/api/v1/professor/supplementary-exams/41/grades');
        $offering = new SupplementaryExamOffering();
        $offering->setRawAttributes(['supplementary_exam_offering_id' => 41]);
        $grading = $this->createMock(SupplementaryExamGradingService::class);
        $grading->expects(self::once())
            ->method('roster')
            ->willThrowException(new RuntimeException('forbidden'));
        $occurrence = $this->createMock(SupplementaryExamOccurrenceService::class);
        $occurrence->expects(self::never())->method('snapshotForPeriod');

        $this->expectException(RuntimeException::class);
        (new SupplementaryExamGradingController())->professorGrades(
            $request,
            $offering,
            $grading,
            $occurrence,
        );
    }

    public function test_generic_period_show_authorizes_before_additive_occurrence(): void
    {
        $this->assertPeriodShowIsAdditive(false);
    }

    public function test_scientific_vp_period_show_authorizes_before_additive_occurrence(): void
    {
        $this->assertPeriodShowIsAdditive(true);
    }

    public function test_unauthorized_period_reader_cannot_trigger_occurrence_evaluation(): void
    {
        $user = $this->user();
        $request = $this->request($user, '/api/v1/supplementary-exam-periods/12');
        $period = $this->period();
        $governance = $this->createMock(SupplementaryExamPeriodGovernanceService::class);
        $governance->expects(self::once())
            ->method('findPeriod')
            ->willThrowException(new RuntimeException('forbidden'));
        $occurrence = $this->createMock(SupplementaryExamOccurrenceService::class);
        $occurrence->expects(self::never())->method('snapshotForPeriod');

        $this->expectException(RuntimeException::class);
        (new SupplementaryExamPeriodController($governance))->show($request, $period, $occurrence);
    }

    private function assertPeriodShowIsAdditive(bool $scientificVicePresident): void
    {
        $order = 0;
        $user = $this->user();
        $request = $this->request($user, '/api/v1/supplementary-exam-periods/12');
        $period = $this->period();
        $governance = $this->createMock(SupplementaryExamPeriodGovernanceService::class);
        $governance->expects(self::once())
            ->method('findPeriod')
            ->with($user, $period)
            ->willReturnCallback(function () use (&$order, $period): SupplementaryExamPeriod {
                self::assertSame(0, $order++);

                return $period;
            });
        $occurrence = $this->createMock(SupplementaryExamOccurrenceService::class);
        $occurrence->expects(self::once())
            ->method('snapshotForPeriod')
            ->with($period)
            ->willReturnCallback(function () use (&$order, $period): SupplementaryExamOccurrenceSnapshot {
                self::assertSame(1, $order++);

                return $this->snapshot($period, AcademicCalendarPolicyStatus::CLOSED);
            });
        $controller = $scientificVicePresident
            ? new ScientificVicePresidentSupplementaryExamPeriodController($governance)
            : new SupplementaryExamPeriodController($governance);

        $data = $controller->show($request, $period, $occurrence)->getData(true)['data'];

        self::assertSame(2, $order);
        self::assertSame(12, $data['supplementary_exam_period_id']);
        self::assertSame('Published period resource field', $data['period_name']);
        self::assertSame('closed', $data['supplementary_exam_occurrence']['status']);
        self::assertFalse($data['supplementary_exam_occurrence']['is_occurring']);
    }

    private function snapshot(
        SupplementaryExamPeriod $period,
        AcademicCalendarPolicyStatus $status,
    ): SupplementaryExamOccurrenceSnapshot {
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');
        $result = new AcademicCalendarPolicyResult(
            $status,
            'supplementary_exams',
            5,
            2,
            $at,
            $status === AcademicCalendarPolicyStatus::OPEN ? 1 : 0,
            $status === AcademicCalendarPolicyStatus::OPEN ? 'effective_window_found' : 'no_effective_window',
        );

        return new SupplementaryExamOccurrenceSnapshot(12, 5, 2, $at, $result);
    }

    /** @param array<string, mixed> $occurrence */
    private function assertPublicOccurrence(array $occurrence): void
    {
        self::assertSame([
            'supplementary_exam_period_id',
            'academic_year_id',
            'semester_id',
            'evaluated_at',
            'status',
            'is_occurring',
            'reason_code',
        ], array_keys($occurrence));
        self::assertSame('open', $occurrence['status']);
        self::assertTrue($occurrence['is_occurring']);
        foreach (['change_reason', 'cancellation_reason', 'created_by_user_id', 'published_by_user_id'] as $privateField) {
            self::assertArrayNotHasKey($privateField, $occurrence);
        }
    }

    private function period(): SupplementaryExamPeriod
    {
        $period = new SupplementaryExamPeriod();
        $period->setRawAttributes([
            'supplementary_exam_period_id' => 12,
            'academic_year_id' => 5,
            'semester_id' => 2,
            'period_name' => 'Published period resource field',
            'status' => 'grading_open',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
        ]);
        $period->setRelation('academicYear', null);
        $period->setRelation('semester', null);
        $period->setRelation('openedBy', null);

        return $period;
    }

    private function user(): User
    {
        $user = new User();
        $user->setAttribute('user_id', 7);

        return $user;
    }

    private function request(User $user, string $uri): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
