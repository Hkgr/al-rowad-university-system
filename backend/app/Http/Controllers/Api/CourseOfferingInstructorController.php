<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseOfferingInstructor\StoreCourseOfferingInstructorRequest;
use App\Http\Requests\CourseOfferingInstructor\UpdateCourseOfferingInstructorRequest;
use App\Http\Resources\CourseOfferingInstructorResource;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Services\TeachingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Exam-board / generic offering-instructor API.
 * Dean assignment activation for NEW slots goes through TeachingAssignmentWorkflowService.
 * This controller still writes the effective course_offering_instructors projection
 * and is not the Dean teaching-assignment workflow.
 */
class CourseOfferingInstructorController extends Controller
{
    public function __construct(private TeachingAssignmentService $teachingAssignments)
    {
    }

    public function index(CourseOffering $courseOffering): JsonResponse
    {
        $this->teachingAssignments->assertCanViewAssignments(request()->user(), $courseOffering);

        $instructors = $courseOffering->offeringInstructors()
            ->with('facultyMember')
            ->orderByDesc('is_primary')
            ->orderBy('course_offering_instructor_id')
            ->get();

        return $this->successResponse(
            CourseOfferingInstructorResource::collection($instructors)->resolve()
        );
    }

    public function store(
        StoreCourseOfferingInstructorRequest $request,
        CourseOffering $courseOffering
    ): JsonResponse {
        $this->teachingAssignments->assertCanManageAssignments($request->user(), $courseOffering);

        $validated = $request->validated();
        $role = (string) $validated['instructor_role'];
        $facultyMember = FacultyMember::query()->findOrFail((int) $validated['faculty_member_id']);
        $this->teachingAssignments->assertValidAssignment($courseOffering, $facultyMember, $role);

        $instructor = DB::transaction(function () use ($courseOffering, $facultyMember, $role, $validated): CourseOfferingInstructor {
            $courseOffering->loadMissing('course');
            $isActive = array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true;

            $instructor = CourseOfferingInstructor::query()->updateOrCreate(
                [
                    'course_offering_id' => $courseOffering->course_offering_id,
                    'instructor_role' => $role,
                ],
                [
                    'faculty_member_id' => $facultyMember->faculty_member_id,
                    'is_primary' => $this->teachingAssignments->deriveIsPrimary($courseOffering->course, $role),
                    'is_active' => $isActive,
                ]
            );

            if ($isActive) {
                $this->teachingAssignments->ensureGenericCourseInstructor(
                    (int) $courseOffering->course_id,
                    (int) $facultyMember->faculty_member_id
                );
            }

            $this->teachingAssignments->normalizePrimaryFlags($courseOffering);
            $this->teachingAssignments->syncLegacyFacultyPointer($courseOffering);

            return $instructor->fresh(['facultyMember']);
        });

        return $this->successResponse(
            (new CourseOfferingInstructorResource($instructor))->resolve($request),
            'Course offering instructor assigned successfully',
            201
        );
    }

    public function update(
        UpdateCourseOfferingInstructorRequest $request,
        CourseOfferingInstructor $courseOfferingInstructor
    ): JsonResponse {
        $courseOfferingInstructor->loadMissing('courseOffering.course');
        $courseOffering = $courseOfferingInstructor->courseOffering;
        $this->teachingAssignments->assertCanManageAssignments($request->user(), $courseOffering);

        $validated = $request->validated();
        $facultyMember = array_key_exists('faculty_member_id', $validated)
            ? FacultyMember::query()->findOrFail((int) $validated['faculty_member_id'])
            : $courseOfferingInstructor->facultyMember()->firstOrFail();

        $isDeactivatingOnly = array_key_exists('is_active', $validated)
            && ! $validated['is_active']
            && ! array_key_exists('faculty_member_id', $validated);

        if (! $isDeactivatingOnly) {
            $this->teachingAssignments->assertValidAssignment(
                $courseOffering,
                $facultyMember,
                (string) $courseOfferingInstructor->instructor_role
            );
        }

        $instructor = DB::transaction(function () use ($courseOfferingInstructor, $courseOffering, $facultyMember, $validated): CourseOfferingInstructor {
            $isActive = array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : (bool) $courseOfferingInstructor->is_active;

            $courseOfferingInstructor->fill([
                'faculty_member_id' => $facultyMember->faculty_member_id,
                'is_active' => $isActive,
            ]);
            $courseOfferingInstructor->save();

            if ($isActive) {
                $this->teachingAssignments->ensureGenericCourseInstructor(
                    (int) $courseOffering->course_id,
                    (int) $facultyMember->faculty_member_id
                );
            }

            $this->teachingAssignments->normalizePrimaryFlags($courseOffering);
            $this->teachingAssignments->syncLegacyFacultyPointer($courseOffering->fresh());

            return $courseOfferingInstructor->fresh(['facultyMember']);
        });

        return $this->successResponse(
            (new CourseOfferingInstructorResource($instructor))->resolve($request)
        );
    }

    public function destroy(CourseOfferingInstructor $courseOfferingInstructor): JsonResponse
    {
        $courseOfferingInstructor->loadMissing('courseOffering');
        $courseOffering = $courseOfferingInstructor->courseOffering;
        $this->teachingAssignments->assertCanManageAssignments(request()->user(), $courseOffering);

        DB::transaction(function () use ($courseOfferingInstructor, $courseOffering): void {
            $courseOfferingInstructor->delete();
            $this->teachingAssignments->syncLegacyFacultyPointer($courseOffering->fresh());
        });

        return $this->successResponse([], 'Course offering instructor removed successfully');
    }

    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
