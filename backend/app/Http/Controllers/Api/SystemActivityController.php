<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Read-only activity feed of the Technical Office (system_activity.view). No write routes exist. */
class SystemActivityController extends Controller
{
    public function __construct(private readonly SystemActivityService $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'module' => ['sometimes', 'nullable', 'string', 'max:80'],
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
            'actor' => ['sometimes', 'nullable', 'string', 'max:80'],
            'source' => ['sometimes', 'nullable', Rule::in(['activity', 'login'])],
            'target_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'date_format' => 'صيغة التاريخ يجب أن تكون YYYY-MM-DD.',
            'max' => 'القيمة أطول من المسموح.',
            'in' => 'القيمة غير مسموحة.',
        ]);

        return $this->ok($this->activity->list($request->user(), array_filter($filters, fn ($v) => $v !== null && $v !== '')));
    }

    public function options(Request $request): JsonResponse
    {
        return $this->ok($this->activity->options($request->user()));
    }

    public function show(Request $request, string $source, int $id): JsonResponse
    {
        $event = $this->activity->show($request->user(), $source, $id);
        if ($event === null) {
            return response()->json(['success' => false, 'message' => 'الحدث غير موجود أو خارج نطاق ما يمكنك عرضه.', 'error_code' => 'activity_not_found', 'errors' => []], 404);
        }

        return $this->ok($event);
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Operation completed successfully', 'data' => $data]);
    }
}
