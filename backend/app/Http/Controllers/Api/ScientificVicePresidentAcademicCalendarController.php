<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventVersion;
use App\Models\AcademicYear;
use App\Services\AcademicCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScientificVicePresidentAcademicCalendarController extends Controller
{
    public function __construct(private AcademicCalendarService $calendar)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        return $this->success($this->calendar->catalog($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
            'academic_calendar_event_type_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->success($this->calendar->managementEvents($request->user(), $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->draftRules(true));
        return $this->success($this->calendar->createDraft($request->user(), $data), 201);
    }

    public function updateDraft(Request $request, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): JsonResponse
    {
        $data = $request->validate($this->draftRules(false));
        return $this->success($this->calendar->editDraft($request->user(), $event, $version, $data));
    }

    public function replacementDraft(Request $request, AcademicCalendarEvent $event): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'], 'public_notes' => ['sometimes', 'nullable', 'string'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'is_enforcement' => ['sometimes', 'boolean'], 'change_reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ]);
        if (isset($data['starts_at'], $data['ends_at']) && strtotime($data['ends_at']) < strtotime($data['starts_at'])) {
            abort(422, 'ends_at must be after or equal to starts_at');
        }
        return $this->success($this->calendar->createReplacementDraft($request->user(), $event, $data), 201);
    }

    public function publish(Request $request, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): JsonResponse
    {
        return $this->success($this->calendar->publish($request->user(), $event, $version));
    }

    public function destroyDraft(Request $request, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): JsonResponse
    {
        $this->calendar->deleteDraft($request->user(), $event, $version);
        return $this->success([]);
    }

    public function cancel(Request $request, AcademicCalendarEvent $event): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u']]);
        return $this->success($this->calendar->cancel($request->user(), $event, $data['cancellation_reason']));
    }

    public function history(Request $request, AcademicCalendarEvent $event): JsonResponse
    {
        return $this->success($this->calendar->history($request->user(), $event));
    }

    public function activateYear(Request $request, AcademicYear $year): JsonResponse
    {
        return $this->yearAction($request, $year, 'activate');
    }

    public function reopenYear(Request $request, AcademicYear $year): JsonResponse
    {
        return $this->yearAction($request, $year, 'reopen');
    }

    public function closeYear(Request $request, AcademicYear $year): JsonResponse
    {
        return $this->yearAction($request, $year, 'close');
    }

    private function yearAction(Request $request, AcademicYear $year, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/u']]);
        return $this->success($this->calendar->transitionYear($request->user(), $year, $action, $data['reason']));
    }

    private function draftRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';
        return [
            'academic_year_id' => [$presence, 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'academic_calendar_event_type_id' => [$presence, 'integer', 'min:1'],
            'title' => [$presence, 'string', 'max:255'],
            'public_notes' => ['sometimes', 'nullable', 'string'],
            'starts_at' => [$presence, 'date'],
            'ends_at' => [$presence, 'date', 'after_or_equal:starts_at'],
            'is_enforcement' => [$presence, 'boolean'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    private function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Operation completed successfully', 'data' => $data], $status);
    }
}
