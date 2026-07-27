<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if ($user === null || ! collect($permissions)->contains(fn ($permission) => $user->hasPermission($permission))) {
            throw new AccessDeniedHttpException('You do not have permission to perform this operation.');
        }

        return $next($request);
    }
}
