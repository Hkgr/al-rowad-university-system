<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\LoginAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()
            ->with(['accountStatus', 'roles.permissions', 'employee.facultyMembers'])
            ->where('email', $email)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
            if ($user) {
                $user->increment('failed_login_attempts');
            }

            $this->recordLoginAttempt($request, $user, $email, 'invalid_credentials');

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 422);
        }

        if (! $user->isAccountActive()) {
            $this->recordLoginAttempt($request, $user, $email, 'inactive_account');

            return response()->json([
                'success' => false,
                'message' => 'This account is not active.',
                'errors' => [
                    'account' => ['Contact the system administrator to restore access.'],
                ],
            ], 403);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'failed_login_attempts' => 0,
        ])->save();

        $this->recordLoginAttempt($request, $user, $email, 'success');

        $token = $user->createToken('web-dashboard')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => (new AuthenticatedUserResource($user))->resolve($request),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully',
            'data' => (new AuthenticatedUserResource($request->user()))->resolve($request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => [],
        ]);
    }

    private function recordLoginAttempt(
        Request $request,
        ?User $user,
        string $email,
        string $status
    ): void {
        try {
            LoginAuditLog::query()->create([
                'user_id' => $user?->user_id,
                'username_attempted' => mb_substr($email, 0, 100),
                'login_status' => $status,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'attempted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
