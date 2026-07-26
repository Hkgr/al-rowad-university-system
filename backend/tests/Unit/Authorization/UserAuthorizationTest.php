<?php

namespace Tests\Unit\Authorization;

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireStaffPermission;
use App\Models\AccountStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    public function test_manage_permission_implies_matching_view_permission(): void
    {
        $user = $this->userWithRoles([
            'registration_officer' => ['registration.manage'],
        ]);

        $this->assertTrue($user->hasPermission('registration.manage'));
        $this->assertTrue($user->hasPermission('registration.view'));
        $this->assertFalse($user->hasPermission('grades.manage'));
    }

    public function test_exam_role_resolves_exam_dashboard_from_server_configuration(): void
    {
        $user = $this->userWithRoles([
            'exam_officer' => ['exams.manage', 'grades.manage'],
        ]);

        $this->assertSame('/exam-board', $user->defaultDashboardPath());
        $this->assertSame(
            ['exam-board'],
            collect($user->accessibleDashboards())->pluck('code')->all()
        );
    }

    public function test_student_only_accounts_are_blocked_from_staff_middleware(): void
    {
        $user = $this->userWithRoles([
            'student' => ['students.view'],
        ], ['student_id' => 15]);
        $request = Request::create('/api/v1/students', 'GET');
        $request->setUserResolver(fn (): User => $user);

        $response = (new RequireStaffPermission())->handle(
            $request,
            fn () => response()->json(['success' => true]),
            'students.view'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_permission_middleware_accepts_manage_for_view_route(): void
    {
        $user = $this->userWithRoles([
            'registration_officer' => ['students.manage'],
        ]);
        $request = Request::create('/api/v1/students', 'GET');
        $request->setUserResolver(fn (): User => $user);

        $response = (new RequirePermission())->handle(
            $request,
            fn () => response()->json(['success' => true]),
            'students.view'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_account_must_have_active_status_code_and_flag(): void
    {
        $user = $this->userWithRoles([]);
        $user->setRelation('accountStatus', new AccountStatus([
            'status_code' => 'active',
            'status_name' => 'Active',
            'is_active' => true,
        ]));

        $this->assertTrue($user->isAccountActive());

        $user->setRelation('accountStatus', new AccountStatus([
            'status_code' => 'locked',
            'status_name' => 'Locked',
            'is_active' => true,
        ]));

        $this->assertFalse($user->isAccountActive());
    }

    private function userWithRoles(array $rolePermissions, array $attributes = []): User
    {
        $roles = collect($rolePermissions)
            ->map(function (array $permissionCodes, string $roleCode): Role {
                $role = new Role([
                    'role_code' => $roleCode,
                    'role_name' => $roleCode,
                    'is_active' => true,
                ]);
                $role->setRelation(
                    'permissions',
                    collect($permissionCodes)->map(
                        fn (string $permissionCode): Permission => new Permission([
                            'permission_code' => $permissionCode,
                            'permission_name' => $permissionCode,
                            'is_active' => true,
                        ])
                    )
                );

                return $role;
            })
            ->values();

        $user = new User(array_merge([
            'username' => 'test.user',
            'email' => 'test.user@example.test',
        ], $attributes));
        $user->setRelation('roles', $roles);

        return $user;
    }
}
