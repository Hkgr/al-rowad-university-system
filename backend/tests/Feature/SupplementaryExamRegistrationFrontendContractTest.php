<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupplementaryExamRegistrationFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(base_path('../frontend/src/features/'.$path));
    }

    public function test_supplementary_pages_use_the_shared_api_request_contract(): void
    {
        foreach ([
            'student-dashboard/pages/StudentSupplementaryExams.jsx',
            'student-affairs/pages/SupplementaryExamRegistrations.jsx',
            'supplementary-exams/ReadOnlyRegistrationList.jsx',
        ] as $file) {
            $source = $this->source($file);
            $this->assertStringContainsString('import { apiRequest }', $source);
            $this->assertStringNotContainsString('apiClient.', $source);
        }
    }

    public function test_student_payload_separates_published_preview_from_official_materialization(): void
    {
        $source = $this->source('student-dashboard/pages/StudentSupplementaryExams.jsx');
        foreach (['published_supplementary_result', 'official_result'] as $field) {
            $this->assertStringContainsString($field, $source);
        }
        $this->assertStringContainsString('لم يُحدّث السجل الأكاديمي الرسمي بعد', $source);
        $this->assertStringContainsString('تم تحديث نتيجتك الأكاديمية الرسمية', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringNotContainsString('window.prompt', $source);
    }

    public function test_student_affairs_uses_bounded_server_search_and_pagination(): void
    {
        $source = $this->source('student-affairs/pages/SupplementaryExamRegistrations.jsx');
        foreach (['per_page', 'rosterSearch', 'rosterPage', '350', 'meta.meta.last_page'] as $proof) {
            $this->assertStringContainsString($proof, $source);
        }
        $this->assertStringContainsString('SupplementaryConfirmDialog', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringNotContainsString('window.prompt', $source);
    }

    public function test_supplementary_effects_do_not_return_async_promises(): void
    {
        foreach ([
            'student-dashboard/pages/StudentSupplementaryExams.jsx',
            'student-affairs/pages/SupplementaryExamRegistrations.jsx',
            'supplementary-exams/ReadOnlyRegistrationList.jsx',
        ] as $file) {
            $this->assertStringNotContainsString('useEffect(async', $this->source($file));
        }
    }
}
