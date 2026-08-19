<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use App\Services\ResourceAuthorizationService;
use App\Services\DataScopeService;

trait HandlesApiCrud
{
    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    abstract protected function storeRequestClass(): string;

    abstract protected function updateRequestClass(): string;

    public function index(): JsonResponse
    {
        $this->authorizeIfPolicyExists('viewAny', $this->modelClass());
        $perPage = \App\Support\AcademicQueuePagination::perPage(
            request()->has('per_page') ? request()->integer('per_page') : null,
            15
        );
        $models = app(DataScopeService::class)->scopeResourceQuery($this->modelClass()::query(), request()->user())
            ->paginate($perPage);

        $payload = $this->resourceClass()::collection($models)
            ->response(request())
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function store(): JsonResponse
    {
        $this->authorizeIfPolicyExists('create', $this->modelClass());
        /** @var FormRequest $request */
        $request = app($this->storeRequestClass());
        app(DataScopeService::class)->assertPayloadScope(request()->user(), $request->validated());

        $modelClass = $this->modelClass();

        $model = $modelClass::query()->create($request->validated());

        $resourceClass = $this->resourceClass();

        $payload = (new $resourceClass($model))->resolve(request());

        return $this->successResponse(
            $payload,
            'Operation completed successfully',
            201
        );
    }

    public function show($id): JsonResponse
    {
        $modelClass = $this->modelClass();

        $model = app(DataScopeService::class)->scopeResourceQuery($modelClass::query(), request()->user())->findOrFail($id);
        $this->authorizeIfPolicyExists('view', $model);

        $resourceClass = $this->resourceClass();

        $payload = (new $resourceClass($model))->resolve(request());

        return $this->successResponse($payload);
    }

    public function update($id): JsonResponse
    {
        /** @var FormRequest $request */
        $request = app($this->updateRequestClass());

        $modelClass = $this->modelClass();

        $model = app(DataScopeService::class)->scopeResourceQuery($modelClass::query(), request()->user())->findOrFail($id);
        $this->authorizeIfPolicyExists('update', $model);
        app(DataScopeService::class)->assertPayloadScope(request()->user(), $request->validated());

        $model->update($request->validated());

        $resourceClass = $this->resourceClass();

        $payload = (new $resourceClass($model->fresh()))->resolve(request());

        return $this->successResponse($payload);
    }

    public function destroy($id): JsonResponse
    {
        $modelClass = $this->modelClass();

        $model = app(DataScopeService::class)->scopeResourceQuery($modelClass::query(), request()->user())->findOrFail($id);
        $this->authorizeIfPolicyExists('delete', $model);

        $model->delete();

        return $this->successResponse(
            [],
            'Operation completed successfully'
        );
    }

    private function authorizeIfPolicyExists(string $ability, object|string $target): void
    {
        $modelClass = is_string($target) ? $target : $target::class;
        if (Gate::getPolicyFor($modelClass) !== null) {
            Gate::authorize($ability, $target);
            return;
        }

        app(ResourceAuthorizationService::class)->authorize(
            request()->user(),
            $modelClass,
            in_array($ability, ['create', 'update', 'delete', 'restore', 'forceDelete'], true)
        );
    }
}
