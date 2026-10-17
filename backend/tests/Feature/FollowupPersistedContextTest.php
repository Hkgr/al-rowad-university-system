<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogCompletion\FollowupCatalogService;
use App\Services\StudentCompletion\CompletionData;
use Illuminate\Support\Facades\{Artisan,DB};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\{PersistedFollowupCatalogFixture,StudentCompletionFixture};
use Tests\TestCase;

/** Real writer/loader and authenticated console boundary, synthetic sources/database only. */
final class FollowupPersistedContextTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        parent::setUp();StudentCompletionFixture::initialize();PersistedFollowupCatalogFixture::seed();
        $this->directory=sys_get_temp_dir().'/persisted-followup-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
    }
    protected function tearDown(): void {foreach(glob($this->directory.'/*')?:[] as $f)unlink($f);rmdir($this->directory);parent::tearDown();}
    private function preview(array $artifact,string $suffix='preview',int $actor=1): array
    {
        $output=$this->directory.'/'.$suffix.'.json';$exit=Artisan::call('academic-catalog:complete',['--actor'=>$actor,'--manifest'=>$artifact['path'],'--manifest-hash'=>$artifact['hash'],'--output'=>$output]);
        return [$exit,is_file($output)?json_decode(file_get_contents($output),true,flags:JSON_THROW_ON_ERROR):null,Artisan::output()];
    }
    public function test_persisted_v6_preview_ignores_associative_key_order_but_preserves_every_required_reference_and_source_hash(): void
    {
        $a=PersistedFollowupCatalogFixture::write($this->directory);$r=$a['bundle']['catalog_followup']['programs'][0]['courses'][0];
        $original=$a['original']['catalog_followup']['programs'][0]['courses'][0]['identity_audit']['candidate_context'];$stored=$r['identity_audit']['candidate_context'];
        self::assertNotSame(array_keys($original[0]),array_keys($stored[0]));self::assertSame(CompletionData::hash($original),CompletionData::hash($stored));
        $reuse=0;foreach($a['bundle']['catalog_followup']['programs'] as $p)foreach($p['courses'] as $row)if(isset($row['existing']))$reuse++;self::assertSame(27,$reuse);
        $before=[];foreach(glob($this->directory.'/*') as $file)$before[$file]=hash_file('sha256',$file);$queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        [$exit,$result,$output]=$this->preview($a);$context=[];$other=[];
        foreach($result['review']['programs'] as $p)foreach($p['blockers'] as $blocker){if(str_starts_with($blocker,'course_identity_context_changed:'))$context[]=$blocker;else $other[]=$blocker;}
        self::assertSame([],$other);self::assertCount(0,$context,'Persisted-only false context blockers: '.count($context));
        self::assertSame(0,$exit,$output);self::assertSame('READY',$result['status']);self::assertFalse($result['writes_performed']);
        foreach($result['review']['programs'] as $p)self::assertSame([],$p['blockers']);
        foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|replace|create|alter|drop)\b/i',$sql);
        foreach($before as $file=>$hash)self::assertSame($hash,hash_file('sha256',$file));
        self::assertFalse(User::findOrFail(1)->isSuperAdmin());
        DB::table('users')->insert(['user_id'=>99,'username'=>'synthetic-no-catalog-access','account_status_id'=>1]);
        [$exit,$result,$output]=$this->preview($a,'denied',99);self::assertSame(1,$exit);self::assertNull($result);self::assertStringContainsString('catalog_completion_failed',$output);
    }
    public static function changedReferences(): array {return array_map(fn($field)=>[$field],['id','department_id','program_id','requirement_group_id','kind','missing_field','typed_id']);}
    #[DataProvider('changedReferences')]
    public function test_all_typed_identity_fields_of_each_reviewed_reference_are_required(string $field): void
    {
        $a=PersistedFollowupCatalogFixture::write($this->directory);$b=$a['bundle'];$row=&$b['catalog_followup']['programs'][0]['courses'][0];
        foreach($row['identity_audit']['candidate_context'] as &$reference)if(($reference['program_id']??null)===1901) {
            if($field==='missing_field')unset($reference['requirement_group_id']);
            elseif($field==='typed_id')$reference['id']=(string)$reference['id'];
            elseif($field==='kind')$reference[$field]='program_membership';
            else $reference[$field]+=10000;
        }unset($reference);
        $record=$row['record_id'];unset($row);unset($b['catalog_followup']['hash']);$b['catalog_followup']['hash']=CompletionData::hash($b['catalog_followup']);
        $result=app(FollowupCatalogService::class)->preview(User::findOrFail(1),$b);
        self::assertContains('course_identity_context_changed:'.$record,$result['review']['programs'][0]['blockers']);self::assertSame($a['hash'],hash_file('sha256',$a['path']));
    }
    public function test_missing_live_reference_blocks_even_while_other_reviewed_university_contexts_remain(): void
    {
        $a=PersistedFollowupCatalogFixture::write($this->directory);$pc=DB::table('program_courses')->where('academic_program_id',1901)->where('course_id',403)->value('program_course_id');
        DB::table('program_course_requirement_groups')->where('program_course_id',$pc)->delete();
        [$exit,$result]=$this->preview($a);self::assertSame(1,$exit);self::assertContains('course_identity_context_changed:exam1:DBMater:827',$result['review']['programs'][0]['blockers']);
        self::assertNotContains('course_identity_unverified:exam1:DBMater:827',$result['review']['programs'][0]['blockers']);
    }
    public static function liveChanges(): array {return array_map(fn($field)=>[$field],['id','department','program','group','kind']);}
    #[DataProvider('liveChanges')]
    public function test_actual_live_relationship_changes_block_the_persisted_command_preview(string $field): void
    {
        $a=PersistedFollowupCatalogFixture::write($this->directory);$pc=DB::table('program_courses')->where('academic_program_id',1901)->where('course_id',403)->sole();
        $group=DB::table('academic_requirement_groups')->where('academic_program_id',1901)->where('requirement_type','mandatory')->sole();
        if($field==='department')DB::table('academic_programs')->where('academic_program_id',1901)->update(['department_id'=>1]);
        elseif($field==='kind')DB::table('academic_requirement_groups')->where('requirement_group_id',$group->requirement_group_id)->update(['requirement_scope'=>'college']);
        else {
            DB::table('program_course_requirement_groups')->where('program_course_id',$pc->program_course_id)->delete();
            if($field==='id') {
                DB::table('program_courses')->where('program_course_id',$pc->program_course_id)->delete();$values=(array)$pc;unset($values['plan_scope_key']);$values['program_course_id']+=10000;
                DB::table('program_courses')->insert($values);$pc->program_course_id=$values['program_course_id'];
            } elseif($field==='group') {
                DB::table('academic_requirement_groups')->where('requirement_group_id',$group->requirement_group_id)->delete();$values=(array)$group;unset($values['requirement_group_id'],$values['plan_scope_key']);
                $new=DB::table('academic_requirement_groups')->insertGetId($values,'requirement_group_id');self::assertNotSame((int)$group->requirement_group_id,$new);$group->requirement_group_id=$new;
            } else {
                DB::table('academic_programs')->insert(['academic_program_id'=>1902,'department_id'=>2,'program_code'=>'PERSIST-MOVED','program_name'=>'Synthetic changed context','total_credit_hours'=>2]);
                $values=(array)$group;unset($values['requirement_group_id'],$values['plan_scope_key']);$values['academic_program_id']=1902;$values['group_code']='PERSIST-MOVED-G';
                $group->requirement_group_id=DB::table('academic_requirement_groups')->insertGetId($values,'requirement_group_id');
                DB::table('program_courses')->where('program_course_id',$pc->program_course_id)->update(['academic_program_id'=>1902]);
            }
            DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$pc->program_course_id,'requirement_group_id'=>$group->requirement_group_id]);
        }
        [$exit,$result]=$this->preview($a);self::assertSame(1,$exit);self::assertContains('course_identity_context_changed:exam1:DBMater:827',$result['review']['programs'][0]['blockers']);
        self::assertNotContains('course_identity_unverified:exam1:DBMater:827',$result['review']['programs'][0]['blockers']);self::assertSame($a['hash'],hash_file('sha256',$a['path']));
    }
    public function test_additional_live_reference_is_allowed_without_dropping_any_reviewed_reference(): void
    {
        $a=PersistedFollowupCatalogFixture::write($this->directory);DB::table('course_departments')->insert(['course_id'=>403,'department_id'=>2,'is_primary'=>false]);
        [$exit,$result,$output]=$this->preview($a);self::assertSame(0,$exit,$output);self::assertSame('READY',$result['status']);self::assertSame($a['hash'],hash_file('sha256',$a['path']));
    }
}
