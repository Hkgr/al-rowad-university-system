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
        try { $b=Schema::connection((string)config('database.default')); if(!$b instanceof Builder||!method_exists($b,'getColumns')||!method_exists($b,'getIndexes')||!method_exists($b,'getForeignKeys'))return false;return self::periodLifecycleReady($b)&&self::registrations($b)&&self::events($b)&&self::permissions(); } catch (\Throwable) { return false; }
    }
    private static function periodLifecycleReady(Builder $b):bool
    {
        if(!$b->hasTable('supplementary_exam_periods')||!$b->hasTable('supplementary_exam_period_events'))return false;
        $p=collect($b->getColumns('supplementary_exam_periods'))->keyBy('name');$e=collect($b->getColumns('supplementary_exam_period_events'))->keyBy('name');
        return self::varchar($p->get('status'),false,19)&&self::varchar($e->get('event_type'),false,19)&&self::varchar($e->get('from_status'),true,19)&&self::varchar($e->get('to_status'),false,19);
    }
    private static function registrations(Builder $b): bool
    {
        if(!$b->hasTable('supplementary_exam_registrations'))return false;
        $required=['supplementary_exam_registration_id','supplementary_exam_offering_id','student_id','student_course_registration_id','status','current_slot','eligibility_reason','registration_channel','registered_by_user_id','registered_at','cancelled_by_user_id','cancelled_at','cancellation_reason','eligibility_checked_at','created_at','updated_at'];
        $c=collect($b->getColumns('supplementary_exam_registrations'))->keyBy('name');if(collect($required)->contains(fn($x)=>!$c->has($x)))return false;
        if(!self::integer($c->get($required[0]),false,true))return false;foreach(['supplementary_exam_offering_id','student_id','student_course_registration_id','registered_by_user_id'] as $n)if(!self::integer($c->get($n)))return false;
        if(!self::integer($c->get('current_slot'),true)||!self::integer($c->get('cancelled_by_user_id'),true)||!self::varchar($c->get('status'),false,16)||!self::varchar($c->get('eligibility_reason'),false,40)||!self::varchar($c->get('registration_channel'),false,24))return false;
        foreach(['registered_at','eligibility_checked_at','created_at','updated_at'] as $n)if(!self::date($c->get($n)))return false;if(!self::date($c->get('cancelled_at'),true)||!self::text($c->get('cancellation_reason'),true))return false;
        $i=collect($b->getIndexes('supplementary_exam_registrations'));$has=fn($cols,$unique=false)=>$i->contains(fn($x)=>array_values($x['columns']??[])===$cols&&(!$unique||!empty($x['unique'])||!empty($x['primary'])));
        return $has([$required[0]],true)&&$has(['supplementary_exam_offering_id','student_course_registration_id'],true)&&$has(['supplementary_exam_offering_id','student_id','current_slot'],true)&&$has(['student_id','current_slot'])&&$has(['supplementary_exam_offering_id','status'])&&$has(['registered_by_user_id'])&&$has(['cancelled_by_user_id'])&&self::fks($b,'supplementary_exam_registrations',['supplementary_exam_offering_id'=>['supplementary_exam_offerings','supplementary_exam_offering_id'],'student_id'=>['students','student_id'],'student_course_registration_id'=>['student_course_registrations','student_course_registration_id'],'registered_by_user_id'=>['users','user_id'],'cancelled_by_user_id'=>['users','user_id']]);
    }
    private static function events(Builder $b): bool
    {
        if(!$b->hasTable('supplementary_exam_registration_events'))return false;
        $required=['supplementary_exam_registration_event_id','supplementary_exam_registration_id','event_type','from_status','to_status','actor_user_id','notes','created_at'];$c=collect($b->getColumns('supplementary_exam_registration_events'))->keyBy('name');if(collect($required)->contains(fn($x)=>!$c->has($x)))return false;if(!self::integer($c->get($required[0]),false,true)||!self::integer($c->get('supplementary_exam_registration_id'))||!self::integer($c->get('actor_user_id'))||!self::varchar($c->get('event_type'),false,24)||!self::varchar($c->get('from_status'),true,16)||!self::varchar($c->get('to_status'),false,16)||!self::text($c->get('notes'),true)||!self::date($c->get('created_at')))return false;$i=collect($b->getIndexes('supplementary_exam_registration_events'));$has=fn($cols,$primary=false)=>$i->contains(fn($x)=>array_values($x['columns']??[])===$cols&&(!$primary||!empty($x['primary'])));return $has([$required[0]],true)&&$has(['supplementary_exam_registration_id','created_at'])&&$has(['actor_user_id'])&&self::fks($b,'supplementary_exam_registration_events',['supplementary_exam_registration_id'=>['supplementary_exam_registrations','supplementary_exam_registration_id'],'actor_user_id'=>['users','user_id']]);
    }
    private static function permissions():bool
    {
        $codes=[self::VIEW,self::SELF,self::MANAGE,self::WINDOW];
        $permissions=DB::table('permissions as p')->join('system_modules as m','m.module_id','=','p.module_id')->whereIn('p.permission_code',$codes)->where('p.is_active',true)->where('m.module_code','exams')->where('m.is_active',true)->distinct()->count('p.permission_code');
        if($permissions!==4||DB::table('permissions')->whereIn('permission_code',$codes)->count()!==4)return false;
        $mappings=DB::table('role_permissions as rp')->join('roles as r','r.role_id','=','rp.role_id')->join('permissions as p','p.permission_id','=','rp.permission_id')->where('r.is_active',true)->where(function($q){$q->where(fn($x)=>$x->where('p.permission_code',self::SELF)->where('r.role_code','student'))->orWhere(fn($x)=>$x->where('p.permission_code',self::VIEW)->whereIn('r.role_code',['registration_officer','exam_officer','dean','vice_president_scientific']))->orWhere(fn($x)=>$x->whereIn('p.permission_code',[self::MANAGE,self::WINDOW])->where('r.role_code','registration_officer'));})->distinct()->count();
        $allMappings=DB::table('role_permissions as rp')->join('permissions as p','p.permission_id','=','rp.permission_id')->whereIn('p.permission_code',$codes)->count();
        return $mappings===7&&$allMappings===7;
    }
    private static function fks(Builder $b,string $table,array $required):bool{$found=[];foreach($b->getForeignKeys($table) as $f){$local=array_values($f['columns']??[]);if(count($local)===1)$found[$local[0]]=[(string)($f['foreign_table']??''),array_values($f['foreign_columns']??[])[0]??''];}foreach($required as $col=>$target)if(($found[$col]??null)!==$target)return false;return true;}
    private static function integer(?array $c,bool $nullable=false,bool $auto=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['int','integer','tinyint'],true)&&$nullable===!empty($c['nullable'])&&(!$auto||!empty($c['auto_increment']));}
    private static function varchar(?array $c,bool $nullable,int $length):bool{if($c===null||$nullable!==!empty($c['nullable']))return false;$name=strtolower((string)($c['type_name']??''));$type=strtolower((string)($c['type']??''));if(!in_array($name,['varchar','string'],true)&&!str_starts_with($type,'varchar'))return false;$actual=(int)($c['length']??0);if($actual===0&&preg_match('/varchar\((\d+)\)/',$type,$m))$actual=(int)$m[1];return$actual>=$length;}
    private static function text(?array $c,bool $nullable):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['text','mediumtext','longtext'],true)&&$nullable===!empty($c['nullable']);}
    private static function date(?array $c,bool $nullable=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['timestamp','datetime'],true)&&$nullable===!empty($c['nullable']);}
}
