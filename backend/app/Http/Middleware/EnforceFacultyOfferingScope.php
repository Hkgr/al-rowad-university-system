<?php

namespace App\Http\Middleware;

use App\Models\AttendanceSession;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnforceFacultyOfferingScope
{
    public function handle(
        Request $request,
        Closure $next,
        string $resourceType = 'course-offering'
    ): Response {
        $user = $request->user();
        $facultyRole = config('authorization.faculty_scoped_role', 'doctor_instructor');

        if (
            ! $user?->hasRole($facultyRole)
            || $user->hasRole(config('authorization.faculty_scope_bypass_roles', []))
        ) {
            return $next($request);
        }

        $facultyMemberIds = $user->employee
            ?->facultyMembers()
            ->where('is_active', true)
            ->pluck('faculty_member_id')
            ->all() ?? [];

        $courseOfferingId = $this->resolveCourseOfferingId($request, $resourceType);
        $hasInstructorAssignments = Schema::hasTable('course_offering_instructors');
        $isAssigned = $courseOfferingId !== null
            && $facultyMemberIds !== []
            && CourseOffering::query()
                ->whereKey($courseOfferingId)
                ->where(function ($query) use ($facultyMemberIds, $hasInstructorAssignments): void {
                    $query->whereIn('faculty_member_id', $facultyMemberIds);

                    if ($hasInstructorAssignments) {
                        $query->orWhereHas(
                            'offeringInstructors',
                            fn ($instructors) => $instructors
                                ->whereIn('faculty_member_id', $facultyMemberIds)
                                ->where('is_active', true)
                        );
                    }
                })
                ->exists();

        if (! $isAssigned) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this course offering.',
                'errors' => [
                    'course_offering' => [
                        'Faculty members may access only offerings assigned to them.',
                    ],
                ],
            ], 403);
        }

        return $next($request);
    }

    private function resolveCourseOfferingId(Request $request, string $resourceType): ?int
    {
        $routeValue = $request->route('courseOffering') ?? $request->route('id');
        $resourceId = $routeValue instanceof Model
            ? $routeValue->getKey()
            : filter_var($routeValue, FILTER_VALIDATE_INT);

        if ($resourceId === false || $resourceId === null) {
            return null;
        }

        return match ($resourceType) {
            'registration' => StudentCourseRegistration::query()
                ->whereKey($resourceId)
                ->value('course_offering_id'),
            'attendance-session' => AttendanceSession::query()
                ->whereKey($resourceId)
                ->value('course_offering_id'),
            default => (int) $resourceId,
        };
    }
}
