<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Source contract for the ministry-portal manual SQL package; MariaDB execution is verified separately. */
final class MinistryPortalSqlContractTest extends TestCase
{
    private const DIR = '/database/sql/ministry-portal/';

    private static function code(string $file): string
    {
        return preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__, 2).self::DIR.$file));
    }

    /** @return list<string> */
    private static function statements(string $file, string $prefix): array
    {
        return array_values(array_filter(array_map('trim', explode(';', self::code($file))), fn ($s) => stripos($s, $prefix) === 0));
    }

    public function test_package_files_exist_and_use_utf8mb4(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists(dirname(__DIR__, 2).self::DIR.$file);
        }
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'] as $file) {
            self::assertStringContainsString('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', self::code($file));
            self::assertDoesNotMatchRegularExpression('/\b(DATABASE\(\)|DELIMITER|SIGNAL|CREATE\s+PROCEDURE|USE\s+`)/i', self::code($file), $file);
        }
    }

    public function test_preflight_and_verify_are_read_only(): void
    {
        foreach (['00_preflight.sql', '02_verify.sql'] as $file) {
            self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|PREPARE|START)\b/im', self::code($file), $file);
        }
    }

    public function test_apply_is_fail_closed_idempotent_and_creates_no_account_password_scope_or_foreign_grant(): void
    {
        $code = self::code('01_apply.sql');
        $inserts = self::statements('01_apply.sql', 'INSERT');
        self::assertCount(4, $inserts, 'module, role, permissions, role_permissions');
        foreach ($inserts as $insert) {
            self::assertStringContainsString('@apply_ready = 1', $insert);
            self::assertStringContainsString('NOT EXISTS', $insert);
            self::assertDoesNotMatchRegularExpression('/\b(role_id|permission_id|module_id)\s*=\s*\d+/', $insert);
        }
        self::assertStringContainsString("r.role_code = 'ministry_observer'", end($inserts), 'permissions are mapped to the ministry role only');
        self::assertStringNotContainsString('super_admin', $code);
        self::assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`alrowad_uni_rust`\.`(users|user_roles|user_access_scopes|personal_access_tokens|students|employees)`/i', $code);
        self::assertDoesNotMatchRegularExpression('/password|\$2y\$/i', $code);
        self::assertDoesNotMatchRegularExpression('/\b(ALTER|CREATE\s+INDEX|DROP)\b/i', $code, 'no schema change');
        foreach (['@foreign_mappings = 0', '@perm_conflicts = 0', "@role_state IN ('ABSENT', 'COMPATIBLE')", "@module_state IN ('ABSENT', 'COMPATIBLE')"] as $guard) {
            self::assertStringContainsString($guard, $code);
        }
        self::assertSame(8, preg_match_all("/'ministry_portal\.[a-z_.]+'/", self::statements('01_apply.sql', 'INSERT INTO `alrowad_uni_rust`.`permissions`')[0]));
    }

    public function test_rollback_only_removes_tagged_objects_and_blocks_on_assignments_or_foreign_mappings(): void
    {
        $code = self::code('03_rollback.sql');
        $deletes = self::statements('03_rollback.sql', 'DELETE');
        self::assertCount(4, $deletes);
        foreach ($deletes as $delete) {
            self::assertStringContainsString('@rollback_ready = 1', $delete);
            self::assertStringContainsString("LIKE '%[ministry-portal]%'", $delete);
        }
        self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+`alrowad_uni_rust`\.`(users|user_roles|user_access_scopes|students|employees)`/i', $code);
        self::assertDoesNotMatchRegularExpression('/DELETE\s+\w+\s+FROM/i', $code.self::code('01_apply.sql'), 'single-table deletes only (phpMyAdmin without a selected database)');
        foreach (['@assignment_rows = 0', '@foreign_mappings = 0', '@role_untagged = 0', '@perm_untagged = 0', '@module_untagged = 0'] as $guard) {
            self::assertStringContainsString($guard, $code);
        }
    }
}
