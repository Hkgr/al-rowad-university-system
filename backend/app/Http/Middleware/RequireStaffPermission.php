<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffPermission extends RequirePermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $permissionList
    ): Response {
        $permissions = $this->parsePermissions($permissionList);
        $user = $request->user();

        if ($user?->isStudentOnly() || ! $user?->hasAnyPermission($permissions)) {
            return $this->deniedResponse($permissions);
        }

        return $next($request);
    }
}
