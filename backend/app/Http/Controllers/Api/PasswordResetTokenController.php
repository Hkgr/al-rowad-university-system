<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\PasswordResetToken\StorePasswordResetTokenRequest;
use App\Http\Requests\PasswordResetToken\UpdatePasswordResetTokenRequest;
use App\Http\Resources\PasswordResetTokenResource;
use App\Models\PasswordResetToken;

class PasswordResetTokenController extends ApiController
{
    protected function modelClass(): string
    {
        return PasswordResetToken::class;
    }

    protected function resourceClass(): string
    {
        return PasswordResetTokenResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StorePasswordResetTokenRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdatePasswordResetTokenRequest::class;
    }
}
