<?php

namespace Tests\Feature;

use App\Models\{AcademicPlanVersion, ProgramCourse, Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow};
use App\Services\HistoricalExams\{ExamCatalogCreditService, ExamSources, ExamApprovedAuthority};
use App\Services\StudentCompletion\CompletionData;
use App\Support\ApplicableCourseCredits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{AcademicPlanFixture, SyntheticApprovedExamSources};
use Tests\TestCase;

/** Behavioral workflow coverage; actual DDL and locking are verified in the MariaDB harness. */
final class HistoricalExamCatalogCreditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();AcademicPlanFixture::initialize();SyntheticApprovedExamSources::schema();
        DB::table('academic_programs')->where('academic_program_id',1)->update(['program_code'=>'RU2-ES-TS','total_credit_hours'=>141]);
        DB::table('academic_requirement_groups')->where('requirement_scope','college')->where('requirement_type','mandatory')->update(['required_credit_hours'=>62]);
        DB::table('academic_requirement_groups')->where('requirement_scope','department')->where('requirement_type','mandatory')->update(['required_credit_hours'=>76]);
        foreach ([['ES421',2,'college'],['FILL1',30,'college'],['FILL2',30,'college'],['FILL3',30,'department'],['FILL4',30,'department'],['FILL5',16,'department']] as [$code,$hours,$scope]) {
            $course=DB::table('courses')->insertGetId(['course_code'=>$code,'course_name'=>$code,'credit_hours'=>$hours],'course_id');
            $member=DB::table('program_courses')->insertGetId(['academic_program_id'=>1,'course_id'=>$course,'course_type'=>'mandatory','academic_level_id'=>1,'recommended_semester_id'=>1],'program_course_id');
            $group=DB::table('academic_requirement_groups')->where('requirement_scope',$scope)->where('requirement_type','mandatory')->value('requirement_group_id');
            DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$member,'requirement_group_id'=>$group]);
        }
    }
    private function bundle(): array
    {
        return ['contract'=>ExamSources::APPROVED_CONTRACT,'approved_authority'=>['revision'=>ExamApprovedAuthority::REVISION,'document_hashes'=>ExamApprovedAuthority::PINS],
            'snapshot'=>['courses'=>DB::table('courses')->get()->map(fn($c)=>(array)$c)->all()]];
    }
    private function preview(array $bundle): array {return app(ExamCatalogCreditService::class)->preview(User::findOrFail(1),$bundle,['RU2-ES-TS']);}
    private function apply(array $bundle,array $p,?string $id=null,?string $revision=null,bool $resume=false): array
    {
        return app(ExamCatalogCreditService::class)->apply(User::findOrFail(1),$bundle,$p['review'],$p['review_hash'],$id??(string)Str::uuid(),$revision??$p['review']['revision'],$resume);
    }
    public function test_approved_credit_publication_preserves_origins_historical_memberships_and_existing_students_and_replays_before_stale(): void
    {
        $bundle=$this->bundle();$before=DB::table('courses')->orderBy('course_id')->get()->toJson();$p=$this->preview($bundle);
        self::assertSame('READY',$p['status']);self::assertSame(0,AcademicPlanVersion::count());
        $id=(string)Str::uuid();$result=$this->apply($bundle,$p,$id);$version=$result['programs'][0]['published_version_id'];$source=$result['programs'][0]['source_version_id'];
        self::assertSame(142,AcademicPlanVersion::findOrFail($version)->total_credit_hours);self::assertSame(141,AcademicPlanVersion::findOrFail($source)->total_credit_hours);
        self::assertSame($source,AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
        self::assertSame($version,(int)DB::table('academic_programs')->value('default_academic_plan_version_id'));
        self::assertSame($before,DB::table('courses')->orderBy('course_id')->get()->toJson());
        $old=ProgramCourse::with('course')->where('academic_plan_version_id',$source)->whereHas('course',fn($q)=>$q->where('course_code','ES421'))->sole();
        $new=ProgramCourse::with('course')->where('academic_plan_version_id',$version)->where('course_id',$old->course_id)->sole();
        self::assertSame(2,ApplicableCourseCredits::planHours($old));self::assertSame(3,ApplicableCourseCredits::planHours($new));
        self::assertSame($result,$this->apply($bundle,$p,$id,'0',true));self::assertSame(2,AcademicPlanVersion::count());
        self::assertTrue(app(ExamCatalogCreditService::class)->status(User::findOrFail(1),$id,$bundle)['publication_verified']);
        $copy=app(AcademicPlanWorkflow::class)->copy(User::findOrFail(1),1,$version,['revision'=>app(AcademicCatalogTransaction::class)->revision(),'label'=>'Later synthetic copy']);
        $copied=ProgramCourse::with('course')->where('academic_plan_version_id',$copy['version']->getKey())->where('course_id',$old->course_id)->sole();
        self::assertSame(3,ApplicableCourseCredits::planHours($copied));
    }
    public function test_failed_canonical_approval_rolls_back_fixation_publication_credits_assignments_and_receipts(): void
    {
        DB::table('academic_requirement_groups')->where('requirement_scope','university')->where('requirement_type','elective')->delete();
        $bundle=$this->bundle();$p=$this->preview($bundle);$before=DB::table('program_courses')->orderBy('program_course_id')->get()->toJson();
        try {$this->apply($bundle,$p);self::fail('Incomplete plan was published');}catch(\Illuminate\Validation\ValidationException $e){self::assertNotEmpty($e->errors());}
        self::assertSame(0,AcademicPlanVersion::count());self::assertSame(0,DB::table('student_academic_plan_assignments')->count());
        self::assertSame(0,DB::table('plan_course_credit_evidence')->count());self::assertSame(0,DB::table('academic_plan_events')->count());
        self::assertSame($before,DB::table('program_courses')->orderBy('program_course_id')->get()->toJson());
    }
    public function test_context_changes_and_authorization_cannot_be_bypassed_by_replay_or_snapshot_numeric_ids(): void
    {
        $bundle=$this->bundle();$p=$this->preview($bundle);
        DB::table('courses')->where('course_code','ES421')->update(['course_name'=>'Different academic subject']);
        self::assertSame('BLOCKED',$this->preview($bundle)['status']);
        try{$this->apply($bundle,$p);self::fail('Stale publication accepted');}catch(\App\Services\StudentCompletion\CompletionException $e){self::assertStringContainsString('stale',$e->errorCode);}
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'employee']);
        try{$this->preview($bundle);self::fail('Non-scientific user published');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
    }
}
