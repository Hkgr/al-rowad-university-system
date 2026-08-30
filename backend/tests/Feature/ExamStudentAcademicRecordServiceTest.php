<?php

namespace Tests\Feature;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Models\AcademicProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicRequirementService;
use App\Services\ExamStudentAcademicRecordService;
use App\Services\GradeService;
use App\Services\GraduationEligibilityService;
use App\Services\UserIdentityService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ExamStudentAcademicRecordServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_uses_each_canonical_read_once_and_reuses_progress(): void
    {
        CarbonImmutable::setTestNow('2026-08-30T09:10:11Z');
        $student = $this->student(17);
        $actor = new User(['username' => 'exam.operator']);
        $progress = ['academic_program_id' => 17, 'groups' => []];
        $transcript = ['student_id' => 8, 'summary' => ['cgpa' => 3.25], 'terms' => []];
        $eligibility = ['eligible' => false, 'groups' => []];

        $grades = $this->createMock(GradeService::class);
        $grades->expects(self::once())->method('getTranscript')->with($student)->willReturn($transcript);
        $requirements = $this->createMock(AcademicRequirementService::class);
        $requirements->expects(self::once())->method('getStudentRequirementProgress')->with($student)->willReturn($progress);
        $graduation = $this->createMock(GraduationEligibilityService::class);
        $graduation->expects(self::once())->method('evaluateFromProgress')->with($student, $progress)->willReturn($eligibility);
        $identities = $this->createMock(UserIdentityService::class);
        $identities->expects(self::once())->method('documentGenerator')->with($actor)->willReturn([
            'display_name' => 'موظف الامتحانات',
            'username' => 'exam.operator',
            'organizational_unit' => null,
        ]);

        $snapshot = (new ExamStudentAcademicRecordService($grades, $requirements, $graduation, $identities))
            ->snapshot($student, $actor);

        self::assertSame($transcript, $snapshot['transcript']);
        self::assertSame('available', $snapshot['requirements']['status']);
        self::assertSame($progress, $snapshot['requirements']['progress']);
        self::assertSame($eligibility, $snapshot['requirements']['graduation_eligibility']);
        self::assertSame('2026-08-30T09:10:11+00:00', $snapshot['generation']['generated_at']);
        self::assertSame('Asia/Damascus', $snapshot['generation']['timezone']);
        self::assertSame('موظف الامتحانات', $snapshot['generation']['generated_by']['display_name']);
    }

    public function test_requirement_configuration_failure_does_not_drop_transcript(): void
    {
        $student = $this->student(17);
        $actor = new User(['username' => 'exam.operator']);
        $transcript = ['student_id' => 8, 'summary' => ['cgpa' => 2.9], 'terms' => []];

        $grades = $this->createMock(GradeService::class);
        $grades->expects(self::once())->method('getTranscript')->willReturn($transcript);
        $requirements = $this->createMock(AcademicRequirementService::class);
        $requirements->expects(self::once())->method('getStudentRequirementProgress')->willThrowException(
            new AcademicRequirementConfigurationException('Invalid requirement setup.')
        );
        $graduation = $this->createMock(GraduationEligibilityService::class);
        $graduation->expects(self::never())->method('evaluateFromProgress');
        $identities = $this->createMock(UserIdentityService::class);
        $identities->method('documentGenerator')->willReturn([
            'display_name' => 'exam.operator',
            'username' => 'exam.operator',
            'organizational_unit' => null,
        ]);

        $snapshot = (new ExamStudentAcademicRecordService($grades, $requirements, $graduation, $identities))
            ->snapshot($student, $actor);

        self::assertSame($transcript, $snapshot['transcript']);
        self::assertSame('unavailable', $snapshot['requirements']['status']);
        self::assertSame(AcademicRequirementConfigurationException::ERROR_CODE, $snapshot['requirements']['error_code']);
        self::assertNull($snapshot['requirements']['progress']);
        self::assertNull($snapshot['requirements']['graduation_eligibility']);
    }

    private function student(int $programId): Student
    {
        $student = new Student();
        $student->forceFill(['student_id' => 8, 'academic_program_id' => $programId]);
        $program = new AcademicProgram();
        $program->forceFill(['academic_program_id' => $programId]);
        $program->setRelation('department', null);
        $student->setRelation('academicProgram', $program);
        $student->setRelation('currentAcademicLevel', null);
        $student->setRelation('studentStatus', null);

        return $student;
    }
}
