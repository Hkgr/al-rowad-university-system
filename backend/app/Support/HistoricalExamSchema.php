<?php

namespace App\Support;

use App\Exceptions\GradeException;
use Illuminate\Support\Facades\{DB, Schema};

/** One manual package: indexed private work, applicable credits and append-only adopted outcomes. */
final class HistoricalExamSchema
{
    public const OWNER = 'historical-exam-approved-reconciliation-v1';
    public const WORK = 'historical_exam_work_items';
    public const CREDITS = 'registration_credit_evidence';
    public const PLAN = 'plan_course_credit_evidence';
    public const OUTCOMES = 'historical_exam_outcomes';

    public static function columns(): array
    {
        return [self::WORK => [
            'work_item_id'=>['char(36)'], 'operation_id'=>['char(36)'], 'action_id'=>['char(64)'], 'actor_user_id'=>['int'],
            'student_id'=>['int'], 'course_id'=>['int'], 'academic_program_id'=>['int'], 'academic_year_id'=>['int'], 'semester_id'=>['int'],
            'course_offering_id'=>['int',true], 'student_course_registration_id'=>['int',true], 'kind'=>['varchar(40)'],
            'source_reference'=>['varchar(160)'], 'baseline_hash'=>['char(64)'], 'proposal'=>['longtext'], 'created_at'=>['datetime'],
        ], self::CREDITS => [
            'credit_evidence_id'=>['bigint',false,true], 'student_course_registration_id'=>['int'], 'revision'=>['int unsigned'],
            'credit_hours'=>['int unsigned'], 'basis'=>['varchar(48)'], 'work_item_id'=>['char(36)',true], 'operation_id'=>['char(36)'],
            'action_id'=>['char(64)'], 'source_hash'=>['char(64)'], 'actor_user_id'=>['int'], 'created_at'=>['datetime'],
        ], self::PLAN => [
            'credit_evidence_id'=>['bigint',false,true], 'program_course_id'=>['int'], 'revision'=>['int unsigned'], 'credit_hours'=>['int unsigned'],
            'academic_plan_event_id'=>['bigint'], 'created_at'=>['datetime'],
        ], self::OUTCOMES => [
            'historical_outcome_id'=>['bigint',false,true], 'student_course_registration_id'=>['int'], 'work_item_id'=>['char(36)'],
            'revision'=>['int unsigned'], 'request_id'=>['char(36)'], 'request_hash'=>['char(64)'], 'actor_user_id'=>['int'],
            'reason'=>['text'], 'before_values'=>['longtext'], 'after_values'=>['longtext'], 'created_at'=>['datetime'],
        ]];
    }

    public static function indexes(): array
    {
        return [self::WORK => ['PRIMARY'=>[0,'work_item_id'], 'uq_hx_work_unit'=>[0,'action_id,baseline_hash'],
            'uq_hx_work_operation'=>[0,'operation_id,action_id'], 'idx_hx_work_list'=>[1,'kind,academic_year_id,semester_id,student_id,work_item_id'],
            'idx_hx_work_registration'=>[1,'student_course_registration_id,work_item_id']],
            self::CREDITS => ['PRIMARY'=>[0,'credit_evidence_id'], 'uq_hx_credit_revision'=>[0,'student_course_registration_id,revision'],
                'uq_hx_credit_operation'=>[0,'operation_id,action_id,student_course_registration_id']],
            self::PLAN => ['PRIMARY'=>[0,'credit_evidence_id'], 'uq_hx_plan_credit_revision'=>[0,'program_course_id,revision']],
            self::OUTCOMES => ['PRIMARY'=>[0,'historical_outcome_id'], 'uq_hx_outcome_revision'=>[0,'student_course_registration_id,revision'],
                'uq_hx_outcome_work'=>[0,'work_item_id'], 'uq_hx_outcome_request'=>[0,'request_id']]];
    }

