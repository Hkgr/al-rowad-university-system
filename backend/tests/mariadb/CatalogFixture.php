<?php

/** Minimal synthetic fixture for the package's REQUIRED contract, not a production clone. */
final class CatalogFixture
{
    public static function create(CatalogEnvironment $env, PDO $p): void
    {
        $env->guard($p);
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sql/scientific-course-management/00_preflight.sql');
        preg_match_all("/SELECT '([a-z_]+)' table_name,'([a-z_]+)' column_name/", $sql, $matches, PREG_SET_ORDER);
        $tables=[];
        foreach ($matches as $m) $tables[$m[1]][$m[2]]=true;
        $extra = ['users'=>['username','student_id','employee_id'], 'user_roles'=>['user_role_id'], 'user_access_scopes'=>['user_access_scope_id'], 'course_offerings'=>['course_id','academic_year_id','semester_id','department_id','faculty_member_id','status','section','capacity','available_seats'], 'academic_years'=>['academic_year_id','year_name','starts_at','ends_at'], 'supplementary_exam_offerings'=>['course_id'], 'students'=>['deleted_at']];
        $primary = ['user_roles'=>'user_role_id','user_access_scopes'=>'user_access_scope_id'];
        $extra['course_instructors'] = ['course_instructor_id', 'faculty_member_id', 'is_primary'];
        $extra['faculty_members'] = ['faculty_member_id', 'employee_id'];
        $extra['employees'] = ['employee_id', 'first_name', 'last_name'];
        $primary += ['faculty_members' => 'faculty_member_id', 'employees' => 'employee_id'];
        foreach ($extra as $table=>$fields) foreach ($fields as $field) $tables[$table][$field]=true;
        foreach ($tables as $table=>$fields) {
            $key = $primary[$table] ?? array_key_first($fields); $columns=[];
            foreach (array_keys($fields) as $field) {
                $type = match (true) {
                    $field===$key => ($table === 'user_activity_logs' ? 'BIGINT' : 'INT').' NOT NULL AUTO_INCREMENT PRIMARY KEY',
                    $field==='is_active' => 'TINYINT NOT NULL DEFAULT 1',
                    str_ends_with($field, '_at') => 'DATETIME NULL',
                    str_ends_with($field, '_id'), str_ends_with($field, '_hours'), $field==='duration_years', $field==='is_primary' => 'INT NULL',
                    $field==='description' => 'TEXT NULL',
                    default => 'VARCHAR(191) NULL',
                };
                $columns[]="`$field` $type";
            }
            foreach (['is_active'=>'TINYINT NOT NULL DEFAULT 1', 'created_at'=>'DATETIME NULL', 'updated_at'=>'DATETIME NULL'] as $field=>$type) if (!isset($fields[$field])) $columns[]="`$field` $type";
            $p->exec("CREATE TABLE `$table` (".implode(',', $columns).') ENGINE=InnoDB');
        }
        $uniques=['courses'=>[['course_code']], 'program_courses'=>[['academic_program_id','course_id']], 'academic_requirement_groups'=>[['group_code'],['academic_program_id','requirement_scope','requirement_type']], 'program_course_requirement_groups'=>[['program_course_id']], 'course_departments'=>[['course_id','department_id']], 'course_prerequisites'=>[['course_id','prerequisite_course_id']]];
        foreach ($uniques as $table=>$indexes) foreach ($indexes as $fields) $p->exec("ALTER TABLE $table ADD UNIQUE (".implode(',', $fields).')');
        preg_match_all("/k.table_name='([a-z_]+)' AND k.column_name='([a-z_]+)' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='([a-z_]+)' AND k.referenced_column_name='([a-z_]+)'/", $sql, $fks, PREG_SET_ORDER);
        $seen=[];
        foreach ($fks as [, $table,$column,$target,$key]) {
            if (isset($seen[$table.'.'.$column])) continue;
            $seen[$table.'.'.$column]=true;
            $p->exec("ALTER TABLE $table ADD FOREIGN KEY ($column) REFERENCES $target ($key) ON DELETE RESTRICT ON UPDATE RESTRICT");
        }
        self::seed($p);
    }
    public static function seed(PDO $p): void
    {
        $p->exec("INSERT INTO system_modules(module_id,module_code) VALUES(1,'courses')");
        $p->exec("INSERT INTO account_statuses(account_status_id,status_code) VALUES(1,'active')");
        $p->exec("INSERT INTO users(user_id,username,account_status_id) VALUES(1,'scientific',1)");
        $p->exec("INSERT INTO roles(role_id,role_code) VALUES(1,'vice_president_scientific')");
        $p->exec('INSERT INTO user_roles(user_id,role_id) VALUES(1,1)');
        foreach (['vice_presidency.scientific.access','courses.manage','academic_structure.manage','vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage'] as $i=>$code) {
            $id=$i+1;
            $p->exec("INSERT INTO permissions(permission_id,module_id,permission_code) VALUES($id,1,".$p->quote($code).')');
            $p->exec("INSERT INTO role_permissions(role_id,permission_id) VALUES(1,$id)");
        }
        $p->exec("INSERT INTO organizational_units(organizational_unit_id,unit_code) VALUES(1,'PRES')");
        $p->exec("INSERT INTO user_access_scopes(user_id,scope_type,scope_id) VALUES(1,'university',1)");
        foreach (range(1, 2) as $id) {
            $p->exec("INSERT INTO colleges(college_id,college_name) VALUES($id,'كلية اختبار $id')");
            $p->exec("INSERT INTO departments(department_id,college_id,department_name) VALUES($id,$id,'قسم اختبار $id')");
            $p->exec("INSERT INTO academic_programs(academic_program_id,department_id,program_name,total_credit_hours) VALUES($id,$id,'برنامج اختبار $id',3)");
            $p->exec("INSERT INTO courses(course_id,course_code,course_name,credit_hours,theoretical_hours,practical_hours) VALUES($id,'C$id','مادة $id',3,2,0)");
            $p->exec("INSERT INTO course_departments(course_id,department_id,is_primary) VALUES($id,$id,1)");
        }
        $p->exec("INSERT INTO academic_levels(academic_level_id,level_name) VALUES(1,'الأول')");
        $p->exec("INSERT INTO semesters(semester_id,semester_name) VALUES(1,'الأول')");
        $p->exec("INSERT INTO academic_years(academic_year_id,year_name) VALUES(1,'2026 / 2027')");
        $p->exec("INSERT INTO result_statuses(result_status_id,status_code,status_name) VALUES(1,'passed','ناجح')");
        foreach (['university','college','department'] as $i=>$scope) foreach (['mandatory','elective'] as $j=>$type) {
            $id=2*$i+$j+1; $hours=$id===1?3:0;
            $p->exec("INSERT INTO academic_requirement_groups(requirement_group_id,academic_program_id,group_code,group_name,requirement_scope,requirement_type,required_credit_hours) VALUES($id,1,'TEST-$id','متطلبات اختبارية','$scope','$type',$hours)");
        }
        $p->exec("INSERT INTO program_courses(program_course_id,academic_program_id,course_id,course_type,academic_level_id,recommended_semester_id) VALUES(1,1,1,'mandatory',1,1),(2,2,2,'mandatory',1,1)");
        $p->exec('INSERT INTO program_course_requirement_groups(program_course_id,requirement_group_id) VALUES(1,1)');
        $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(1,2)');
    }
}
