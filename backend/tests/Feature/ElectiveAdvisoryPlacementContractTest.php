<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Source contracts only. Actual DDL, constraints, resume and races have separate MariaDB suites. */
final class ElectiveAdvisoryPlacementContractTest extends TestCase
{
    public function test_every_package_step_uses_the_same_pre_mutation_guard_contract(): void
    {
        $contracts = [];
        foreach (['00_preflight','01_apply','02_verify','03_rollback'] as $step) {
            $sql = file_get_contents(__DIR__.'/../../database/sql/elective-advisory-placement/'.$step.'.sql');
            preg_match('/WITH expected_guards AS \((.*?)\n\)\s*\nSELECT /s',$sql,$m);
            self::assertNotEmpty($m, $step);
            $contracts[] = $m[1];
            foreach (['expected_parameters','foreign_key_mapping_or_rules','unknown_check_definition','unknown_trigger:',
                'generated_plan_context','placement_column_definition','constraint_checks_disabled'] as $guard) self::assertStringContainsString($guard,$sql);
            self::assertDoesNotMatchRegularExpression('/(?:CREATE|DROP|REPLACE)\s+(?:TABLE|TRIGGER|FUNCTION|PROCEDURE)\b/i',$sql);
            self::assertDoesNotMatchRegularExpression('/(?:UPDATE|DELETE FROM|INSERT INTO)\s+program_courses\b/i',$sql);
        }
        self::assertSame($contracts[0],$contracts[1]);
        self::assertSame($contracts[0],$contracts[2]);
        self::assertSame($contracts[0],$contracts[3]);
    }

    public function test_runtime_uses_verified_sql_and_manifest_bytes_remain_approved(): void
    {
        $root = dirname(__DIR__,2);
        self::assertSame('2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238',hash_file('sha256',$root.'/resources/academic-catalog/approved-2026-10.json'));
        self::assertStringContainsString("sql/elective-advisory-placement/02_verify.sql",file_get_contents($root.'/app/Services/AcademicPlacementSchema.php'));
        $apply=file_get_contents($root.'/database/sql/elective-advisory-placement/01_apply.sql');
        self::assertLessThan(strpos($apply,'ALTER TABLE program_courses'),strpos($apply,'elective_placement_incompatible_schema_or_data'));
        $rollback=file_get_contents($root.'/database/sql/elective-advisory-placement/03_rollback.sql');
        self::assertLessThan(strpos($rollback,'ALTER TABLE program_courses'),strpos($rollback,'elective_placement_rollback_would_lose_unplaced_memberships'));
    }
}
