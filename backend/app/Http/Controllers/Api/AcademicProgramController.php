<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AcademicProgram\StoreAcademicProgramRequest;
use App\Http\Requests\AcademicProgram\UpdateAcademicProgramRequest;
use App\Http\Resources\AcademicProgramResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\ProgramCourseResource;
use App\Http\Resources\StudentResource;
use App\Models\AcademicProgram;
use App\Services\DataScopeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicProgramController extends ApiController
{
    protected function modelClass(): string
    {
        return AcademicProgram::class;
    }

    protected function resourceClass(): string
    {
        return AcademicProgramResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAcademicProgramRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAcademicProgramRequest::class;
    }

    public function students(AcademicProgram $academicProgram): JsonResponse
    {
        Gate::authorize('viewAny', \App\Models\Student::class);
        abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), $academicProgram->academic_program_id), 403);
        $students = app(DataScopeService::class)->scopeStudents($academicProgram->students(), request()->user())
            ->with(['currentAcademicLevel', 'studentStatus'])
            ->orderBy('student_number')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentResource::collection($students)->response(request())->getData(true)
        );
    }

    public function courses(AcademicProgram $academicProgram): JsonResponse
    {
        abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), $academicProgram->academic_program_id), 403);
        $courses = app(DataScopeService::class)->scopeCourses($academicProgram->courses(), request()->user())
            ->orderBy('course_code')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            CourseResource::collection($courses)->response(request())->getData(true)
        );
    }

    public function mandatoryCourses(int $id): JsonResponse
    {
        abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), $id), 403);
        $program = AcademicProgram::query()->with([
            'programCourses' => fn ($query) => app(DataScopeService::class)->scopeResourceQuery($query, request()->user()),
            'programCourses.course',
            'programCourses.academicLevel',
            'programCourses.recommendedSemester',
            'programCourses.academicProgram.department',
        ])->findOrFail($id);

        $courses = $program->programCourses
            ->whereIn('course_type', ['mandatory', 'required'])
            ->values();

        return $this->successResponse(ProgramCourseResource::collection($courses));
    }

    public function electiveCourses(int $id): JsonResponse
    {
        abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), $id), 403);
        $program = AcademicProgram::query()->with([
            'programCourses' => fn ($query) => app(DataScopeService::class)->scopeResourceQuery($query, request()->user()),
            'programCourses.course',
            'programCourses.academicLevel',
            'programCourses.recommendedSemester',
            'programCourses.academicProgram.department',
        ])->findOrFail($id);

        $courses = $program->programCourses
            ->where('course_type', 'elective')
            ->values();

        return $this->successResponse(ProgramCourseResource::collection($courses));
    }

    public function studyPlan(int $id): JsonResponse
    {
        abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), $id), 403);
        $program = AcademicProgram::query()->with([
            'programCourses' => fn ($query) => app(DataScopeService::class)->scopeResourceQuery($query, request()->user()),
            'programCourses.course',
            'programCourses.academicLevel',
            'programCourses.recommendedSemester',
        ])->findOrFail($id);

        $studyPlan = $program->programCourses
            ->sortBy([
                ['academic_level_id', 'asc'],
                ['recommended_semester_id', 'asc'],
                ['course.course_code', 'asc'],
            ])
            ->groupBy(fn ($programCourse) => $programCourse->academic_level_id.'-'.$programCourse->recommended_semester_id)
            ->map(function ($items) {
                $firstItem = $items->first();

                return [
                    'academic_level' => $firstItem?->relationLoaded('academicLevel') ? [
                        'academic_level_id' => $firstItem->academicLevel->academic_level_id,
                        'level_code' => $firstItem->academicLevel->level_code,
                        'level_name' => $firstItem->academicLevel->level_name,
                    ] : null,
                    'semester' => $firstItem?->relationLoaded('recommendedSemester') ? [
                        'semester_id' => $firstItem->recommendedSemester->semester_id,
                        'semester_code' => $firstItem->recommendedSemester->semester_code,
                        'semester_name' => $firstItem->recommendedSemester->semester_name,
                    ] : null,
                    'courses' => $items->map(static function ($programCourse) {
                        return [
                            'course_id' => $programCourse->course_id,
                            'course_code' => $programCourse->course?->course_code,
                            'course_name' => $programCourse->course?->course_name,
                            'credit_hours' => $programCourse->course?->credit_hours,
                            'course_type' => $programCourse->course_type,
                        ];
                    })->values(),
                ];
            })->values();

        return $this->successResponse($studyPlan);
    }
}
