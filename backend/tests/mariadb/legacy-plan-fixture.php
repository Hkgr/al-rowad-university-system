<?php

// Synthetic programs on the marker-guarded disposable instance only; no production dump.
require __DIR__.'/CatalogEnvironment.php';
$env = new CatalogEnvironment;
$p = $env->connect();
$prefix = 'LEGACY-'.bin2hex(random_bytes(4));
// Emulate rows that predate installation. Temporarily remove ONLY the new-program
// insert guard on this isolated fixture, restoring its exact original DDL before any test.
$trigger = $p->query('SHOW CREATE TRIGGER sc_plan_program_i')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
$p->exec('DROP TRIGGER sc_plan_program_i');
$p->beginTransaction();
try {
    $ids = [];
    foreach (['complete', 'incomplete', 'empty', 'unused'] as $case) {
        $q = $p->prepare('INSERT INTO academic_programs(program_code,program_name,department_id,total_credit_hours,is_active,plan_state) VALUES(?,?,1,3,1,\'legacy\')');
        $q->execute([$prefix.'-'.$case, 'خطة قديمة اختبارية '.$case]);
        $id = (int) $p->lastInsertId(); $ids[$case] = $id;
        if ($case === 'empty') continue;
        foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            if ($case === 'incomplete' && $scope === 'department') continue;
            $hours = $scope === 'university' && $type === 'mandatory' ? 3 : 0;
            if ($case === 'incomplete' && $scope === 'college') $hours = null;
            $q = $p->prepare('INSERT INTO academic_requirement_groups(academic_program_id,group_code,group_name,requirement_scope,requirement_type,required_credit_hours,is_active) VALUES(?,?,?,?,?,?,1)');
            $q->execute([$id, $prefix.'-'.$case.'-'.$scope.'-'.$type, 'متطلب اختباري', $scope, $type, $hours]);
            $group = (int) $p->lastInsertId();
            if ($scope === 'university' && $type === 'mandatory') {
                $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type,is_active) VALUES($id,1,'mandatory',1)");
                $pc = (int) $p->lastInsertId();
                $p->exec("INSERT INTO program_course_requirement_groups(program_course_id,requirement_group_id) VALUES($pc,$group)");
            }
        }
        if ($case !== 'unused') $p->exec("INSERT INTO students(academic_program_id) VALUES($id)");
    }
    $p->commit(); echo json_encode($ids)."\n";
} catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
finally { $p->exec($trigger); }
