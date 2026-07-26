<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AccountStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends ApiController
{
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password_hash'] = Hash::make($data['password']);
        $data['created_by_user_id'] = $request->user()->user_id;
        $data['failed_login_attempts'] = 0;
        unset($data['password']);

        $user = User::query()->create($data);

        return $this->successResponse(
            (new UserResource($user))->resolve($request),
            'Operation completed successfully',
            201
        );
    }

    public function update(UpdateUserRequest $request, $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $data = $request->validated();
        $passwordChanged = filled($data['password'] ?? null);

        if ($passwordChanged) {
            $data['password_hash'] = Hash::make($data['password']);
        }

        unset($data['password']);
        $user->update($data);
        $user->refresh()->load('accountStatus');

        if ($passwordChanged || ! $user->isAccountActive()) {
            $user->tokens()->delete();
        }

        return $this->successResponse(
            (new UserResource($user))->resolve($request)
        );
    }

    public function destroy($id): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if ((int) $user->user_id === (int) request()->user()->user_id) {
            return $this->errorResponse(
                'You cannot disable your own account.',
                ['user' => ['Ask another administrator to perform this action.']],
                422
            );
        }

        $disabledStatusId = AccountStatus::query()
            ->where('status_code', 'disabled')
            ->value('account_status_id');

        if (! $disabledStatusId) {
            return $this->errorResponse(
                'The disabled account status is not configured.',
                [],
                409
            );
        }

        $user->update(['account_status_id' => $disabledStatusId]);
        $user->tokens()->delete();

        return $this->successResponse(
            [],
            'User account disabled successfully'
        );
    }

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function resourceClass(): string
    {
        return UserResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreUserRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateUserRequest::class;
    }
}
