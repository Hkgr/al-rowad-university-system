<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Source contract for the manual SQL package; MariaDB execution is verified separately. */
final class VpAdministrativeGovernanceSqlContractTest extends TestCase
{
    private const CODES = "'vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage'";

    private static function sql(string $file): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/database/sql/vp-administrative-governance/'.$file);
    }

    private static function code(string $file): string
    {
        return preg_replace('/^\s*--.*$/m', '', self::sql($file));
    }

    /** @return list<string> */
    private static function statements(string $file, string $prefix): array
    {
        return array_values(array_filter(array_map('trim', explode(';', self::code($file))), fn ($s) => stripos($s, $prefix) === 0));
    }

    public function test_package_files_exist_use_utf8mb4_and_add_no_migration(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists(dirname(__DIR__, 2).'/database/sql/vp-administrative-governance/'.$file);
        }
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'] as $file) {
            self::assertStringContainsString('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', self::sql($file), $file);
        }
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            self::assertStringNotContainsString('vice_presidency.administrative.faculty', file_get_contents($migration));
        }
    }

    public function test_preflight_and_verify_are_read_only(): void
    {
        foreach (['00_preflight.sql', '02_verify.sql'] as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE)\b/i', self::code($file), $file);
        }
    }

    public function test_apply_only_adds_tagged_permissions_for_the_administrative_vp(): void
    {
        $code = self::code('01_apply.sql');
        self::assertDoesNotMatchRegularExpression('/^\s*(ALTER|DROP|CREATE|TRUNCATE)\s/im', $code, 'no schema change');
        self::assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`alrowad_uni_rust`\.`(users|user_roles|user_access_scopes|employees|faculty_members|employee_unit_assignments|employee_positions|roles)`/i', $code);
        self::assertStringNotContainsString('password', strtolower($code));
        $inserts = self::statements('01_apply.sql', 'INSERT');
        self::assertCount(2, $inserts);
        foreach ($inserts as $insert) {
            self::assertStringContainsString('@apply_ready = 1', $insert);
            self::assertStringContainsString('NOT EXISTS', $insert);
            self::assertDoesNotMatchRegularExpression('/\b(role_id|permission_id|module_id)\s*=\s*\d+/', $insert);
        }
        self::assertStringContainsString("r.role_code = 'vice_president_administrative'", $inserts[1]);
        self::assertStringContainsString("CONCAT(defs.description, ' ', @tag)", $inserts[0]);
        foreach (self::statements('01_apply.sql', 'DELETE') as $delete) {
            self::assertStringContainsString('@apply_complete = 0', $delete);
            self::assertStringContainsString('@perms_absent_at_start = 1', $delete);
            self::assertStringContainsString('[vp-admin-governance]', $delete);
        }
    }

    public function test_rollback_removes_only_tagged_permissions_and_never_real_data(): void
    {
        $code = self::code('03_rollback.sql');
        $deletes = self::statements('03_rollback.sql', 'DELETE');
        self::assertCount(2, $deletes);
        self::assertStringStartsWith('DELETE FROM `alrowad_uni_rust`.`role_permissions`', $deletes[0]);
        self::assertStringStartsWith('DELETE FROM `alrowad_uni_rust`.`permissions`', $deletes[1]);
        foreach ($deletes as $delete) {
            self::assertStringContainsString('@rollback_ready = 1', $delete);
            self::assertStringContainsString("LIKE '%[vp-admin-governance]%'", $delete);
            self::assertStringContainsString(self::CODES, $delete);
        }
        self::assertStringContainsString("r.role_code IN ('vice_president_administrative', 'super_admin')", $deletes[0]);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`)', $deletes[1]);
        self::assertDoesNotMatchRegularExpression('/(DELETE\s+FROM|UPDATE)\s+`alrowad_uni_rust`\.`(users|user_roles|user_access_scopes|employees|faculty_members|employee_unit_assignments|employee_positions|user_activity_logs|roles)`/i', $code);
        self::assertDoesNotMatchRegularExpression('/DELETE\s+\w+\s+FROM/i', $code.self::code('01_apply.sql'), 'single-table DELETE only (phpMyAdmin without a selected database)');
        foreach (['@perm_untagged = 0', '@foreign_mappings = 0'] as $guard) {
            self::assertStringContainsString($guard, $code);
        }
    }
}
