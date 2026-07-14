<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseOfferingInstructor\StoreCourseOfferingInstructorRequest;
use App\Http\Requests\CourseOfferingInstructor\UpdateCourseOfferingInstructorRequest;
use App\Http\Resources\CourseOfferingInstructorResource;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CourseOfferingInstructorController extends Controller
{
    public function index(CourseOffering $courseOffering): JsonResponse
    {
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
        $validated = $request->validated();
        $courseOfferingId = $courseOffering->course_offering_id;
        $facultyMemberId = (int) $validated['faculty_member_id'];

        $instructor = DB::transaction(function () use ($courseOffering, $courseOfferingId, $facultyMemberId, $validated): CourseOfferingInstructor {
            $existingCount = CourseOfferingInstructor::query()
                ->where('course_offering_id', $courseOfferingId)
                ->count();

            $isFirst = $existingCount === 0;
            $isPrimary = array_key_exists('is_primary', $validated)
                ? (bool) $validated['is_primary']
                : $isFirst;

            $instructor = CourseOfferingInstructor::query()->updateOrCreate(
                [
                    'course_offering_id' => $courseOfferingId,
                    'faculty_member_id' => $facultyMemberId,
                ],
                [
                    'instructor_role' => $validated['instructor_role'] ?? 'instructor',
                    'is_primary' => $isPrimary,
                    'is_active' => $validated['is_active'] ?? true,
                ]
            );

            if ($isPrimary) {
                $this->setPrimaryInstructor($courseOffering, $instructor);
            }

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
        $validated = $request->validated();

        $instructor = DB::transaction(function () use ($courseOfferingInstructor, $validated): CourseOfferingInstructor {
            $courseOfferingInstructor->fill($validated);
            $courseOfferingInstructor->save();

            if (array_key_exists('is_primary', $validated) && $validated['is_primary']) {
                $courseOfferingInstructor->loadMissing('courseOffering');
                $this->setPrimaryInstructor(
                    $courseOfferingInstructor->courseOffering,
                    $courseOfferingInstructor
                );
            }

            return $courseOfferingInstructor->fresh(['facultyMember']);
        });

        return $this->successResponse(
            (new CourseOfferingInstructorResource($instructor))->resolve($request)
        );
    }

    public function destroy(CourseOfferingInstructor $courseOfferingInstructor): JsonResponse
    {
        DB::transaction(function () use ($courseOfferingInstructor): void {
            $courseOffering = $courseOfferingInstructor->courseOffering;
            $wasPrimary = $courseOfferingInstructor->is_primary;

            $courseOfferingInstructor->delete();

            if (! $wasPrimary) {
                return;
            }

            $replacement = CourseOfferingInstructor::query()
                ->where('course_offering_id', $courseOffering->course_offering_id)
                ->where('is_active', true)
                ->orderBy('course_offering_instructor_id')
                ->first();

            if ($replacement) {
                $this->setPrimaryInstructor($courseOffering, $replacement);

                return;
            }

            $courseOffering->update(['faculty_member_id' => null]);
        });

        return $this->successResponse([], 'Course offering instructor removed successfully');
    }

    private function setPrimaryInstructor(
        CourseOffering $courseOffering,
        CourseOfferingInstructor $primaryInstructor
    ): void {
        CourseOfferingInstructor::query()
            ->where('course_offering_id', $courseOffering->course_offering_id)
            ->where('course_offering_instructor_id', '!=', $primaryInstructor->course_offering_instructor_id)
            ->update(['is_primary' => false]);

        if (! $primaryInstructor->is_primary) {
            $primaryInstructor->update(['is_primary' => true]);
        }

        $courseOffering->update([
            'faculty_member_id' => $primaryInstructor->faculty_member_id,
        ]);
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
