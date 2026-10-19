<?php

namespace Tests\Unit;

use App\Services\StudentCompletion\{CompletionData,CompletionJson,CompletionRecords};
use PHPUnit\Framework\TestCase;

final class CompletionStreamingJsonTest extends TestCase
{
    private function read(string $text,array $lists=[]): array
    {
        $stream=tmpfile();try{CompletionJson::writeAll($stream,$text);rewind($stream);return CompletionJson::read($stream,$lists);}finally{fclose($stream);}
    }
    public function test_spilled_records_preserve_canonical_hash_types_order_and_every_value(): void
    {
        $record=['z'=>'نص "عربي" \\ {} []', 'a'=>[null,false,true,0,'0',1.25,[],['9'=>'9','x'=>'']], 'long'=>str_repeat('boundary\\"[]{}',7000)];
        $expected=['value'=>['review'=>['contract'=>'unchanged','actions'=>array_fill(0,131,$record)]],'actor_id'=>15];
        $text=json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$actual=$this->read($text,['value.review.actions']);
        self::assertInstanceOf(CompletionRecords::class,$actual['value']['review']['actions']);self::assertCount(131,$actual['value']['review']['actions']);
        foreach($actual['value']['review']['actions'] as $index=>$row){self::assertSame($expected['value']['review']['actions'][$index],$row);}
        self::assertSame(CompletionData::hash($expected),CompletionData::hash($actual));
        self::assertSame(CompletionData::json($expected),CompletionData::json($actual));
    }
    public function test_small_arrays_and_empty_objects_remain_legacy_array_compatible(): void
    {
        $json='{"actions":[{"n":0,"nil":null,"empty":{},"bool":true}],"other":[false,"",0.25]}';
        self::assertSame(json_decode($json,true,flags:JSON_THROW_ON_ERROR),$this->read($json,['actions']));
    }
    public function test_a_few_large_records_do_not_force_population_materialization(): void
    {
        $records=new CompletionRecords;$records[]=['evidence'=>str_repeat('x',4194304)];
        self::assertSame($records,$records->compact());self::assertSame(4194304,strlen($records[0]['evidence']));
    }
    public function test_spilling_does_not_reset_the_legacy_global_json_nesting_limit(): void
    {
        foreach([10,508,509,510,511,512] as $depth) {
            $json='{"actions":['.str_repeat('[',$depth).'0'.str_repeat(']',$depth).']}';$valid=true;
            try{$expected=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){$valid=false;}
            foreach([[],['actions']] as $lists) {
                try{$actual=$this->read($json,$lists);self::assertTrue($valid);self::assertSame($expected,$actual);}
                catch(\JsonException|\UnexpectedValueException){self::assertFalse($valid);}
            }
        }
    }
    public function test_keyed_spool_updates_do_not_skip_or_duplicate_iteration_and_hashes_are_order_independent(): void
    {
        $records=new CompletionRecords(false);$records['b']=['v'=>1];$records['a']=['v'=>2];
        foreach($records as $key=>$row)$records[$key]=['v'=>$row['v']+1];
        self::assertSame(['b'=>['v'=>2],'a'=>['v'=>3]],iterator_to_array($records));
        self::assertSame(CompletionData::hash(['a'=>['v'=>3],'b'=>['v'=>2]]),CompletionData::hash($records));
        $records['ع/1']=['v'=>0];self::assertSame(CompletionData::hash(['a'=>['v'=>3],'b'=>['v'=>2],'ع/1'=>['v'=>0]]),CompletionData::hash($records));
    }
    public static function invalidJson(): array
    {
        return array_map(fn($s)=>[$s],['', '{"actions":[', '{"actions":[1,]}','{"a":1,}','{"a":01}','{"a":"\\x"}',
            '{"a":"unterminated}', '{"a":true} garbage','{"a":1,"a":2}','{"actions":[{"x":1]}','{"x":"'.chr(1).'"}']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidJson')]
    public function test_malformed_or_truncated_envelopes_are_never_accepted(string $json): void
    {
        $this->expectException(\Throwable::class);$this->read($json,['actions']);
    }
}
