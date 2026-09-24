<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacultyMember;
use App\Services\AdministrativePersonnelService as Personnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdministrativePersonnelController extends Controller
{
    public function __construct(private Personnel $personnel) {}

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public function colleges(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::STAFF_VIEW);
        return $this->ok($this->personnel->colleges());
    }

    public function employees(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::STAFF_MANAGE);
        $filters = $request->validate(['search' => ['sometimes', 'string', 'max:120']]);
        return $this->ok($this->personnel->availableEmployees($filters));
    }

    public function faculty(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::STAFF_VIEW);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'], 'college_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->ok($this->personnel->faculty($filters));
    }

    public function saveFaculty(Request $request, ?FacultyMember $facultyMember = null): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::STAFF_MANAGE);
        // Existing employees and new employee records use distinct validated paths.
        $fields = $request->validate([
            'college_id' => ['required', 'integer', 'min:1'],
            'employee_id' => [$facultyMember ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'min:1'],
            'employee_number' => [$facultyMember ? 'prohibited' : 'required_without:employee_id', 'string', 'max:50', Rule::unique('employees', 'employee_number')],
            'first_name' => [$facultyMember ? 'sometimes' : 'required_without:employee_id', 'string', 'max:100'],
            'last_name' => [$facultyMember ? 'sometimes' : 'required_without:employee_id', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'academic_rank' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:200'],
            'office_location' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);
        return $this->ok($this->personnel->saveFaculty($request->user(), $fields, $facultyMember, $request->ip()), $facultyMember ? 200 : 201);
    }

    public function deans(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::DEANS_VIEW);
        return $this->ok($this->personnel->deans());
    }

    public function deanCandidates(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::DEANS_MANAGE);
        $filters = $request->validate(['search' => ['sometimes', 'string', 'max:120']]);
        return $this->ok($this->personnel->deanCandidates($filters));
    }

    public function appointDean(Request $request): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::DEANS_MANAGE);
        $fields = $request->validate([
            'college_id' => ['required', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'required_with:user_id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'employee_number' => ['required_without:employee_id', 'string', 'max:50', Rule::unique('employees', 'employee_number')],
            'first_name' => ['required_without:employee_id', 'string', 'max:100'],
            'last_name' => ['required_without:employee_id', 'string', 'max:100'],
            'username' => ['required_without:user_id', 'string', 'max:100', Rule::unique('users', 'username')],
            'email' => ['required_without:user_id', 'nullable', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required_without:user_id', 'string', 'min:12', 'max:255'],
            'password_hash' => ['prohibited'],
            'role_ids' => ['prohibited'], 'access_scopes' => ['prohibited'], 'account_status_id' => ['prohibited'],
        ]);
        return $this->ok($this->personnel->appointDean($request->user(), $fields, $request->ip()), 201);
    }

    public function retireDean(Request $request, int $user, int $college): JsonResponse
    {
        $this->personnel->authorize($request->user(), Personnel::DEANS_MANAGE);
        $this->personnel->retireDean($request->user(), $user, $college, $request->ip());
        return $this->ok(['retired' => true]);
    }
}
