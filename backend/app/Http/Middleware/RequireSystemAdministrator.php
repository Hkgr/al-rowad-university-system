<?php

namespace App\Http\Middleware;

use App\Services\AcademicAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSystemAdministrator
{
    public function handle(Request $request, Closure $next, AcademicAuthorizationService $authorization): Response
    {
        $authorization->assertSystemAdministrator($request->user());

        return $next($request);
    }
}
