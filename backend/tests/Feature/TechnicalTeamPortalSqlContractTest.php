<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** Source contract for the manual SQL package; MariaDB execution is verified separately. */
final class TechnicalTeamPortalSqlContractTest extends TestCase
{
    private static function sql(string $file): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/database/sql/technical-team-portal/'.$file);
    }

    /** @return list<string> */
    private static function statements(string $file, string $prefix): array
    {
        $body = preg_replace('/^\s*--.*$/m', '', self::sql($file));

        return array_values(array_filter(array_map('trim', explode(';', $body)), fn ($s) => stripos($s, $prefix) === 0));
    }

    public function test_package_files_exist_and_no_laravel_migration_is_added(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists(dirname(__DIR__, 2).'/database/sql/technical-team-portal/'.$file);
        }
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            self::assertStringNotContainsString('technical_team', file_get_contents($migration));
        }
    }

    public function test_preflight_and_verify_are_read_only(): void
    {
        foreach (['00_preflight.sql', '02_verify.sql'] as $file) {
            $body = preg_replace('/^\s*--.*$/m', '', self::sql($file));
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $body, $file);
        }
    }

    public function test_apply_is_idempotent_fail_closed_and_resolves_ids_by_code(): void
    {
        $apply = self::sql('01_apply.sql');
        self::assertStringContainsString('START TRANSACTION', $apply);
        self::assertStringContainsString("sm.module_code = 'users_permissions'", $apply);
        self::assertStringContainsString("r.role_code = 'technical_team'", $apply);
        $inserts = self::statements('01_apply.sql', 'INSERT');
        self::assertCount(4, $inserts);
        foreach ($inserts as $insert) {
            self::assertStringContainsString('@apply_ready = 1', $insert);
            self::assertStringContainsString('NOT EXISTS', $insert);
            self::assertDoesNotMatchRegularExpression('/\b(role_id|permission_id|module_id)\s*=\s*\d+/', $insert);
        }
        $code = preg_replace('/^\s*--.*$/m', '', $apply);
        self::assertStringNotContainsString('`users`', $code);
        self::assertStringNotContainsString('`user_roles`', $code);
        self::assertStringNotContainsString('password', strtolower($code));
        self::assertStringNotContainsString('DELIMITER', $code);
        self::assertStringNotContainsString('CREATE PROCEDURE', $code);
    }

    public function test_technical_team_is_never_granted_the_manage_permissions_power(): void
    {
        $apply = self::sql('01_apply.sql');
        self::assertMatchesRegularExpression("/p\.permission_code IN \('technical_portal\.access', 'user_accounts\.view', 'user_accounts\.manage'\)\s+WHERE @apply_ready = 1\s+AND r\.role_code = 'technical_team'/", $apply);
        foreach (self::statements('01_apply.sql', 'INSERT') as $insert) {
            self::assertStringNotContainsString("'users_permissions.manage'", $insert);
            self::assertStringNotContainsString("'system_settings.manage'", $insert);
        }
        self::assertStringContainsString('@restricted_on_role = 0', $apply);
        $verify = self::sql('02_verify.sql');
        self::assertStringContainsString("'technical_portal.access,user_accounts.manage,user_accounts.view'", $verify);
        self::assertStringContainsString('HAVING COUNT(*) > 1', $verify);
        self::assertStringContainsString("r.role_code = 'super_admin' AND r.is_active = 1", $verify);
    }

    public function test_rollback_deletes_only_tagged_unreferenced_rows_and_blocks_on_history(): void
    {
        $rollback = self::sql('03_rollback.sql');
        $deletes = self::statements('03_rollback.sql', 'DELETE');
        self::assertCount(3, $deletes);
        foreach ($deletes as $delete) {
            self::assertStringContainsString('@rollback_ready = 1', $delete);
            self::assertStringContainsString("LIKE '%[technical-team-portal]%'", $delete);
            self::assertDoesNotMatchRegularExpression('/DELETE\s+\w*\s*FROM\s+`alrowad_uni_rust`\.`(user_roles|users|user_activity_logs|system_modules|organizational_units)`/i', $delete);
        }
        self::assertStringStartsWith('DELETE FROM `alrowad_uni_rust`.`role_permissions`', $deletes[0]);
        self::assertStringStartsWith('DELETE FROM `alrowad_uni_rust`.`permissions`', $deletes[1]);
        self::assertStringStartsWith('DELETE FROM `alrowad_uni_rust`.`roles`', $deletes[2]);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`)', $deletes[1]);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`user_roles` ur WHERE ur.role_id = `alrowad_uni_rust`.`roles`.`role_id`)', $deletes[2]);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = `alrowad_uni_rust`.`roles`.`role_id`)', $deletes[2]);
        self::assertStringContainsString("r.role_code = 'super_admin')", $deletes[0]);
        self::assertStringContainsString("p.permission_code IN ('user_accounts.view', 'user_accounts.manage')", $deletes[0]);
        // Multi-table "DELETE alias FROM" fails in MariaDB without a selected database.
        self::assertDoesNotMatchRegularExpression('/DELETE\s+\w+\s+FROM/i', preg_replace('/^\s*--.*$/m', '', $rollback.self::sql('01_apply.sql')));
        foreach (['@assignment_rows = 0', '@foreign_mappings = 0', '@role_untagged = 0', '@perm_untagged = 0'] as $guard) {
            self::assertStringContainsString($guard, $rollback);
        }
    }
}
