<?php
namespace Tests\Feature;
use Tests\TestCase;
class SupplementaryExamRegistrationFrontendContractTest extends TestCase
{
 public function test_supplementary_pages_use_real_api_request_contract():void{foreach(['student-dashboard/pages/StudentSupplementaryExams.jsx','student-affairs/pages/SupplementaryExamRegistrations.jsx','supplementary-exams/ReadOnlyRegistrationList.jsx']as$file){$source=file_get_contents(base_path('../frontend/src/features/'.$file));$this->assertStringContainsString('import { apiRequest }',$source);$this->assertStringNotContainsString('import apiClient',$source);$this->assertStringNotContainsString('apiClient.',$source);}}
 public function test_student_affairs_extracts_nested_student_paginator_and_serializes_posts():void{$source=file_get_contents(base_path('../frontend/src/features/student-affairs/pages/SupplementaryExamRegistrations.jsx'));$this->assertStringContainsString('setStudents(payload?.data?.data??[])',$source);$this->assertStringContainsString('new URLSearchParams',$source);$this->assertStringContainsString("method:'POST'",$source);$this->assertStringContainsString('body:JSON.stringify',$source);}
 public function test_student_payloads_are_decoded_json_not_axios_wrappers():void{$source=file_get_contents(base_path('../frontend/src/features/student-dashboard/pages/StudentSupplementaryExams.jsx'));$this->assertStringContainsString('setRows(eligibility?.data??[])',$source);$this->assertStringContainsString('setRegistrations(registrationPayload?.data??[])',$source);$this->assertStringNotContainsString('.data?.data',$source);}
}