    public static function foreignKeys(): array
    {
        return [self::WORK => [
            'fk_hx_work_actor'=>['actor_user_id','users','user_id'], 'fk_hx_work_student'=>['student_id','students','student_id'],
            'fk_hx_work_course'=>['course_id','courses','course_id'], 'fk_hx_work_program'=>['academic_program_id','academic_programs','academic_program_id'],
            'fk_hx_work_year'=>['academic_year_id','academic_years','academic_year_id'], 'fk_hx_work_semester'=>['semester_id','semesters','semester_id'],
            'fk_hx_work_offering'=>['course_offering_id','course_offerings','course_offering_id'],
            'fk_hx_work_registration'=>['student_course_registration_id','student_course_registrations','student_course_registration_id'],
        ], self::CREDITS => [
            'fk_hx_credit_registration'=>['student_course_registration_id','student_course_registrations','student_course_registration_id'],
            'fk_hx_credit_work'=>['work_item_id',self::WORK,'work_item_id'], 'fk_hx_credit_actor'=>['actor_user_id','users','user_id'],
        ], self::PLAN => [
            'fk_hx_plan_credit_member'=>['program_course_id','program_courses','program_course_id'],
            'fk_hx_plan_credit_event'=>['academic_plan_event_id','academic_plan_events','academic_plan_event_id'],
        ], self::OUTCOMES => [
            'fk_hx_outcome_registration'=>['student_course_registration_id','student_course_registrations','student_course_registration_id'],
            'fk_hx_outcome_work'=>['work_item_id',self::WORK,'work_item_id'], 'fk_hx_outcome_actor'=>['actor_user_id','users','user_id'],
        ]];
    }

    public static function checks(): array
    {
        return [self::WORK => ['ck_hx_work_json'=>'json_valid(proposal)',
            'ck_hx_work_kind'=>"kind in ('correction_review','credit_context_review','ungraded_followup','final_absence_review','aggregate_incomplete_followup')"],
            self::CREDITS => ['ck_hx_credit_hours'=>'credit_hours >= 1 and credit_hours <= 30', 'ck_hx_credit_revision'=>'revision >= 1'],
            self::PLAN => ['ck_hx_plan_credit_hours'=>'credit_hours >= 1 and credit_hours <= 30', 'ck_hx_plan_credit_revision'=>'revision >= 1'],
            self::OUTCOMES => ['ck_hx_outcome_json'=>'json_valid(before_values) and json_valid(after_values)', 'ck_hx_outcome_revision'=>'revision >= 1']];
    }

    public static function triggers(): array
    {
        $triggers = [];
        foreach (array_keys(self::columns()) as $i => $table) {
            foreach (['UPDATE'=>'u','DELETE'=>'d'] as $operation => $suffix) $triggers['hx_history_'.$i.'_'.$suffix] = [$table,$operation,"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='historical_exam_evidence_append_only'; END"];
            $guard = $table === self::PLAN ? " IF NOT EXISTS(SELECT 1 FROM program_courses pc JOIN academic_plan_versions v ON v.academic_plan_version_id=pc.academic_plan_version_id WHERE pc.program_course_id=NEW.program_course_id AND v.status='draft' AND v.fixed_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='historical_exam_fixed_plan_credit_locked'; END IF;" : '';
            $triggers['hx_epoch_'.$i.'_i'] = [$table,'INSERT',"BEGIN IF NOT EXISTS(SELECT 1 FROM academic_catalog_control WHERE control_id=1 AND schema_version=1 AND is_ready=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_schema_not_ready'; END IF; UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1;".$guard.' END'];
        }
        return $triggers;
    }

    public static function installed(): bool
    {
        foreach (array_keys(self::columns()) as $table) if (Schema::hasTable($table)) return true;
        return false;
    }

    public static function ready(): bool
    {
        foreach (self::columns() as $table => $columns) if (! Schema::hasColumns($table, array_keys($columns))) return false;
        return DB::getDriverName() !== 'mysql' || DB::select(self::issuesSql(true).' LIMIT 1') === [];
    }

    public static function requireReady(): void
    {
        if (! self::ready()) throw new GradeException('The approved historical-examination evidence package is not ready.', status:503, errorCode:'historical_exam_schema_not_ready');
    }

