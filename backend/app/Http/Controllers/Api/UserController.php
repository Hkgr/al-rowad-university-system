<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Http\Requests\User\LinkUserIdentityRequest;
use App\Services\UserIdentityLinkService;
use Illuminate\Http\JsonResponse;

class UserController extends ApiController
{
    public function linkIdentity(User $user, LinkUserIdentityRequest $request, UserIdentityLinkService $service): JsonResponse
    {
        $linked = $service->link($user, $request->user(), $request->validated(), $request->ip());
        return $this->successResponse((new UserResource($linked))->resolve($request), 'User identity links updated.');
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
