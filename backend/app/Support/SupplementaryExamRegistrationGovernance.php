<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

final class SupplementaryExamRegistrationGovernance
{
    public const VIEW='supplementary_exams.registrations.view';
    public const SELF='supplementary_exams.registrations.self';
    public const MANAGE='supplementary_exams.registrations.manage';
    public const WINDOW='supplementary_exams.registrations.window';
    public static function schemaReady(): bool
    {
        if (!SupplementaryExamEligibilityGovernance::schemaReady()) return false;
        try { $b=Schema::connection((string)config('database.default')); if(!$b instanceof Builder||!method_exists($b,'getColumns')||!method_exists($b,'getIndexes')||!method_exists($b,'getForeignKeys'))return false;return self::registrations($b)&&self::events($b)&&self::permissions(); } catch (\Throwable) { return false; }
    }
    private static function registrations(Builder $b): bool
    {
        if(!$b->hasTable('supplementary_exam_registrations'))return false;
        $required=['supplementary_exam_registration_id','supplementary_exam_offering_id','student_id','student_course_registration_id','status','current_slot','eligibility_reason','registration_channel','registered_by_user_id','registered_at','cancelled_by_user_id','cancelled_at','cancellation_reason','eligibility_checked_at','created_at','updated_at'];
        $c=collect($b->getColumns('supplementary_exam_registrations'))->keyBy('name');if(collect($required)->contains(fn($x)=>!$c->has($x)))return false;
        $i=collect($b->getIndexes('supplementary_exam_registrations'));$has=fn($cols,$unique=false)=>$i->contains(fn($x)=>array_values($x['columns']??[])===$cols&&(!$unique||!empty($x['unique'])||!empty($x['primary'])));
        return $has([$required[0]],true)&&$has(['supplementary_exam_offering_id','student_course_registration_id'],true)&&$has(['supplementary_exam_offering_id','student_id','current_slot'],true)&&self::fks($b,'supplementary_exam_registrations',['supplementary_exam_offering_id'=>['supplementary_exam_offerings','supplementary_exam_offering_id'],'student_id'=>['students','student_id'],'student_course_registration_id'=>['student_course_registrations','student_course_registration_id'],'registered_by_user_id'=>['users','user_id'],'cancelled_by_user_id'=>['users','user_id']]);
    }
    private static function events(Builder $b): bool
    {
        if(!$b->hasTable('supplementary_exam_registration_events'))return false;
        $required=['supplementary_exam_registration_event_id','supplementary_exam_registration_id','event_type','from_status','to_status','actor_user_id','notes','created_at'];$c=collect($b->getColumns('supplementary_exam_registration_events'))->keyBy('name');if(collect($required)->contains(fn($x)=>!$c->has($x)))return false;$i=collect($b->getIndexes('supplementary_exam_registration_events'));$has=fn($cols,$primary=false)=>$i->contains(fn($x)=>array_values($x['columns']??[])===$cols&&(!$primary||!empty($x['primary'])));return $has([$required[0]],true)&&$has(['supplementary_exam_registration_id','created_at'])&&$has(['actor_user_id'])&&self::fks($b,'supplementary_exam_registration_events',['supplementary_exam_registration_id'=>['supplementary_exam_registrations','supplementary_exam_registration_id'],'actor_user_id'=>['users','user_id']]);
    }
    private static function permissions():bool{$codes=[self::VIEW,self::SELF,self::MANAGE,self::WINDOW];return DB::table('permissions')->whereIn('permission_code',$codes)->where('is_active',true)->distinct()->count('permission_code')===4;}
    private static function fks(Builder $b,string $table,array $required):bool{$found=[];foreach($b->getForeignKeys($table) as $f){$local=array_values($f['columns']??[]);if(count($local)===1)$found[$local[0]]=[(string)($f['foreign_table']??''),array_values($f['foreign_columns']??[])[0]??''];}foreach($required as $col=>$target)if(($found[$col]??null)!==$target)return false;return true;}
}
