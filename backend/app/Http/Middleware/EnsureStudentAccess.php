<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentAccess
{
    public function handle(
        Request $request,
        Closure $next,
        string $staffPermission
    ): Response {
        $user = $request->user();
        $routeStudent = $request->route('student') ?? $request->route('id');
        $studentId = $routeStudent instanceof Student
            ? $routeStudent->student_id
            : filter_var($routeStudent, FILTER_VALIDATE_INT);

        $isOwnRecord = $studentId !== false
            && $studentId !== null
            && $user?->student_id !== null
            && (int) $user->student_id === (int) $studentId;

        $staffPermissions = array_values(array_filter(explode('|', $staffPermission)));
        $hasStaffAccess = $user !== null
            && ! $user->isStudentOnly()
            && $user->hasAnyPermission($staffPermissions);

        if ($isOwnRecord || $hasStaffAccess) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have access to this student record.',
            'errors' => [
                'student' => ['Only the record owner or an authorized staff member may access it.'],
            ],
        ], 403);
    }
}
