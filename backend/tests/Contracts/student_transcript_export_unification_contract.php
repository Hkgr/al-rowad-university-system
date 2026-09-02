<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend/src';
    $paths = [
        'routes' => $backendRoot.'/routes/api.php',
        'self_controller' => $backendRoot.'/app/Http/Controllers/Api/StudentSelfAcademicRecordController.php',
        'action' => $frontendRoot.'/features/academic-record/components/TranscriptPdfExportAction.jsx',
        'pdf' => $frontendRoot.'/features/academic-record/lib/transcriptPdf.js',
        'exam_board' => $frontendRoot.'/features/exam-board/pages/ExamStudentAcademicRecordPage.jsx',
        'student_affairs' => $frontendRoot.'/features/student-affairs/pages/StudentProfilePage.jsx',
        'student' => $frontendRoot.'/features/student-dashboard/pages/StudentTranscript.jsx',
        'dean' => $frontendRoot.'/features/dean-dashboard/pages/DeanStudentProfile.jsx',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing transcript-unification source: '.$name;
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };

    foreach (['exam_board', 'student_affairs', 'student', 'dean'] as $surface) {
        $expect(str_contains($sources[$surface], 'academic-record/components/TranscriptPdfExportAction'), 'Missing shared export action on '.$surface.'.');
    }
    $expect(str_contains($sources['student'], 'endpoint="/v1/student/academic-record"'), 'Student UI must use the identifier-free self endpoint.');
    $expect(str_contains($sources['student_affairs'], '/v1/students/${id}/academic-record'), 'Student Affairs must use the authorized staff aggregate endpoint.');
    $expect(str_contains($sources['dean'], '/v1/students/${id}/academic-record'), 'Dean must use the authorized staff aggregate endpoint.');

    foreach (['if (exporting.current) return', 'apiRequest(endpoint)', 'exportTranscriptPdf({ academicRecord: response.data })', 'جاري إنشاء الملف...', 'استخراج كشف العلامات الإلكتروني', 'تعذّر إنشاء كشف العلامات الإلكتروني. يرجى المحاولة مجدداً.'] as $required) {
        $expect(str_contains($sources['action'], $required), 'Shared export action contract missing: '.$required);
    }

    $generatorFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['js', 'jsx'], true)) {
            continue;
        }
        if (preg_match('/export\s+(?:async\s+)?function\s+exportTranscriptPdf/', (string) file_get_contents($file->getPathname()))) {
            $generatorFiles[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    $expect(count($generatorFiles) === 1 && str_ends_with($generatorFiles[0] ?? '', '/features/academic-record/lib/transcriptPdf.js'), 'There must be exactly one shared transcript PDF generator.');

    foreach (['html2canvas', 'new jsPDF', 'pdfContentRef', 'heightLeft', 'position -= pageHeight'] as $legacy) {
        $expect(! str_contains($sources['student_affairs'], $legacy), 'Legacy tall-canvas export remains: '.$legacy);
    }
    $expect(! str_contains($sources['student'], 'window.print'), 'Student transcript still uses browser print.');

    foreach (['academicRecord?.student', 'academicRecord?.transcript', 'academicRecord?.requirements', 'academicRecord?.generation', 'paginateMeasuredSections', 'صفحة ${pageNumber} من ${totalPages}'] as $required) {
        $expect(str_contains($sources['pdf'], $required), 'Shared PDF contract missing: '.$required);
    }
    foreach (['QR', 'verification_code', 'document_number'] as $forbidden) {
        $expect(! str_contains($sources['pdf'], $forbidden), 'Shared PDF invents unsupported document metadata: '.$forbidden);
    }

    $expect(str_contains($sources['routes'], "Route::get('student/academic-record', [StudentSelfAcademicRecordController::class, 'show'])"), 'Missing self academic-record route.');
    $expect(str_contains($sources['self_controller'], '$student = $actor->student;'), 'Self controller must resolve the authenticated student relation.');
    foreach (['$request->input(', '$request->query(', '$request->route('] as $untrusted) {
        $expect(! str_contains($sources['self_controller'], $untrusted), 'Self controller accepts an untrusted student selector: '.$untrusted);
    }

    foreach (['student/transcript', 'students/{student}/transcript', 'students/{student}/academic-record'] as $existingRoute) {
        $expect(str_contains($sources['routes'], $existingRoute), 'Existing academic-record route disappeared: '.$existingRoute);
    }
    $expect((glob($backendRoot.'/database/migrations/*transcript*') ?: []) === [], 'Transcript unification must not add migrations.');
    $expect(! is_dir($backendRoot.'/database/sql/student-transcript-export'), 'Transcript unification must not add SQL.');

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, $error.PHP_EOL);
        }
        exit(1);
    }
    fwrite(STDOUT, "Student transcript export unification contract passed.\n");
}

return $contract;
