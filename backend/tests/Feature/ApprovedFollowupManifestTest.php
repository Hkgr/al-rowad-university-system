<?php

namespace Tests\Feature;

use App\Services\CatalogCompletion\ApprovedFollowupCatalog;
use App\Services\StudentCompletion\CompletionData;
use PHPUnit\Framework\TestCase;

final class ApprovedFollowupManifestTest extends TestCase
{
    private function input(): array
    {
        $h=['Programs'=>[],'Curriculum'=>[],'CourseMappings'=>[],'SourceRows'=>[]];$programs=[];$amendments=[];
        foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
            $source=['record_id'=>'source:'.$key,'program_key'=>$key,'source_code'=>$i<2?'TI112':'ESI112','credits'=>3,'row_kind'=>'COURSE'];$h['Curriculum'][]=$source;
            $code=$i<2?['TI-CE-112','TI-E-112'][$i]:'SYN-'.$i;$credits=$i===3?2:3;
            $ref=['decision_id'=>'explicit-'.$i,'approved_at'=>'2026-10-17'];$amendments[$source['record_id']]=['before_code'=>$source['source_code'],'before_credits'=>3,'system_code'=>$code,'credit_hours'=>$credits,'approval_reference'=>$ref];
            $groups=[];foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)$groups[]=['requirement_scope'=>$scope,'requirement_type'=>$type,'required_credit_hours'=>$scope==='college'&&$type==='mandatory'?$credits:0,'is_active'=>true];
            $p=['key'=>$key,'college_code'=>$i===3?'INST-CD':'INST-ENG','total_credit_hours'=>$credits,'requirements'=>['groups'=>$groups],
                'courses'=>[['record_id'=>$source['record_id'],'source_hash'=>CompletionData::hash($source),'system_code'=>$code,'credit_hours'=>$credits,'requirement_scope'=>'college','course_type'=>'mandatory']]];
            if($i===3)foreach(range(1,4) as $n) {
                $e=['record_id'=>'evidence:'.$n,'values_json'=>json_encode(['B'=>'Synthetic alternative '.$n])];$h['SourceRows'][]=$e;
                $p['elective_alternatives'][]=['record_id'=>$e['record_id'],'source_hash'=>CompletionData::hash($e),'system_code'=>'ESI-UE-0'.$n,'course_name'=>'Synthetic alternative '.$n,'credit_hours'=>2,'requirement_scope'=>'university','course_type'=>'elective','approval_reference'=>$ref];
            }
            $programs[]=$p;
        }
        $config=['table_hashes'=>array_map(CompletionData::hash(...),$h),'programs'=>$programs,'amendments'=>$amendments];
        return [['sources'=>[],'config'=>['followup'=>['catalog'=>$config]]],$h];
    }
    public function test_explicit_credit_code_and_six_budget_approvals_keep_original_source_values_and_do_not_create_choice_slots(): void
    {
        [$b,$h]=$this->input();$profile=(new ApprovedFollowupCatalog)->compile($b,$h);self::assertCount(4,$profile['programs']);
        self::assertSame('TI-CE-112',$profile['programs'][0]['courses'][0]['system_code']);self::assertSame('TI-E-112',$profile['programs'][1]['courses'][0]['system_code']);
        $environment=$profile['programs'][3];self::assertSame(2,$environment['courses'][0]['credit_hours']);self::assertSame(3,$environment['courses'][0]['source']['credits']);
        self::assertCount(5,$environment['courses']);self::assertSame([2,2,2,2],array_column(array_slice($environment['courses'],1),'credit_hours'));
        self::assertFalse($profile['historical_approval_claimed']);
        unset($b['config']['followup']['catalog']['amendments']['source:environment']);
        $this->expectException(\InvalidArgumentException::class);(new ApprovedFollowupCatalog)->compile($b,$h);
    }
    public function test_pinned_source_evidence_change_is_rejected_without_accepting_an_unchanged_record_id(): void
    {
        [$b,$h]=$this->input();$h['SourceRows'][0]['values_json']='{"B":"Different subject"}';
        $this->expectException(\InvalidArgumentException::class);(new ApprovedFollowupCatalog)->compile($b,$h);
    }
    public function test_missing_is_not_silently_zero_and_a_placeholder_cannot_become_an_origin(): void
    {
        [$b,$h]=$this->input();$b['config']['followup']['catalog']['programs'][0]['requirements']['groups'][0]['required_credit_hours']=null;
        $p=(new ApprovedFollowupCatalog)->compile($b,$h);self::assertContains('approved_requirement_budget_missing:university:mandatory',$p['programs'][0]['blockers']);
        $h['Curriculum'][0]['row_kind']='CHOICE_SLOT';$b['config']['followup']['catalog']['table_hashes']['Curriculum']=CompletionData::hash($h['Curriculum']);
        $b['config']['followup']['catalog']['programs'][0]['courses'][0]['source_hash']=CompletionData::hash($h['Curriculum'][0]);
        $this->expectException(\InvalidArgumentException::class);(new ApprovedFollowupCatalog)->compile($b,$h);
    }
}
