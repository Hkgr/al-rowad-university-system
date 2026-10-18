<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ActionApiController;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\MinistryPlacementApplicantConversionController;
use App\Http\Controllers\Api\MinistryPlacementController;
use App\Http\Controllers\Api\MinistryPlacementReconciliationController;
use App\Http\Controllers\Api\MinistryPlacementStudentEnrollmentController;
use ReflectionClass;
use Tests\TestCase;

class MinistryPlacementProductionHotfixContractTest extends TestCase
{
    public function test_dependency_free_hotfix_contract(): void
    {
        $contract = require base_path('tests/Contracts/ministry_placement_production_hotfix_contract.php');
        self::assertSame([], $contract(base_path()));
    }

    public function test_ministry_workflow_controllers_autoload_without_the_crud_contract(): void
    {
        foreach ([
            MinistryPlacementController::class,
            MinistryPlacementApplicantConversionController::class,
            MinistryPlacementStudentEnrollmentController::class,
            MinistryPlacementReconciliationController::class,
        ] as $controller) {
            self::assertTrue(is_subclass_of($controller, ActionApiController::class));
            self::assertFalse(is_subclass_of($controller, ApiController::class));
            if ($controller === MinistryPlacementController::class || $controller === MinistryPlacementReconciliationController::class) {
                self::assertSame($controller, (new ReflectionClass($controller))->getMethod('index')->getDeclaringClass()->getName());
            }
        }
    }
}
