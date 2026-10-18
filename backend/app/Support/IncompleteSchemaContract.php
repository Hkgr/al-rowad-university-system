<?php

namespace App\Support;

/** Server-owned schema manifest shared by runtime and the rendered manual SQL.
 * No request identifiers, DDL execution or persistent helper objects at runtime.
 * MariaDB's canonical CHECK text and our known trigger bodies are fail-closed.
 */
final class IncompleteSchemaContract
{
    public const CASES = 'student_course_incomplete_cases';
    public const EVENTS = 'student_course_incomplete_events';
    public const OWNER = 'course-result-symbols-phase1-v1';

    public static function columns(): array
    {
        $c = [
            'incomplete_case_id'=>['int',false,true], 'student_course_registration_id'=>['int'], 'student_course_result_id'=>['int'],
            'student_id'=>['int'], 'course_id'=>['int'], 'academic_year_id'=>['int'], 'semester_id'=>['int'],
            'reason'=>['text'], 'requirements'=>['text'], 'granted_at'=>['datetime'], 'granted_by_user_id'=>['int'],
            'deadline_at'=>['datetime'], 'deadline_source'=>['varchar(16)'], 'calendar_snapshot'=>['longtext',true],
            'expiry_outcome'=>['char(1)'], 'state'=>['varchar(16)'], 'revision'=>['int unsigned'],
            'outcome_snapshot'=>['longtext'], 'settled_at'=>['datetime',true], 'created_at'=>['datetime'], 'updated_at'=>['datetime'],
        ];
        $e = [
            'incomplete_event_id'=>['bigint',false,true], 'incomplete_case_id'=>['int'], 'revision'=>['int unsigned'],
            'action'=>['varchar(24)'], 'request_id'=>['varchar(100)'], 'request_hash'=>['char(64)'], 'actor_kind'=>['varchar(8)'],
            'actor_user_id'=>['int',true], 'before_values'=>['longtext',true], 'after_values'=>['longtext'],
            'response_snapshot'=>['longtext'], 'performed_at'=>['datetime'],
        ];
        return [self::CASES=>$c,self::EVENTS=>$e];
    }
    public static function indexes(): array
    {
        return [self::CASES=>[
            'PRIMARY'=>[0,'incomplete_case_id'], 'uq_crs_case_registration'=>[0,'student_course_registration_id'],
            'uq_crs_case_result'=>[0,'student_course_result_id'], 'idx_crs_expiration'=>[1,'state,deadline_at,incomplete_case_id'],
            'idx_crs_history'=>[1,'student_id,academic_year_id,semester_id'],
        ],self::EVENTS=>[
            'PRIMARY'=>[0,'incomplete_event_id'], 'uq_crs_event_revision'=>[0,'incomplete_case_id,revision'],
            'uq_crs_request'=>[0,'request_id'], 'idx_crs_event_time'=>[1,'performed_at,incomplete_event_id'],
        ]];
    }
    public static function foreignKeys(): array
    {
        return [self::CASES=>[
            'fk_crs_registration'=>['student_course_registration_id','student_course_registrations','student_course_registration_id'],
            'fk_crs_result'=>['student_course_result_id','student_course_results','student_course_result_id'],
            'fk_crs_student'=>['student_id','students','student_id'], 'fk_crs_course'=>['course_id','courses','course_id'],
            'fk_crs_year'=>['academic_year_id','academic_years','academic_year_id'], 'fk_crs_semester'=>['semester_id','semesters','semester_id'],
            'fk_crs_actor'=>['granted_by_user_id','users','user_id'],
        ],self::EVENTS=>[
            'fk_crs_event_case'=>['incomplete_case_id',self::CASES,'incomplete_case_id'], 'fk_crs_event_actor'=>['actor_user_id','users','user_id'],
        ]];
    }
    public static function checks(): array
    {
        // Canonical server serialization. Parentheses significant to precedence are retained.
        return [self::CASES=>[
            'ck_crs_state'=>"state in ('active','resolved','expired')", 'ck_crs_outcome'=>"expiry_outcome in ('F','Z')",
            'ck_crs_deadline_source'=>"deadline_source = 'manual' and calendar_snapshot is null or deadline_source = 'calendar' and json_valid(calendar_snapshot)",
            'ck_crs_snapshot'=>'json_valid(outcome_snapshot)', 'ck_crs_revision'=>'revision >= 1',
            'ck_crs_terminal'=>"state = 'active' and settled_at is null or state in ('resolved','expired') and settled_at is not null",
        ],self::EVENTS=>[
            'ck_crs_event_action'=>"action in ('grant','deadline_changed','resolved','expired')",
            'ck_crs_event_actor'=>"actor_kind = 'system' and actor_user_id is null and action = 'expired' or actor_kind = 'user' and actor_user_id is not null",
            'ck_crs_event_json'=>'json_valid(after_values) and json_valid(response_snapshot) and (before_values is null or json_valid(before_values))',
        ]];
    }
    public static function triggers(): array
    {
        $event="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_events_append_only'; END";
        $case="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable'; END";
        $update=<<<'SQL'
BEGIN
 IF OLD.state<>'active' OR NEW.revision<>OLD.revision+1 OR
  NOT(NEW.student_course_registration_id<=>OLD.student_course_registration_id) OR NOT(NEW.student_course_result_id<=>OLD.student_course_result_id) OR
  NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.academic_year_id<=>OLD.academic_year_id) OR NOT(NEW.semester_id<=>OLD.semester_id) OR
  NOT(NEW.granted_at<=>OLD.granted_at) OR NOT(NEW.granted_by_user_id<=>OLD.granted_by_user_id) OR NOT(NEW.reason<=>OLD.reason) OR NOT(NEW.requirements<=>OLD.requirements) OR
  NOT(NEW.expiry_outcome<=>OLD.expiry_outcome) OR NOT(NEW.created_at<=>OLD.created_at) OR
  (NEW.state='active' AND NOT(NEW.outcome_snapshot<=>OLD.outcome_snapshot)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable';
 END IF;
END
SQL;
        return ['crs_events_immutable_u'=>[self::EVENTS,'UPDATE',$event], 'crs_events_immutable_d'=>[self::EVENTS,'DELETE',$event],
            'crs_case_history_u'=>[self::CASES,'UPDATE',$update], 'crs_case_history_d'=>[self::CASES,'DELETE',$case]];
    }
    public static function literal(string $value): string { return "'".str_replace("'","''",$value)."'"; }
    private static function rows(array $rows): string
    {
        return implode("\n UNION ALL ",array_map(fn($row)=>'SELECT '.implode(', ',array_map(fn($value,$key)=>self::literal((string)$value).' AS '.$key,$row,array_keys($row))),$rows));
    }
    private static function compact(string $text): string { return preg_replace('/\s+/',' ',trim(str_replace('`','',$text))); }

