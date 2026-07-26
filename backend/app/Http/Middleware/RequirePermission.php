<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $permissionList
    ): Response {
        $permissions = $this->parsePermissions($permissionList);

        if (! $request->user()?->hasAnyPermission($permissions)) {
            return $this->deniedResponse($permissions);
        }

        return $next($request);
    }

    protected function parsePermissions(string $permissionList): array
    {
        return array_values(array_filter(explode('|', $permissionList)));
    }

    protected function deniedResponse(array $permissions): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
            'errors' => [
                'required_permissions' => $permissions,
            ],
        ], 403);
    }
}
