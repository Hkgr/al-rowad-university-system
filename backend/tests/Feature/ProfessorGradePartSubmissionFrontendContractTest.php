<?php
namespace Tests\Feature;
use Tests\TestCase;
class ProfessorGradePartSubmissionFrontendContractTest extends TestCase
{
 public function test_professor_screen_submits_only_selected_ready_part():void{$api=file_get_contents(base_path('../frontend/src/features/professor-dashboard/lib/professorApi.js'));$page=file_get_contents(base_path('../frontend/src/features/professor-dashboard/pages/ProfessorGradesPage.jsx'));$this->assertStringContainsString('submitGradePart = (offeringId, part)',$api);$this->assertStringContainsString('/grade-parts/${part}/submit',$api);$this->assertStringContainsString('selectedPartCanSubmit',$page);$this->assertStringContainsString('partState?.can_submit === true',$page);$this->assertStringContainsString('await submitGradePart(offeringId, part)',$page);$this->assertStringNotContainsString('actionableAssignedParts.every',$page);$this->assertStringNotContainsString('يجب إرسال الجزأين النظري والعملي معًا',$page);$this->assertStringContainsString('ولن تتغير حالة الجزء الآخر',$page);}
}
