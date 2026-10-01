<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{MailcowReadService, UniversityEmailService};
use App\Support\UniversityEmailAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;

final class UniversityEmailController extends Controller
{
    public function index(Request $request, UniversityEmailService $service): JsonResponse
    {
        UniversityEmailAccess::authorize($request->user(), UniversityEmailAccess::VIEW);
        $this->keys($request->query(), ['q', 'page', 'per_page']);
        $input = $request->validate(['q' => 'sometimes|nullable|string|max:120', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return response()->json($service->search($request->user(), $input));
    }
    public function show(Request $request, int $student, UniversityEmailService $service): JsonResponse
    {
        $this->keys($request->query(), []);
        return response()->json(['data' => $service->show($request->user(), $student)]);
    }
    public function save(Request $request, int $student, UniversityEmailService $service): JsonResponse
    {
        UniversityEmailAccess::authorize($request->user(), UniversityEmailAccess::MANAGE);
        $this->keys($request->all(), ['english_first_name', 'revision']);
        $input = $request->validate(['english_first_name' => 'required|string|max:64', 'revision' => 'required|integer|min:0|max:4294967294']);
        $input['revision'] = (int) $input['revision'];
        return response()->json(['data' => $service->save($request->user(), $student, $input)]);
    }
    public function check(Request $request, MailcowReadService $service): JsonResponse
    {
        UniversityEmailAccess::authorize($request->user(), UniversityEmailAccess::CHECK);
        $this->keys($request->query(), []);
        return response()->json(['data' => $service->checkDomain()]);
    }
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) throw ValidationException::withMessages(['request' => 'توجد حقول غير مسموحة في الطلب.']);
    }
}
