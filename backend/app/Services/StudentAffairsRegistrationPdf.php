<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Services\Payroll\PayrollPdfDocument;
use App\Services\Payroll\PayrollPdfExport;
use App\Support\ApplicableCourseCredits;

/** Arabic registration sheet: the courses already saved for one term, signed by the advisor and the dean. */
final class StudentAffairsRegistrationPdf
{
    public function __construct(private readonly PayrollPdfExport $fonts) {}

    public function forStudent(Student $student, int $yearId, int $semesterId): string
    {
        $student->loadMissing(['academicProgram.department.college', 'currentAcademicLevel']);
        $year = AcademicYear::query()->findOrFail($yearId);
        $semester = Semester::query()->findOrFail($semesterId);
        $registrations = StudentCourseRegistration::query()
            ->with(['courseOffering.course'])
            ->where('student_id', $student->student_id)
            ->whereHas('registrationStatus', fn ($query) => $query->where('status_code', StudentCourseRegistration::CURRENT_STATUS))
            ->whereHas('courseOffering', fn ($query) => $query
                ->where('academic_year_id', $yearId)
                ->where('semester_id', $semesterId))
            ->get();
        ApplicableCourseCredits::attach($registrations);

        $courses = $registrations->map(function (StudentCourseRegistration $registration): array {
            $course = $registration->courseOffering?->course;

            return [
                'code' => (string) ($course->course_code ?? ''),
                'name' => (string) ($course->course_name ?? ''),
                'hours' => (int) (ApplicableCourseCredits::hours($registration) ?? 0),
            ];
        })->sortBy('code', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        if ($courses === []) {
            throw new RegistrationException('لا توجد مواد مسجلة لهذا الطالب في الفصل المحدد.', [
                'semester_id' => ['لا توجد مواد مسجلة لهذا الطالب في الفصل المحدد.'],
            ]);
        }

        $program = $student->academicProgram;

        return $this->bytes([
            'student_name' => $this->studentName($student),
            'student_number' => (string) $student->student_number,
            'program' => (string) ($program?->program_name ?? ''),
            'college' => (string) ($program?->department?->college?->college_name ?? ''),
            'department' => (string) ($program?->department?->department_name ?? ''),
            'level' => (string) ($student->currentAcademicLevel?->level_name ?? ''),
            'year' => (string) $year->year_name,
            'semester' => (string) $semester->semester_name,
            'printed_on' => now()->format('Y-m-d'),
            'courses' => $courses,
        ]);
    }

    /** @param array{student_name: string, student_number: string, program: string, college: string, department: string, level: string, year: string, semester: string, printed_on: string, courses: list<array{code: string, name: string, hours: int}>} $sheet */
    public function bytes(array $sheet): string
    {
        $fontDir = $this->fonts->fontDirectory();
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontDir.'/');
        }

        $logo = base_path('../frontend/public/logo.png');
        $pdf = new PayrollPdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setCreator('Alrowad University');
        $pdf->setTitle('كشف تسجيل المواد');
        $pdf->setRTL(true);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setMargins(14, 36, 14);
        $pdf->setHeaderMargin(8);
        $pdf->setFooterMargin(12);
        $pdf->setAutoPageBreak(true, 18);
        $pdf->setCellPaddings(1.4, 0.8, 1.4, 0.8);
        $pdf->AddFont('cairo', '', $fontDir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $fontDir.'/cairob.php');
        $pdf->logoPath = is_file($logo) ? $logo : null;
        $pdf->headerLines = [
            'جامعة الرواد للعلوم والتقانة',
            'كشف تسجيل المواد',
            $this->text($sheet['year']).' — '.$this->text($sheet['semester']),
        ];
        $pdf->AddPage();

        $this->facts($pdf, [
            ['اسم الطالب', $sheet['student_name']],
            ['رقم القيد', $sheet['student_number']],
            ['الكلية', $sheet['college']],
            ['القسم', $sheet['department']],
            ['البرنامج', $sheet['program']],
            ['المستوى', $sheet['level']],
            ['تاريخ الطباعة', $sheet['printed_on']],
        ]);

