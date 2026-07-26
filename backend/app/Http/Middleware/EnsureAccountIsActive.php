<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isAccountActive()) {
            $user?->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'This account is not active.',
                'errors' => [
                    'account' => ['Contact the system administrator to restore access.'],
                ],
            ], 403);
        }

        return $next($request);
    }
}
