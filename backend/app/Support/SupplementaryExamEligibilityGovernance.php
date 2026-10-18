<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamEligibilityGovernance
{
    public const PERMISSION_VIEW = 'supplementary_exams.eligibility.view';
    public const PERMISSION_SELF = 'supplementary_exams.deferrals.self';

    public static function schemaReady(): bool
    {
        try {
            if (! SupplementaryExamOfferingGovernance::schemaReady()) return false;
            $builder = Schema::connection((string) config('database.default'));
            if (! $builder instanceof Builder || ! method_exists($builder, 'getColumns') || ! method_exists($builder, 'getIndexes') || ! method_exists($builder, 'getForeignKeys')) return false;
            return self::deferralsReady($builder) && self::eventsReady($builder) && self::permissionsReady();
        } catch (Throwable) { return false; }
    }

    private static function deferralsReady(Builder $builder): bool
    {
        if (! $builder->hasTable('supplementary_exam_theoretical_deferrals')) return false;
        $c=collect($builder->getColumns('supplementary_exam_theoretical_deferrals'))->keyBy('name');
        $required=['supplementary_exam_theoretical_deferral_id','supplementary_exam_offering_id','student_course_registration_id','status','current_slot','declared_by_user_id','declared_at','cancelled_by_user_id','cancelled_at','cancellation_reason','superseded_at','supersede_reason','created_at','updated_at'];
        if (collect($required)->contains(fn($name)=>!$c->has($name))) return false;
        if (!self::integer($c->get($required[0])) || empty($c->get($required[0])['auto_increment'])) return false;
        foreach(['supplementary_exam_offering_id','student_course_registration_id','declared_by_user_id'] as $name) if(!self::integer($c->get($name))) return false;
        if(!self::integer($c->get('current_slot'),true)||!self::integer($c->get('cancelled_by_user_id'),true)||!self::string($c->get('status')))return false;
        foreach(['declared_at','created_at','updated_at'] as $name)if(!self::date($c->get($name)))return false;
        foreach(['cancelled_at','superseded_at'] as $name)if(!self::date($c->get($name),true))return false;
        foreach(['cancellation_reason','supersede_reason'] as $name)if(!self::text($c->get($name),true))return false;
        $indexes=collect($builder->getIndexes('supplementary_exam_theoretical_deferrals'));
        $has=fn(array $cols,bool $unique=false,bool $primary=false)=>$indexes->contains(fn($i)=>array_values($i['columns']??[])===$cols&&(!$unique||!empty($i['unique']))&&(!$primary||!empty($i['primary'])));
        return $has([$required[0]],false,true)&&$has(['supplementary_exam_offering_id','student_course_registration_id'],true)&&$has(['student_course_registration_id','current_slot'],true)
          &&$has(['declared_by_user_id'])&&$has(['cancelled_by_user_id'])&&self::fks($builder,'supplementary_exam_theoretical_deferrals',[
          'supplementary_exam_offering_id'=>['supplementary_exam_offerings','supplementary_exam_offering_id'],'student_course_registration_id'=>['student_course_registrations','student_course_registration_id'],'declared_by_user_id'=>['users','user_id'],'cancelled_by_user_id'=>['users','user_id']]);
    }

    private static function eventsReady(Builder $builder): bool
    {
        if(!$builder->hasTable('supplementary_exam_theoretical_deferral_events'))return false;
        $c=collect($builder->getColumns('supplementary_exam_theoretical_deferral_events'))->keyBy('name');$required=['supplementary_exam_theoretical_deferral_event_id','supplementary_exam_theoretical_deferral_id','event_type','from_status','to_status','actor_user_id','notes','created_at'];
        if(collect($required)->contains(fn($n)=>!$c->has($n)))return false;if(!self::integer($c->get($required[0]))||empty($c->get($required[0])['auto_increment']))return false;
        if(!self::integer($c->get($required[1]))||!self::integer($c->get('actor_user_id'))||!self::string($c->get('event_type'))||!self::string($c->get('from_status'),true)||!self::string($c->get('to_status'))||!self::text($c->get('notes'),true)||!self::date($c->get('created_at')))return false;
        $i=collect($builder->getIndexes('supplementary_exam_theoretical_deferral_events'));$has=fn($cols,$primary=false)=>$i->contains(fn($x)=>array_values($x['columns']??[])===$cols&&(!$primary||!empty($x['primary'])));
        return $has([$required[0]],true)&&$has(['supplementary_exam_theoretical_deferral_id','created_at'])&&$has(['actor_user_id'])&&self::fks($builder,'supplementary_exam_theoretical_deferral_events',['supplementary_exam_theoretical_deferral_id'=>['supplementary_exam_theoretical_deferrals','supplementary_exam_theoretical_deferral_id'],'actor_user_id'=>['users','user_id']]);
    }
    private static function permissionsReady():bool{return DB::table('permissions')->where('permission_code',self::PERMISSION_VIEW)->where('is_active',true)->exists()&&DB::table('permissions')->where('permission_code',self::PERMISSION_SELF)->where('is_active',true)->exists();}
    private static function fks(Builder $b,string $table,array $required):bool{$found=[];foreach($b->getForeignKeys($table) as $f){$local=array_values($f['columns']??[]);if(count($local)===1)$found[$local[0]]=[(string)($f['foreign_table']??''),array_values($f['foreign_columns']??[])[0]??''];}foreach($required as $col=>$target)if(($found[$col]??null)!==$target)return false;return true;}
    private static function integer(?array $c,bool $nullable=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['int','integer','bigint','mediumint','tinyint'],true)&&$nullable===!empty($c['nullable']);}
    private static function string(?array $c,bool $nullable=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['varchar','char','string'],true)&&$nullable===!empty($c['nullable']);}
    private static function text(?array $c,bool $nullable=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['text','tinytext','mediumtext','longtext','varchar','string'],true)&&$nullable===!empty($c['nullable']);}
    private static function date(?array $c,bool $nullable=false):bool{return$c!==null&&in_array(strtolower((string)($c['type_name']??$c['type']??'')),['timestamp','datetime'],true)&&$nullable===!empty($c['nullable']);}
}