    public static function literal(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
    private static function rows(array $rows): string
    {
        return implode("\n UNION ALL ", array_map(fn($row) => 'SELECT '.implode(', ', array_map(fn($v,$k) => self::literal((string)$v).' AS '.$k, $row, array_keys($row))), $rows));
    }

    /** Read-only exact metadata contract, including every existing owned object before DDL. */
    public static function issuesSql(bool $complete): string
    {
        $columns=[]; $indexes=[]; $foreign=[]; $checks=[]; $triggers=[];
        foreach(self::columns() as $table=>$items)foreach($items as $name=>$spec) {
            $text=(bool)preg_match('/^(?:var)?char|text/',$spec[0]);
            $columns[]=['tbl'=>$table,'col'=>$name,'typ'=>$spec[0],'nullable'=>($spec[1]??false)?'YES':'NO','extra'=>($spec[2]??false)?'auto_increment':'','charset_name'=>$text?'utf8mb4':'','collation_name'=>$text?'utf8mb4_unicode_ci':''];
        }
        foreach(self::indexes() as $table=>$items)foreach($items as $name=>$spec)$indexes[]=['tbl'=>$table,'idx'=>$name,'non_unique'=>$spec[0],'cols'=>$spec[1]];
        foreach(self::foreignKeys() as $table=>$items)foreach($items as $name=>$spec)$foreign[]=['tbl'=>$table,'fk'=>$name,'cols'=>$spec[0],'target'=>$spec[1],'target_cols'=>$spec[2]];
        foreach(self::checks() as $table=>$items)foreach($items as $name=>$body)$checks[]=['tbl'=>$table,'chk'=>$name,'body'=>$body];
        foreach(self::triggers() as $name=>$spec)$triggers[]=['tbl'=>$spec[0],'trg'=>$name,'op'=>$spec[1],'body'=>preg_replace('/\s+/',' ',trim($spec[2]))];
        $c=self::rows($columns);$i=self::rows($indexes);$f=self::rows($foreign);$k=self::rows($checks);$t=self::rows($triggers);
        $owned=implode(',',array_map(self::literal(...),array_keys(self::columns())));$owner=self::literal(self::OWNER);
        $missing=$complete?'1=1':'a.TABLE_NAME IS NOT NULL';$missingTrigger=$complete?'1=1':'a.TRIGGER_NAME IS NOT NULL';
        return <<<SQL
WITH expected_columns AS ($c), expected_indexes AS ($i), expected_fks AS ($f), expected_checks AS ($k), expected_triggers AS ($t),
owned_tables AS (SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_columns AS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_indexes AS (SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols,MAX(COALESCE(SUB_PART,0)) AS prefix_length FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned) GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE),
actual_fks AS (SELECT r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION,GROUP_CONCAT(u.COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS cols,MIN(u.REFERENCED_TABLE_SCHEMA) AS target_schema,MIN(u.REFERENCED_TABLE_NAME) AS target,GROUP_CONCAT(u.REFERENCED_COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS target_cols FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE u ON u.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA AND u.TABLE_NAME=r.TABLE_NAME AND u.CONSTRAINT_NAME=r.CONSTRAINT_NAME WHERE r.CONSTRAINT_SCHEMA=DATABASE() AND r.TABLE_NAME IN ($owned) GROUP BY r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION),
actual_checks AS (SELECT * FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_triggers AS (SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),
issues AS (
 SELECT e.tbl AS object_name,'table_definition' AS problem FROM (SELECT DISTINCT tbl FROM expected_columns) e LEFT JOIN owned_tables a ON a.TABLE_NAME=e.tbl WHERE ($missing) AND (a.TABLE_NAME IS NULL OR a.ENGINE<>'InnoDB' OR a.TABLE_TYPE<>'BASE TABLE' OR BINARY a.TABLE_COMMENT<>BINARY $owner OR a.TABLE_COLLATION<>'utf8mb4_unicode_ci')
 UNION ALL SELECT CONCAT(e.tbl,'.',e.col),'column_definition' FROM expected_columns e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_columns a ON a.TABLE_NAME=e.tbl AND a.COLUMN_NAME=e.col WHERE a.COLUMN_NAME IS NULL OR BINARY (CASE WHEN a.DATA_TYPE IN ('int','bigint') THEN REGEXP_REPLACE(a.COLUMN_TYPE,'[(][0-9]+[)]','') ELSE a.COLUMN_TYPE END)<>BINARY e.typ OR a.IS_NULLABLE<>e.nullable OR BINARY a.EXTRA<>BINARY e.extra OR COALESCE(a.CHARACTER_SET_NAME,'')<>e.charset_name OR COALESCE(a.COLLATION_NAME,'')<>e.collation_name OR (a.COLUMN_DEFAULT IS NOT NULL AND a.COLUMN_DEFAULT<>'NULL') OR COALESCE(a.GENERATION_EXPRESSION,'')<>''
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.COLUMN_NAME),'unexpected_column' FROM actual_columns a LEFT JOIN expected_columns e ON e.tbl=a.TABLE_NAME AND e.col=a.COLUMN_NAME WHERE e.col IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.idx),'index_definition' FROM expected_indexes e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_indexes a ON a.TABLE_NAME=e.tbl AND a.INDEX_NAME=e.idx WHERE a.INDEX_NAME IS NULL OR a.cols IS NULL OR a.NON_UNIQUE<>e.non_unique OR BINARY a.cols<>BINARY e.cols OR a.INDEX_TYPE<>'BTREE' OR a.prefix_length<>0
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.INDEX_NAME),'unexpected_unique_index' FROM actual_indexes a LEFT JOIN expected_indexes e ON e.tbl=a.TABLE_NAME AND e.idx=a.INDEX_NAME WHERE a.NON_UNIQUE=0 AND e.idx IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.fk),'foreign_key_definition' FROM expected_fks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_fks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.fk WHERE a.CONSTRAINT_NAME IS NULL OR a.target_schema IS NULL OR a.target_cols IS NULL OR BINARY a.cols<>BINARY e.cols OR BINARY a.target_schema<>BINARY DATABASE() OR BINARY a.target<>BINARY e.target OR BINARY a.target_cols<>BINARY e.target_cols OR a.DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.UPDATE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.MATCH_OPTION<>'NONE'
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_foreign_key' FROM actual_fks a LEFT JOIN expected_fks e ON e.tbl=a.TABLE_NAME AND e.fk=a.CONSTRAINT_NAME WHERE e.fk IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.chk),'check_definition' FROM expected_checks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_checks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.chk WHERE a.CONSTRAINT_NAME IS NULL OR a.CHECK_CLAUSE IS NULL OR BINARY REGEXP_REPLACE(TRIM(REPLACE(a.CHECK_CLAUSE,CHAR(96),'')),'[[:space:]]+',' ')<>BINARY e.body
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_check' FROM actual_checks a LEFT JOIN expected_checks e ON e.tbl=a.TABLE_NAME AND e.chk=a.CONSTRAINT_NAME WHERE e.chk IS NULL
 UNION ALL SELECT e.trg,'trigger_definition' FROM expected_triggers e LEFT JOIN actual_triggers a ON a.TRIGGER_NAME=e.trg WHERE ($missingTrigger) AND (a.TRIGGER_NAME IS NULL OR BINARY a.EVENT_OBJECT_TABLE<>BINARY e.tbl OR a.EVENT_MANIPULATION<>e.op OR a.ACTION_TIMING<>'BEFORE' OR a.ACTION_ORIENTATION<>'ROW' OR a.ACTION_STATEMENT IS NULL OR BINARY REGEXP_REPLACE(TRIM(a.ACTION_STATEMENT),'[[:space:]]+',' ')<>BINARY e.body)
 UNION ALL SELECT a.TRIGGER_NAME,'unexpected_trigger' FROM actual_triggers a LEFT JOIN expected_triggers e ON e.trg=a.TRIGGER_NAME WHERE a.EVENT_OBJECT_TABLE IN ($owned) AND e.trg IS NULL
)
SELECT object_name,problem FROM issues
SQL;
    }
}
