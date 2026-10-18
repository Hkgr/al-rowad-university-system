<?php

namespace App\Http\Middleware;

use App\Support\OwnerPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Server-side guard for every /v1/owner endpoint (see App\Support\OwnerPortal). */
class RequireOwnerPortal
{
    public function __construct(private readonly OwnerPortal $portal) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->portal->allows($request->user(), $permission)) {
            return response()->json(['success' => false, 'message' => OwnerPortal::FORBIDDEN_MESSAGE, 'error_code' => 'owner_portal_forbidden', 'errors' => []], 403);
        }

        return $next($request);
    }
}
