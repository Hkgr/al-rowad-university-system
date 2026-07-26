<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UserRole\StoreUserRoleRequest;
use App\Http\Requests\UserRole\UpdateUserRoleRequest;
use App\Http\Resources\UserRoleResource;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;

class UserRoleController extends ApiController
{
    public function store(StoreUserRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userRole = UserRole::query()->firstOrNew([
            'user_id' => $data['user_id'],
            'role_id' => $data['role_id'],
        ]);
        $wasRecentlyCreated = ! $userRole->exists;

        $userRole->fill([
            'assigned_by_user_id' => $request->user()->user_id,
            'assigned_at' => now(),
            'is_active' => true,
        ])->save();

        return $this->successResponse(
            (new UserRoleResource($userRole))->resolve($request),
            $wasRecentlyCreated
                ? 'Role assigned successfully'
                : 'Role assignment reactivated successfully',
            $wasRecentlyCreated ? 201 : 200
        );
    }

    public function update(UpdateUserRoleRequest $request, $id): JsonResponse
    {
        $userRole = UserRole::query()->findOrFail($id);

        if (
            (int) $userRole->user_id === (int) $request->user()->user_id
            && ! $request->boolean('is_active')
        ) {
            return $this->errorResponse(
                'You cannot deactivate your own role assignment.',
                ['role' => ['Ask another administrator to perform this action.']],
                422
            );
        }

        $userRole->update([
            'is_active' => $request->boolean('is_active'),
            'assigned_by_user_id' => $request->user()->user_id,
            'assigned_at' => now(),
        ]);

        return $this->successResponse(
            (new UserRoleResource($userRole->fresh()))->resolve($request)
        );
    }

    public function destroy($id): JsonResponse
    {
        $userRole = UserRole::query()->findOrFail($id);

        if ((int) $userRole->user_id === (int) request()->user()->user_id) {
            return $this->errorResponse(
                'You cannot deactivate your own role assignment.',
                ['role' => ['Ask another administrator to perform this action.']],
                422
            );
        }

        $userRole->update([
            'is_active' => false,
            'assigned_by_user_id' => request()->user()->user_id,
            'assigned_at' => now(),
        ]);

        return $this->successResponse(
            [],
            'Role assignment deactivated successfully'
        );
    }

    protected function modelClass(): string
    {
        return UserRole::class;
    }

    protected function resourceClass(): string
    {
        return UserRoleResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreUserRoleRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateUserRoleRequest::class;
    }
}
