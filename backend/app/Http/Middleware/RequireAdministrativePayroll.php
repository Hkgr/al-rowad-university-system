<?php

namespace App\Http\Middleware;

use App\Support\HrOffice;
use Closure;
use Illuminate\Http\Request;

final class RequireAdministrativePayroll
{
    public function __construct(private readonly HrOffice $access) {}

    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $this->access->payroll($request->user(), $permission);

        return $next($request);
    }
}
