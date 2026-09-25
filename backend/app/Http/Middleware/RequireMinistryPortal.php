<?php

namespace App\Http\Middleware;

use App\Support\MinistryPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Server-side guard for every /v1/ministry endpoint (see App\Support\MinistryPortal). */
class RequireMinistryPortal
{
    public function __construct(private readonly MinistryPortal $portal) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->portal->allows($request->user(), $permission)) {
            return response()->json(['message' => MinistryPortal::FORBIDDEN_MESSAGE, 'error_code' => 'ministry_portal_forbidden'], 403);
        }

        return $next($request);
    }
}
