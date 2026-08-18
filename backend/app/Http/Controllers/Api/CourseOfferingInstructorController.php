<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TeachingAssignmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseOfferingInstructor\StoreCourseOfferingInstructorRequest;
use App\Http\Requests\CourseOfferingInstructor\UpdateCourseOfferingInstructorRequest;
use App\Http\Resources\CourseOfferingInstructorResource;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Services\TeachingAssignmentService;
use Illuminate\Http\JsonResponse;

/**
 * Effective-assignment read API.
 * POST/PATCH/DELETE are retained only to return a workflow-required conflict.
 * They must not write course_offering_instructors for any caller, including Super Admin.
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
        throw TeachingAssignmentException::workflowRequired();
    }

    public function update(
        UpdateCourseOfferingInstructorRequest $request,
        CourseOfferingInstructor $courseOfferingInstructor
    ): JsonResponse {
        throw TeachingAssignmentException::workflowRequired();
    }

    public function destroy(CourseOfferingInstructor $courseOfferingInstructor): JsonResponse
    {
        throw TeachingAssignmentException::workflowRequired();
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
