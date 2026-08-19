<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginAuditService;
use App\Services\UserIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Valid bcrypt hash used only when no matching user row exists so
     * Hash::check always runs for a syntactically valid login. Not a credential.
     */
    public const DUMMY_PASSWORD_HASH = '$2y$10$a0ujh/fUB4pnWUaG9Nnk7uk2nJwkJykV/An1whWOsLovbz6nMBXYC';

    public function __construct(
        private UserIdentityService $identity,
        private LoginAuditService $loginAudit,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        $hash = is_string($user?->password_hash) && $user->password_hash !== ''
            ? $user->password_hash
            : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($validated['password'], $hash);

        if ($user === null || ! $passwordMatches) {
            $this->loginAudit->record($request, $user, LoginAuditService::STATUS_FAILED);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => ['email' => ['The provided credentials are incorrect.']],
            ], 422);
        }

        if ($user->accountStatus?->status_code !== 'active') {
            $this->loginAudit->record($request, $user, LoginAuditService::STATUS_INACTIVE);

            return response()->json([
                'success' => false,
                'message' => 'This account is disabled or inactive.',
                'error_code' => 'account_inactive',
                'errors' => [],
            ], 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;
        $this->loginAudit->record($request, $user, LoginAuditService::STATUS_SUCCESS);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $this->identity->payload($user),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}
