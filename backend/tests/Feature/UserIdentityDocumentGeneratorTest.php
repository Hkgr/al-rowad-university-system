<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\UserIdentityService;
use Tests\TestCase;

class UserIdentityDocumentGeneratorTest extends TestCase
{
    public function test_employee_name_and_trusted_unit_are_used_without_internal_ids(): void
    {
        $unit = new OrganizationalUnit([
            'unit_code' => 'EXAM',
            'unit_name' => 'قسم الامتحانات',
        ]);
        $employee = new Employee(['first_name' => 'سارة', 'last_name' => 'خالد']);
        $employee->setRelation('organizationalUnit', $unit);
        $user = new User(['username' => 'exam.sara']);
        $user->setRelation('employee', $employee);

        $payload = (new UserIdentityService($this->createMock(DataScopeService::class)))
            ->documentGenerator($user);

        self::assertSame('سارة خالد', $payload['display_name']);
        self::assertSame('exam.sara', $payload['username']);
        self::assertSame(['code' => 'EXAM', 'name' => 'قسم الامتحانات'], $payload['organizational_unit']);
        self::assertArrayNotHasKey('user_id', $payload);
        self::assertArrayNotHasKey('employee_id', $payload);
        self::assertArrayNotHasKey('email', $payload);
    }

    public function test_username_is_safe_fallback_without_employee(): void
    {
        $user = new User(['username' => 'exam.fallback']);
        $user->setRelation('employee', null);

        $payload = (new UserIdentityService($this->createMock(DataScopeService::class)))
            ->documentGenerator($user);

        self::assertSame('exam.fallback', $payload['display_name']);
        self::assertNull($payload['organizational_unit']);
    }
}
