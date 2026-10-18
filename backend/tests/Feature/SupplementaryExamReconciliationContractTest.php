<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamReconciliationContractTest extends TestCase
{
    #[Test]
    public function reconciliation_route_is_get_only_and_uses_the_read_only_controller(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        $path = 'exams/supplementary-periods/{period}/reconciliation';

        $this->assertStringContainsString(
            "Route::get('{$path}', [SupplementaryExamReconciliationController::class, 'show'])",
            $routes,
        );
        $this->assertStringNotContainsString("Route::post('{$path}'", $routes);
        $this->assertStringNotContainsString("Route::put('{$path}'", $routes);
        $this->assertStringNotContainsString("Route::patch('{$path}'", $routes);
        $this->assertStringNotContainsString("Route::delete('{$path}'", $routes);

        $controller = file_get_contents(app_path('Http/Controllers/Api/SupplementaryExamReconciliationController.php'));
        $this->assertStringContainsString('$service->reconcile($request->user(), $period)', $controller);
    }

    #[Test]
    public function service_is_fail_closed_scoped_batched_and_has_no_write_primitive(): void
    {
        $service = file_get_contents(app_path('Services/SupplementaryExamReconciliationService.php'));

        foreach ([
            'isExamOfficer()',
            'effectivePermissions()->contains(GradingGovernance::REVIEW)',
            'scopes($actor)',
            'mutableProgramIds(',
            'supplementary_reconciliation_forbidden',
            'supplementary_reconciliation_out_of_scope',
            'scope_complete',
            "'state' => \$this->state(\$issues)",
            "'roster' =>",
            "'graded' =>",
            "'published' =>",
            "'materialized' =>",
        ] as $proof) {
            $this->assertStringContainsString($proof, $service);
        }
        $this->assertStringContainsString('DB::transaction(', $service);
        $this->assertStringContainsString('One repeatable-read snapshot', $service);

        foreach (['lockForUpdate(', '->insert(', '->update(', '->delete(', '->save(', '::create('] as $write) {
            $this->assertStringNotContainsString($write, $service);
        }
        $this->assertStringNotContainsString('hasPermission(', $service);
    }

    #[Test]
    public function reconciliation_exposes_stable_source_target_component_and_terminal_issue_codes(): void
    {
        $service = file_get_contents(app_path('Services/SupplementaryExamReconciliationService.php'));

        foreach ([
            'roster_result_mismatch',
            'registration_state_mismatch',
            'duplicate_roster_target',
            'source_submission_drift',
            'source_result_drift',
            'source_publication_event_mismatch',
            'published_result_not_materialized',
            'regular_attempt_already_materialized',
            'duplicate_materialization_provenance',
            'materialization_source_mismatch',
            'materialization_event_mismatch',
            'materialization_outside_current_roster',
            'offering_source_target_mismatch',
            'offering_source_relationship_mismatch',
            'official_target_drift',
            'theoretical_component_drift',
            'practical_component_drift',
            'coursework_total_drift',
            'attendance_deprivation_drift',
            'terminal_coverage_incomplete',
            'terminal_event_mismatch',
            'terminal_event_before_transition',
        ] as $code) {
            $this->assertStringContainsString($code, $service);
        }
    }
}
