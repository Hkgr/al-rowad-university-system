<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\AccountAdministration;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RoleController extends ApiController
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function resourceClass(): string
    {
        return RoleResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreRoleRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateRoleRequest::class;
    }

    /**
     * The super_admin role anchors the last-administrator guard; renaming,
     * deactivating or deleting it would bypass that guard.
     */
    protected function beforeUpdateMutation(Role $role, array $data): void
    {
        if ($role->role_code !== AccountAdministration::ROLE_SUPER_ADMIN) {
            return;
        }
        $renamed = array_key_exists('role_code', $data) && $data['role_code'] !== AccountAdministration::ROLE_SUPER_ADMIN;
        $deactivated = array_key_exists('is_active', $data) && ! (bool) $data['is_active'];
        if ($renamed || $deactivated) {
            throw new ConflictHttpException('لا يمكن إعادة تسمية دور super_admin أو تعطيله.');
        }
    }

    protected function beforeDestroyMutation(Role $role): void
    {
        if ($role->role_code === AccountAdministration::ROLE_SUPER_ADMIN) {
            throw new ConflictHttpException('لا يمكن حذف دور super_admin.');
        }
    }
}
