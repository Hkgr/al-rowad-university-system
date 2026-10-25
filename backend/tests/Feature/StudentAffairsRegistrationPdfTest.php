<?php

namespace Tests\Feature;

use App\Services\StudentAffairsRegistrationPdf;
use Tests\TestCase;

class StudentAffairsRegistrationPdfTest extends TestCase
{
    public function test_registration_sheet_is_a_pdf(): void
    {
        $bytes = app(StudentAffairsRegistrationPdf::class)->bytes([
            'student_name' => 'رشاد تجربة تجربة',
            'student_number' => '2026-005',
            'program' => 'هندسة المعلوماتية',
            'college' => 'كلية الهندسة',
            'department' => 'هندسة المعلوماتية',
            'level' => 'السنة الأولى',
            'year' => '2027-2026',
            'semester' => 'الفصل الأول',
            'printed_on' => '2026-10-25',
            'courses' => [
                ['code' => 'FMF114', 'name' => 'رياضيات', 'hours' => 3],
                ['code' => 'RU112', 'name' => 'لغة إنكليزية', 'hours' => 2],
            ],
        ]);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(2000, strlen($bytes));
    }
}
