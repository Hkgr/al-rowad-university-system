<?php

/** Adds only synthetic columns needed by real canonical transfer reads. Guarded disposable server only. */
final class AcademicPlanRuntimeFixture
{
    public static function complete(CatalogEnvironment $environment, PDO $p): void
    {
        $environment->guard($p);
        $p->exec('CREATE TABLE IF NOT EXISTS appeal_statuses (appeal_status_id INT PRIMARY KEY, status_code VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        $p->exec('CREATE TABLE IF NOT EXISTS grade_appeals (grade_appeal_id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, appeal_status_id INT NULL) ENGINE=InnoDB');
        $p->exec('CREATE TABLE IF NOT EXISTS supplementary_exam_registrations (supplementary_exam_registration_id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB');
        $p->exec('CREATE TABLE IF NOT EXISTS supplementary_exam_materializations (supplementary_exam_materialization_id INT AUTO_INCREMENT PRIMARY KEY, supplementary_exam_registration_id INT NOT NULL) ENGINE=InnoDB');
        foreach (['registration_statuses' => 'registration_status_id', 'approval_statuses' => 'approval_status_id', 'student_statuses' => 'student_status_id'] as $table => $key) {
            $p->exec("CREATE TABLE IF NOT EXISTS $table ($key INT PRIMARY KEY, status_code VARCHAR(40) NOT NULL, status_name VARCHAR(100) NULL) ENGINE=InnoDB");
        }
        $columns = [
            'students' => ['current_academic_level_id INT NULL', 'student_status_id INT NULL', 'student_number VARCHAR(60) NULL', 'first_name VARCHAR(100) NULL', 'last_name VARCHAR(100) NULL'],
            'student_course_registrations' => ['registration_status_id INT NULL', 'result_status_id INT NULL', 'registration_date DATE NULL'],
            'student_course_results' => ['student_course_registration_id INT NULL', 'result_status_id INT NULL', 'final_mark DECIMAL(6,3) NULL', 'theoretical_total DECIMAL(6,3) NULL', 'practical_total DECIMAL(6,3) NULL'],
            'grade_approvals' => ['approval_status_id INT NULL'],
            'student_registration_request_items' => ['student_registration_request_id INT NULL'],
            'student_registration_modification_items' => ['student_registration_modification_request_id INT NULL', 'operation VARCHAR(20) NULL'],
            'student_registration_replacement_items' => ['student_registration_replacement_request_id INT NULL', 'replacement_course_offering_id INT NULL'],
            'academic_years' => ['start_date DATE NULL', 'end_date DATE NULL', 'is_current TINYINT NOT NULL DEFAULT 0'],
            'student_progression_decisions' => ['status VARCHAR(30) NULL', 'current_slot TINYINT NULL', 'materialized_at DATETIME NULL'],
            'student_graduation_decisions' => ['status VARCHAR(30) NULL', 'current_slot TINYINT NULL', 'materialized_at DATETIME NULL'],
        ];
        foreach ($columns as $table => $definitions) foreach ($definitions as $definition) $p->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS $definition");
        $p->exec("INSERT IGNORE INTO registration_statuses VALUES(1,'completed','مكتمل'),(2,'registered','مسجل')");
        $p->exec("INSERT IGNORE INTO approval_statuses VALUES(1,'approved','معتمد')");
        $p->exec("CREATE TABLE IF NOT EXISTS grade_components (grade_component_id INT AUTO_INCREMENT PRIMARY KEY, course_offering_id INT NOT NULL, component_type VARCHAR(20) NOT NULL, is_required TINYINT NOT NULL DEFAULT 1, max_mark DECIMAL(6,3) NULL) ENGINE=InnoDB");
    }
}
