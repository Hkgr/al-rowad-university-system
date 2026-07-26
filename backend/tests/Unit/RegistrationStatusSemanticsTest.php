<?php

namespace Tests\Unit;

use App\Models\RegistrationStatus;
use App\Models\StudentCourseRegistration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RegistrationStatusSemanticsTest extends TestCase
{
    #[DataProvider('statusSemantics')]
    public function test_write_eligibility_is_distinct_from_historical_visibility(
        string $code,
        bool $attendanceAllowed,
        bool $gradeAllowed
    ): void {
        $registration = new StudentCourseRegistration();
        $status = new RegistrationStatus(['status_code' => $code]);
        $registration->setRelation('registrationStatus', $status);

        $this->assertSame($attendanceAllowed, $registration->allowsAttendanceEntry());
        $this->assertSame($gradeAllowed, $registration->allowsGradeEntry());
    }

    public static function statusSemantics(): array
    {
        return [
            'registered is writable' => ['registered', true, true],
            'completed is historical and read-only' => ['completed', false, false],
            'dropped is excluded' => ['dropped', false, false],
            'withdrawn is excluded' => ['withdrawn', false, false],
        ];
    }

    public function test_domain_status_groups_are_stable_codes(): void
    {
        $this->assertSame('registered', StudentCourseRegistration::CURRENT_STATUS);
        $this->assertSame(['registered', 'completed'], StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES);
        $this->assertSame(['dropped', 'withdrawn'], StudentCourseRegistration::EXCLUDED_STATUSES);
    }
}
