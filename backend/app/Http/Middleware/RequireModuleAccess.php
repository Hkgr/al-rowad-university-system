<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequireModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $permission = $module.'.'.($request->isMethodSafe() ? 'view' : 'manage');
        if (! $request->user()?->hasPermission($permission)) {
            throw new AccessDeniedHttpException('You do not have permission to access this module.');
        }

        return $next($request);
    }
}
