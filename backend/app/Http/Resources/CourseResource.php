<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\CourseController;
use App\Support\CourseRequirementClassification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    public static function collection($resource)
    {
        $user = request()->user();
        if ($user !== null) {
            CourseRequirementClassification::hydrateCoursesForUser(
                CourseRequirementClassification::modelsFromResource($resource),
                $user
            );
        }

        return tap(new AnonymousResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    public function toArray(Request $request): array
    {
        $this->hydrateClassification($request);

        return [
            'course_id' => $this->course_id,
            'course_code' => $this->course_code,
            'course_name' => $this->course_name,
            'credit_hours' => $this->credit_hours,
            'theoretical_hours' => $this->theoretical_hours,
            'practical_hours' => $this->practical_hours,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'program_requirement_classifications' => $this->when(
                $this->relationLoaded('programCourses')
                    && $request->user() !== null
                    && $this->resource->getAttribute('_program_courses_scoped_for_user_id') === $request->user()->getAuthIdentifier(),
                CourseRequirementClassification::programClassificationsForCourse($this->resource)
            ),
            'departments' => $this->relationLoaded('departments') ? DepartmentResource::collection($this->departments) : null,
            'academic_programs' => $this->relationLoaded('academicPrograms') ? AcademicProgramResource::collection($this->academicPrograms) : null,
            'prerequisites' => $this->relationLoaded('prerequisites') ? CourseResource::collection($this->prerequisites) : null,
            'course_offerings' => $this->relationLoaded('courseOfferings') ? CourseOfferingResource::collection($this->courseOfferings) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Hydrate classification for a single Course without duplicating CRUD authorization.
     *
     * Query-free when programCourses is already scoped for this user (including collection() batching).
     * Nested CourseResource::make() inside offerings does not auto-load programCourses.
     */
    private function hydrateClassification(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }

        if ($this->relationLoaded('programCourses')) {
            CourseRequirementClassification::hydrateCoursesForUser([$this->resource], $user);

            return;
        }

        $controller = $request->route()?->getController();
        $action = $request->route()?->getActionMethod();

        if (
            $controller instanceof CourseController
            && in_array($action, ['index', 'show', 'store', 'update'], true)
        ) {
            CourseRequirementClassification::hydrateCoursesForUser([$this->resource], $user);
        }
    }
}