    /** Single read-only metadata query: missing objects allowed only during installation. */
    public static function issuesSql(bool $complete, bool $nullableMarks = true): string
    {
        $columns=[];$indexes=[];$fks=[];$checks=[];$triggers=[];
        foreach(self::columns() as $table=>$items)foreach($items as $name=>$spec){
            $text=preg_match('/^(?:var)?char|text/',$spec[0]);
            $columns[]=['tbl'=>$table,'col'=>$name,'typ'=>$spec[0],'nullable'=>($spec[1]??false)?'YES':'NO',
                'extra'=>($spec[2]??false)?'auto_increment':'','charset_name'=>$text?'utf8mb4':'','collation_name'=>$text?'utf8mb4_unicode_ci':''];
        }
        foreach(self::indexes() as $table=>$items)foreach($items as $name=>$spec)$indexes[]=['tbl'=>$table,'idx'=>$name,'non_unique'=>$spec[0],'cols'=>$spec[1]];
        foreach(self::foreignKeys() as $table=>$items)foreach($items as $name=>$spec)$fks[]=['tbl'=>$table,'fk'=>$name,'cols'=>$spec[0],'target'=>$spec[1],'target_cols'=>$spec[2]];
        foreach(self::checks() as $table=>$items)foreach($items as $name=>$body)$checks[]=['tbl'=>$table,'chk'=>$name,'body'=>$body];
        foreach(self::triggers() as $name=>$spec)$triggers[]=['tbl'=>$spec[0],'trg'=>$name,'op'=>$spec[1],'body'=>self::compact($spec[2])];
        $c=self::rows($columns);$i=self::rows($indexes);$f=self::rows($fks);$k=self::rows($checks);$t=self::rows($triggers);
        $owned=self::literal(self::CASES).','.self::literal(self::EVENTS); $owner=self::literal(self::OWNER);
        $missing=$complete?'1=1':'a.TABLE_NAME IS NOT NULL';
        $missingTrigger=$complete?'1=1':'a.TRIGGER_NAME IS NOT NULL';
        $markNullable=$nullableMarks?"a.IS_NULLABLE='YES' AND (a.COLUMN_DEFAULT IS NULL OR a.COLUMN_DEFAULT='NULL')":"((a.IS_NULLABLE='YES' AND (a.COLUMN_DEFAULT IS NULL OR a.COLUMN_DEFAULT='NULL')) OR (a.IS_NULLABLE='NO' AND a.COLUMN_DEFAULT IN ('0','0.00')))";
        return <<<SQL
WITH expected_columns AS ($c), expected_indexes AS ($i), expected_fks AS ($f), expected_checks AS ($k), expected_triggers AS ($t),
owned_tables AS (SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_columns AS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_indexes AS (SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE,
 GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols,MAX(COALESCE(SUB_PART,0)) AS prefix_length
 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned) GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE),
actual_fks AS (SELECT r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION,
 GROUP_CONCAT(u.COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS cols,
 MIN(u.REFERENCED_TABLE_SCHEMA) AS target_schema, MIN(u.REFERENCED_TABLE_NAME) AS target,
 GROUP_CONCAT(u.REFERENCED_COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS target_cols
 FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE u ON u.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA AND u.TABLE_NAME=r.TABLE_NAME AND u.CONSTRAINT_NAME=r.CONSTRAINT_NAME
 WHERE r.CONSTRAINT_SCHEMA=DATABASE() AND r.TABLE_NAME IN ($owned) GROUP BY r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION),
actual_checks AS (SELECT * FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ($owned)),
actual_triggers AS (SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),
issues AS (
 SELECT e.tbl AS object_name,'table_definition' AS problem FROM (SELECT DISTINCT tbl FROM expected_columns) e LEFT JOIN owned_tables a ON a.TABLE_NAME=e.tbl
 WHERE ($missing) AND (a.TABLE_NAME IS NULL OR a.ENGINE<>'InnoDB' OR a.TABLE_TYPE<>'BASE TABLE' OR BINARY a.TABLE_COMMENT<>BINARY $owner OR a.TABLE_COLLATION<>'utf8mb4_unicode_ci')
 UNION ALL SELECT CONCAT(e.tbl,'.',e.col),'column_definition' FROM expected_columns e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_columns a ON a.TABLE_NAME=e.tbl AND a.COLUMN_NAME=e.col
 WHERE a.COLUMN_NAME IS NULL OR BINARY (CASE WHEN a.DATA_TYPE IN ('int','bigint') THEN REGEXP_REPLACE(a.COLUMN_TYPE,'[(][0-9]+[)]','') ELSE a.COLUMN_TYPE END)<>BINARY e.typ
 OR a.IS_NULLABLE<>e.nullable OR BINARY a.EXTRA<>BINARY e.extra OR COALESCE(a.CHARACTER_SET_NAME,'')<>e.charset_name OR COALESCE(a.COLLATION_NAME,'')<>e.collation_name
 OR (a.COLUMN_DEFAULT IS NOT NULL AND a.COLUMN_DEFAULT<>'NULL') OR COALESCE(a.GENERATION_EXPRESSION,'')<>''
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.COLUMN_NAME),'unexpected_column' FROM actual_columns a LEFT JOIN expected_columns e ON e.tbl=a.TABLE_NAME AND e.col=a.COLUMN_NAME WHERE e.col IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.idx),'index_definition' FROM expected_indexes e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_indexes a ON a.TABLE_NAME=e.tbl AND a.INDEX_NAME=e.idx
 WHERE a.INDEX_NAME IS NULL OR a.cols IS NULL OR a.NON_UNIQUE<>e.non_unique OR BINARY a.cols<>BINARY e.cols OR a.INDEX_TYPE<>'BTREE' OR a.prefix_length<>0
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.INDEX_NAME),'unexpected_unique_index' FROM actual_indexes a LEFT JOIN expected_indexes e ON e.tbl=a.TABLE_NAME AND e.idx=a.INDEX_NAME WHERE a.NON_UNIQUE=0 AND e.idx IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.fk),'foreign_key_definition' FROM expected_fks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_fks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.fk
 WHERE a.CONSTRAINT_NAME IS NULL OR a.target_schema IS NULL OR a.target_cols IS NULL OR BINARY a.cols<>BINARY e.cols OR BINARY a.target_schema<>BINARY DATABASE() OR BINARY a.target<>BINARY e.target OR BINARY a.target_cols<>BINARY e.target_cols
 OR a.DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.UPDATE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.MATCH_OPTION<>'NONE'
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_foreign_key' FROM actual_fks a LEFT JOIN expected_fks e ON e.tbl=a.TABLE_NAME AND e.fk=a.CONSTRAINT_NAME WHERE e.fk IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.chk),'check_definition' FROM expected_checks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_checks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.chk
 WHERE a.CONSTRAINT_NAME IS NULL OR a.CHECK_CLAUSE IS NULL OR BINARY REGEXP_REPLACE(TRIM(REPLACE(a.CHECK_CLAUSE,CHAR(96),'')),'[[:space:]]+',' ')<>BINARY e.body
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_check' FROM actual_checks a LEFT JOIN expected_checks e ON e.tbl=a.TABLE_NAME AND e.chk=a.CONSTRAINT_NAME WHERE e.chk IS NULL
 UNION ALL SELECT e.trg,'trigger_definition' FROM expected_triggers e LEFT JOIN actual_triggers a ON a.TRIGGER_NAME=e.trg
 WHERE ($missingTrigger) AND (a.TRIGGER_NAME IS NULL OR BINARY a.EVENT_OBJECT_TABLE<>BINARY e.tbl OR a.EVENT_MANIPULATION<>e.op OR a.ACTION_TIMING<>'BEFORE' OR a.ACTION_ORIENTATION<>'ROW'
 OR a.ACTION_STATEMENT IS NULL OR BINARY REGEXP_REPLACE(TRIM(a.ACTION_STATEMENT),'[[:space:]]+',' ')<>BINARY e.body)
 UNION ALL SELECT a.TRIGGER_NAME,'unexpected_trigger' FROM actual_triggers a LEFT JOIN expected_triggers e ON e.trg=a.TRIGGER_NAME WHERE a.EVENT_OBJECT_TABLE IN ($owned) AND e.trg IS NULL
 UNION ALL SELECT e.col,'result_mark_definition' FROM (SELECT 'theoretical_total' AS col UNION ALL SELECT 'practical_total' UNION ALL SELECT 'final_mark') e
 LEFT JOIN information_schema.COLUMNS a ON a.TABLE_SCHEMA=DATABASE() AND a.TABLE_NAME='student_course_results' AND a.COLUMN_NAME=e.col
 WHERE a.COLUMN_NAME IS NULL OR a.COLUMN_TYPE<>'decimal(5,2)' OR NOT ($markNullable) OR a.EXTRA<>'' OR COALESCE(a.GENERATION_EXPRESSION,'')<>''
 UNION ALL SELECT 'student_course_results','result_registration_unique' WHERE NOT EXISTS (
 SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND NON_UNIQUE=0 AND INDEX_TYPE='BTREE'
 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MIN(COLUMN_NAME)='student_course_registration_id' AND MAX(COALESCE(SUB_PART,0))=0)
)
SELECT object_name,problem FROM issues
SQL;
    }
}
