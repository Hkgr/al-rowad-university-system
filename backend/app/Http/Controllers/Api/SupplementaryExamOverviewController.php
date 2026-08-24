<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupplementaryExamOverviewService;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementaryExamOverviewController extends Controller
{
    public function __invoke(Request $request, SupplementaryExamOverviewService $overview): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)
                || $actor->hasRoleCode('super_admin'),
            403,
        );
        $validated = $request->validate([
            'period_id' => ['sometimes', 'integer', 'min:1'],
            'offering_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $overview->overview(
                $actor,
                isset($validated['period_id']) ? (int) $validated['period_id'] : null,
                isset($validated['offering_id']) ? (int) $validated['offering_id'] : null,
                $validated['search'] ?? null,
                isset($validated['per_page']) ? (int) $validated['per_page'] : null,
            ),
        ]);
    }
}
