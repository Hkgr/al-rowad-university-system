<?php

namespace App\Http\Middleware;

use App\Support\MinistryPortal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth for the Ministry of Education account: an account holding the
 * ministry role (active user_roles row, whatever else it holds) may call only the
 * read-only /api/v1/ministry endpoints inside /api/v1. Every other v1 route answers
 * 403 before its controller runs, so no existing endpoint (including those that
 * validate input before checking permissions) is reachable with a ministry token.
 * /api/user and /api/logout live outside /api/v1 and stay available.
 */
class ConfineMinistryAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $request->is('api/v1/ministry', 'api/v1/ministry/*') && $this->holdsMinistryRole((int) $user->user_id)) {
            return response()->json([
                'message' => 'حساب وزارة التربية والتعليم مقصور على بوابة الوزارة للاطلاع فقط.',
                'error_code' => 'ministry_account_confined',
            ], 403);
        }

        return $next($request);
    }

    private function holdsMinistryRole(int $userId): bool
    {
        try {
            return DB::table('user_roles as ur')
                ->join('roles as r', 'r.role_id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)
                ->where('ur.is_active', 1)
                ->where('r.role_code', MinistryPortal::ROLE)
                ->exists();
        } catch (QueryException $e) {
            // Without a user_roles table nobody can hold the role; any other failure is not swallowed.
            if (! Schema::hasTable('user_roles')) {
                return false;
            }
            throw $e;
        }
    }
}
