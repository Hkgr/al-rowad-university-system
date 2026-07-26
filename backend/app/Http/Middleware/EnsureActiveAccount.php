<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->accountStatus?->status_code !== 'active') {
            $user->currentAccessToken()?->delete();
            throw new AccessDeniedHttpException('This account is disabled or inactive.');
        }

        return $next($request);
    }
}
