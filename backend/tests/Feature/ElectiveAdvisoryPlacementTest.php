<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{AcademicCatalogTransaction, AcademicPlanWorkflow};
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** HTTP + SQLite application/read semantics only; upgrade and real CHECKs live in MariaDB tests. */
final class ElectiveAdvisoryPlacementTest extends TestCase
{
    private const API = '/api/v1/vice-presidency/scientific/program-management';
    private int $version;
    protected function setUp(): void
    {
        parent::setUp(); \Tests\Support\AcademicPlanFixture::initialize();
        $actor = User::findOrFail(1); Sanctum::actingAs($actor);
        $workflow = app(AcademicPlanWorkflow::class);
        $source = $workflow->fixTransition($actor,1,['revision'=>$this->revision(),'confirmed'=>true])['current_version_id'];
        $this->version = (int)$workflow->copy($actor,1,$source,['revision'=>$this->revision(),'label'=>'Synthetic elective draft'])['version']->getKey();
    }
    private function revision(): string { return app(AcademicCatalogTransaction::class)->revision(); }
    private function input(string $type, ?int $level, ?int $semester): array
    {
        return ['revision'=>$this->revision(),'course_type'=>$type,'requirement_scope'=>'university',
            'academic_level_id'=>$level,'recommended_semester_id'=>$semester,'is_active'=>true];
    }
    public function test_unplaced_elective_is_read_without_zero_or_lost_membership_and_explicit_placement_is_preserved(): void
    {
        $url=self::API.'/1/versions/'.$this->version.'/courses/1';
        $this->putJson($url,$this->input('elective',null,null))->assertOk();
        $this->getJson(self::API.'/1/versions/'.$this->version)->assertOk()->assertJsonPath('data.courses.0.academic_level_id',null)
            ->assertJsonPath('data.courses.0.recommended_semester_id',null);
        $workspace=$this->getJson(self::API.'/workspace?academic_program_id=1&version_id='.$this->version)->assertOk();
        self::assertSame(1,$workspace->json('data.meta.total'));
        self::assertNull($workspace->json('data.rows.0.academic_level_id'));
        $context = new \App\Services\AcademicPlanContext(1,$this->version);
        $groups = app(\App\Services\AcademicRequirementService::class)->forPlanContext($context)->getProgramRequirements(1);
        $electives = $groups->where('requirement_scope','university')->firstWhere('requirement_type','elective');
        self::assertSame(1,$electives['course_count']);
        self::assertSame(3,$electives['pool_credit_hours']);
        self::assertSame(0,$electives['required_credit_hours']); // Pool capacity never rewrites the budget.
        self::assertSame(1,(int)$electives['courses'][0]['course_id']);
        $this->putJson($url,$this->input('elective',1,1))->assertOk();
        self::assertSame(1,(int)DB::table('program_courses')->where('academic_plan_version_id',$this->version)->value('academic_level_id'));
        // Original fixed membership stays placed and keeps its identifier.
        self::assertSame(1,(int)DB::table('program_courses')->where('program_course_id',1)->value('academic_level_id'));
    }
    public function test_mandatory_missing_and_all_partial_combinations_are_rejected_without_writes(): void
    {
        $before=DB::table('program_courses')->get()->all(); $events=DB::table('academic_plan_events')->count();
        foreach ([['mandatory',null,null],['mandatory',1,null],['mandatory',null,1],['elective',1,null],['elective',null,1]] as [$type,$level,$semester])
            $this->putJson(self::API.'/1/versions/'.$this->version.'/courses/1',$this->input($type,$level,$semester))->assertUnprocessable()->assertJsonValidationErrors('academic_level_id');
        self::assertEquals($before,DB::table('program_courses')->get()->all());
        self::assertSame($events,DB::table('academic_plan_events')->count());
    }
    public function test_current_role_scope_and_fixed_membership_guards_are_not_placement_exemptions(): void
    {
        DB::table('role_permissions')->delete();
        $this->putJson(self::API.'/1/versions/'.$this->version.'/courses/1',$this->input('elective',null,null))->assertForbidden();
        self::assertSame(2,DB::table('program_courses')->count());
    }
}
