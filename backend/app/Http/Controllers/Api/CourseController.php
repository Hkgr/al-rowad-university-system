<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\AcademicProgramResource;
use App\Http\Resources\CourseInstructorResource;
use App\Http\Resources\CoursePrerequisiteResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\ProgramCourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;

class CourseController extends ApiController
{
    protected function modelClass(): string
    {
        return Course::class;
    }

    protected function resourceClass(): string
    {
        return CourseResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCourseRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCourseRequest::class;
    }

    public function departments(int $id): JsonResponse
    {
        $course = Course::query()->with('departments')->findOrFail($id);

        return $this->successResponse(DepartmentResource::collection($course->departments));
    }

    public function programs(int $id): JsonResponse
    {
        $course = Course::query()->with([
            'programCourses.academicProgram.department',
            'programCourses.academicLevel',
            'programCourses.recommendedSemester',
            'programCourses.course',
        ])->findOrFail($id);

        return $this->successResponse(ProgramCourseResource::collection($course->programCourses));
    }

    public function prerequisites(int $id): JsonResponse
    {
        $course = Course::query()->with([
            'coursePrerequisiteRecords.prerequisiteCourse',
            'coursePrerequisiteRecords.course',
            'coursePrerequisiteRecords.minimumResultStatus',
        ])->findOrFail($id);

        return $this->successResponse(CoursePrerequisiteResource::collection($course->coursePrerequisiteRecords));
    }

    public function instructors(int $id): JsonResponse
    {
        $course = Course::query()->with([
            'courseInstructors.facultyMember.employee',
        ])->findOrFail($id);

        return $this->successResponse(CourseInstructorResource::collection($course->courseInstructors));
    }
}
