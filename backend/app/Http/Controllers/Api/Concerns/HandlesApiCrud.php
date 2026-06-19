<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

trait HandlesApiCrud
{
    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    abstract protected function storeRequestClass(): string;

    abstract protected function updateRequestClass(): string;

    public function index(): JsonResponse
    {
        $models = $this->modelClass()::query()
            ->paginate(request()->integer('per_page', 15));

        $payload = $this->resourceClass()::collection($models)
            ->response(request())
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function store(): JsonResponse
    {
        /** @var FormRequest $request */
        $request = app($this->storeRequestClass());

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

        $model = $modelClass::query()->findOrFail($id);

        $resourceClass = $this->resourceClass();

        $payload = (new $resourceClass($model))->resolve(request());

        return $this->successResponse($payload);
    }

    public function update($id): JsonResponse
    {
        /** @var FormRequest $request */
        $request = app($this->updateRequestClass());

        $modelClass = $this->modelClass();

        $model = $modelClass::query()->findOrFail($id);

        $model->update($request->validated());

        $resourceClass = $this->resourceClass();

        $payload = (new $resourceClass($model->fresh()))->resolve(request());

        return $this->successResponse($payload);
    }

    public function destroy($id): JsonResponse
    {
        $modelClass = $this->modelClass();

        $model = $modelClass::query()->findOrFail($id);

        $model->delete();

        return $this->successResponse(
            [],
            'Operation completed successfully'
        );
    }
}