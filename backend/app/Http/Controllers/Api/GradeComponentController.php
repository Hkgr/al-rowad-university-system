<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GradeException;
use App\Http\Requests\GradeComponent\StoreGradeComponentRequest;
use App\Http\Requests\GradeComponent\UpdateGradeComponentRequest;
use App\Http\Resources\GradeComponentResource;
use App\Models\GradeComponent;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class GradeComponentController extends ApiController
{
    public function store(): JsonResponse
    {
        return \App\Services\AcademicPlanContext::transaction(fn (): JsonResponse => parent::store());
    }

    public function update($id): JsonResponse
    {
        return \App\Services\AcademicPlanContext::transaction(fn (): JsonResponse => parent::update($id));
    }

    public function destroy($id): JsonResponse
    {
        return \App\Services\AcademicPlanContext::transaction(fn (): JsonResponse => parent::destroy($id));
    }

    protected function beforeStoreMutation(array $payload): void
    {
        SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable([
            (int) $payload['course_offering_id'],
        ]);
        \App\Support\IncompleteOutcomeGuard::assertConfigurationMutable([(int) $payload['course_offering_id']]);
    }

    protected function beforeUpdateMutation(GradeComponent $component, array $payload): void
    {
        $currentOfferingId = GradeComponent::query()->whereKey($component->getKey())->value('course_offering_id');
        $offeringIds = collect([
            (int) $component->course_offering_id,
            (int) $currentOfferingId,
            (int) ($payload['course_offering_id'] ?? $component->course_offering_id),
        ])->filter()->unique()->values();
        SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable($offeringIds);
        if (array_intersect(array_keys($payload), ['course_offering_id', 'component_type', 'is_required', 'max_mark', 'weight_percentage'])) \App\Support\IncompleteOutcomeGuard::assertConfigurationMutable($offeringIds);
        $locked = GradeComponent::query()->whereKey($component->getKey())->lockForUpdate()->first();
        if (! $locked || ! $offeringIds->contains((int) $locked->course_offering_id)) {
            throw new GradeException(
                'The grade component changed while its configuration was being locked.',
                status: 409,
                errorCode: SupplementaryExamTargetGuard::CONFIGURATION_ERROR_CODE,
            );
        }
    }

    protected function beforeDestroyMutation(GradeComponent $component): void
    {
        $currentOfferingId = GradeComponent::query()->whereKey($component->getKey())->value('course_offering_id');
        $offeringIds = collect([
            (int) $component->course_offering_id,
            (int) $currentOfferingId,
        ])->filter()->unique()->values();
        SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable($offeringIds);
        \App\Support\IncompleteOutcomeGuard::assertConfigurationMutable($offeringIds);
        $locked = GradeComponent::query()->whereKey($component->getKey())->lockForUpdate()->first();
        if (! $locked || ! $offeringIds->contains((int) $locked->course_offering_id)) {
            throw new GradeException(
                'The grade component changed while its configuration was being locked.',
                status: 409,
                errorCode: SupplementaryExamTargetGuard::CONFIGURATION_ERROR_CODE,
            );
        }
    }

    protected function modelClass(): string
    {
        return GradeComponent::class;
    }

    protected function resourceClass(): string
    {
        return GradeComponentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeComponentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeComponentRequest::class;
    }
}
