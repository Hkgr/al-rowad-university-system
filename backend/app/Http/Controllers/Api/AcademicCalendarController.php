<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AcademicCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicCalendarController extends Controller
{
    public function __construct(private AcademicCalendarService $calendar)
    {
    }

    public function catalog(): JsonResponse
    {
        return $this->success($this->calendar->catalog());
    }

    public function events(Request $request): JsonResponse
    {
        $filters = $request->validate($this->filterRules());
        return $this->success($this->calendar->publicEvents($filters));
    }

    private function filterRules(): array
    {
        return [
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
            'academic_calendar_event_type_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }

    private function success(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Operation completed successfully', 'data' => $data]);
    }
}
