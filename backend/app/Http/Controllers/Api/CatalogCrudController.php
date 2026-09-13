<?php

namespace App\Http\Controllers\Api;

use App\Services\AcademicCatalogTransaction;
use App\Services\AcademicCatalogHistory;
use App\Exceptions\AcademicCatalogException;
use App\Models\{AcademicProgram, Course, CourseDepartment, CoursePrerequisite, ProgramCourse};
use Illuminate\Http\JsonResponse;

/** Keep existing CRUD authorization/payloads; share serialization with the new writer.
 * History locks and revision advancement are database invariants, not controller hooks.
 */
abstract class CatalogCrudController extends ApiController
{
    protected function beforeStoreMutation(array $payload): void
    {
        $this->assertHistoricalChange(new ($this->modelClass())($payload), $payload, true);
    }

    protected function beforeUpdateMutation($model, array $payload): void
    {
        // Authenticated by HandlesApiCrud before this hook; preserve existing authority.
        $model = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
        $this->assertHistoricalChange($model, $payload);
    }

    protected function beforeDestroyMutation($model): void
    {
        $this->assertHistoricalChange($model, [], false, true);
    }

    private function assertHistoricalChange($model, array $payload, bool $create = false, bool $delete = false): void
    {
        $history = app(AcademicCatalogHistory::class);
        $copy = clone $model;
        $copy->fill($payload);
        $fields = array_keys($copy->getDirty());
        $blocked = match (true) {
            $model instanceof Course => !$create && ($delete || array_diff($fields, ['course_name', 'description'])) && $history->courseUsed((int) $model->getKey()),
            $model instanceof AcademicProgram => !$create && ($delete || array_diff($fields, ['program_name', 'description'])) && $history->programUsed((int) $model->getKey()),
            $model instanceof ProgramCourse => $history->programUsed((int) $model->academic_program_id) || $history->programUsed((int) $copy->academic_program_id),
            $model instanceof CourseDepartment, $model instanceof CoursePrerequisite => $history->courseUsed((int) $model->course_id) || $history->courseUsed((int) $copy->course_id),
            default => false,
        };
        if ($blocked) throw new AcademicCatalogException('البيانات مستخدمة أكاديميًا؛ يسمح بالتصحيح النصي فقط.', 'academic_catalog_history_locked');
    }

    public function store(): JsonResponse
    {
        return app(AcademicCatalogTransaction::class)->run(function () {
            $response = parent::store();
            if ($this->modelClass() === \App\Models\CoursePrerequisite::class) app(AcademicCatalogTransaction::class)->assertAcyclic();
            return $response;
        });
    }

    public function update($id): JsonResponse
    {
        return app(AcademicCatalogTransaction::class)->run(function () use ($id) {
            // Existing generic FormRequests use ignoreModel() on these route parameters.
            // Bind only the already scoped record; keep the original public CRUD payload.
            $model = app(\App\Services\DataScopeService::class)->scopeResourceQuery($this->modelClass()::query(), request()->user())
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            request()->route()?->setParameter(\Illuminate\Support\Str::snake(class_basename($model)), $model);
            $response = parent::update($id);
            if ($this->modelClass() === \App\Models\CoursePrerequisite::class) app(AcademicCatalogTransaction::class)->assertAcyclic();
            return $response;
        });
    }

    public function destroy($id): JsonResponse
    {
        return app(AcademicCatalogTransaction::class)->run(fn () => parent::destroy($id));
    }
}
