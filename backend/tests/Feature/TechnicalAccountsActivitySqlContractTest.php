<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Source contract for the manual SQL package; MariaDB execution is verified separately. */
final class TechnicalAccountsActivitySqlContractTest extends TestCase
{
    private static function code(string $file): string
    {
        return preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__, 2).'/database/sql/technical-accounts-activity/'.$file));
    }

    /** @return list<string> */
    private static function statements(string $file, string $prefix): array
    {
        return array_values(array_filter(array_map('trim', explode(';', self::code($file))), fn ($s) => stripos($s, $prefix) === 0));
    }

    public function test_package_files_exist_and_use_utf8mb4(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists(dirname(__DIR__, 2).'/database/sql/technical-accounts-activity/'.$file);
        }
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'] as $file) {
            self::assertStringContainsString('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', self::code($file));
        }
    }

    public function test_preflight_and_verify_are_read_only(): void
    {
        foreach (['00_preflight.sql', '02_verify.sql'] as $file) {
            self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|PREPARE)\b/im', self::code($file), $file);
        }
    }

    public function test_apply_is_fail_closed_idempotent_and_never_touches_accounts_or_logs(): void
    {
        $code = self::code('01_apply.sql');
        foreach (self::statements('01_apply.sql', 'INSERT') as $insert) {
            self::assertStringContainsString('@apply_ready = 1', $insert);
            self::assertStringContainsString('NOT EXISTS', $insert);
            self::assertDoesNotMatchRegularExpression('/\b(role_id|permission_id|module_id)\s*=\s*\d+/', $insert);
        }
        self::assertSame(3, preg_match_all("/IF\(@apply_ready = 1, 'CREATE INDEX IF NOT EXISTS/", $code));
        self::assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`alrowad_uni_rust`\.`(users|user_roles|user_activity_logs|login_audit_logs|employees|students|roles)`/i', $code);
        self::assertStringNotContainsString("'users_permissions.manage'", $code);
        self::assertStringContainsString('@restricted_on_role = 0', $code);
    }

    public function test_rollback_only_removes_tagged_objects_and_blocks_on_foreign_mappings(): void
    {
        $code = self::code('03_rollback.sql');
        $deletes = self::statements('03_rollback.sql', 'DELETE');
        self::assertCount(2, $deletes);
        foreach ($deletes as $delete) {
            self::assertStringContainsString('@rollback_ready = 1', $delete);
            self::assertStringContainsString("LIKE '%[technical-accounts-activity]%'", $delete);
        }
        self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+`alrowad_uni_rust`\.`(users|user_roles|user_activity_logs|login_audit_logs|employees|students|roles)`/i', $code);
        self::assertDoesNotMatchRegularExpression('/DELETE\s+\w+\s+FROM/i', $code.self::code('01_apply.sql'));
        foreach (['@perm_untagged = 0', '@foreign_mappings = 0'] as $guard) {
            self::assertStringContainsString($guard, $code);
        }
    }
}
