<?php

namespace App\Http\Middleware;

use App\Support\PresidentPortal;
use Closure;
use Illuminate\Http\Request;

final class RequirePresidentPortal
{
    public function handle(Request $request, Closure $next, string $section)
    {
        abort_unless(app(PresidentPortal::class)->allows($request->user(), $section), 403, 'لا تملك صلاحية هذا القسم من بوابة رئيس الجامعة أو نطاق الجامعة الفعلي.');
        return $next($request);
    }
}