        $pdf->Ln(2);
        $widths = [14, 32, 118, 16];
        $this->row($pdf, ['م', 'رمز المادة', 'اسم المادة', 'الساعات'], $widths, true, false);
        $total = 0;
        foreach ($sheet['courses'] as $index => $course) {
            $total += $course['hours'];
            $this->row($pdf, [
                (string) ($index + 1),
                $course['code'],
                $course['name'],
                (string) $course['hours'],
            ], $widths, false, $index % 2 === 1);
        }

        $pdf->Ln(3);
        $pdf->setFont('cairo', 'B', 11);
        $pdf->MultiCell(0, 7, 'عدد المواد: '.count($sheet['courses']).' — مجموع الساعات: '.$total, 0, 'R', false, 1);

        $this->signatures($pdf);

        return $pdf->Output('registration.pdf', 'S');
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    private function facts(PayrollPdfDocument $pdf, array $pairs): void
    {
        $width = ($pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right']) / 2;
        foreach (array_chunk($pairs, 2) as $row) {
            if (count($row) === 1) {
                $row[] = ['', ''];
            }
            foreach ($row as $pair) {
                $label = $pair[0] === '' ? '' : $pair[0].': '.$this->text($pair[1]);
                $pdf->setFont('cairo', '', 11);
                $pdf->MultiCell($width, 8, $label, 0, 'R', false, 0, '', '', true, 0, false, true, 8, 'M');
            }
            $pdf->Ln(8);
        }
    }

    /** @param list<string> $cells @param list<float> $widths */
    private function row(PayrollPdfDocument $pdf, array $cells, array $widths, bool $header, bool $shade): void
    {
        $height = 8;
        if ($header) {
            $pdf->setFillColor(22, 92, 62);
            $pdf->setTextColor(255, 255, 255);
            $pdf->setFont('cairo', 'B', 10);
        } else {
            $pdf->setFillColor($shade ? 245 : 255, $shade ? 248 : 255, $shade ? 244 : 255);
            $pdf->setTextColor(30, 30, 30);
            $pdf->setFont('cairo', '', 10);
        }
        foreach ($cells as $index => $cell) {
            $pdf->MultiCell($widths[$index], $height, $cell, 1, 'C', true, 0, '', '', true, 0, false, true, $height, 'M');
        }
        $pdf->Ln($height);
        $pdf->setTextColor(0, 0, 0);
    }

    private function signatures(PayrollPdfDocument $pdf): void
    {
        if ($pdf->getY() > 220) {
            $pdf->AddPage();
        }
        $pdf->Ln(6);
        $pdf->setTextColor(30, 30, 30);
        $pdf->setFont('cairo', '', 10);
        $pdf->MultiCell(0, 6, 'يُطبع هذا الكشف بعد تسجيل المواد في النظام، ليوقّعه المرشد الأكاديمي، ثم يصادق عليه عميد الكلية.', 0, 'R', false, 1);
        $pdf->Ln(3);

        $width = ($pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right']) / 2;
        $rows = [
            ['المرشد الأكاديمي', 'عميد الكلية'],
            ['الاسم: ........................', 'المصادقة: ........................'],
            ['التوقيع: ........................', 'التوقيع: ........................'],
            ['التاريخ: ........................', 'التاريخ: ........................'],
        ];
        foreach ($rows as $index => $row) {
            $pdf->setFont('cairo', $index === 0 ? 'B' : '', $index === 0 ? 12 : 11);
            if ($index === 0) {
                $pdf->setFillColor(236, 244, 239);
            }
            $pdf->MultiCell($width, $index === 0 ? 9 : 12, $row[0], 1, 'C', $index === 0, 0, '', '', true, 0, false, true, $index === 0 ? 9 : 12, 'M');
            $pdf->MultiCell($width, $index === 0 ? 9 : 12, $row[1], 1, 'C', $index === 0, 1, '', '', true, 0, false, true, $index === 0 ? 9 : 12, 'M');
        }
    }

    private function studentName(Student $student): string
    {
        return trim(implode(' ', array_filter(
            [$student->first_name, $student->father_name, $student->last_name],
            fn ($part) => $part !== null && trim((string) $part) !== '',
        )));
    }

    private function text(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '—' : $value;
    }
}
