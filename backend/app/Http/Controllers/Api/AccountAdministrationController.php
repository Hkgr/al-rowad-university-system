<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountAdministration\AssignAccountRoleRequest;
use App\Http\Requests\AccountAdministration\StoreAccountRequest;
use App\Http\Requests\AccountAdministration\UpdateAccountStatusRequest;
use App\Models\User;
use App\Services\AccountAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Technical Office portal: accounts, role assignments and derived permissions. */
class AccountAdministrationController extends Controller
{
    public function __construct(private readonly AccountAdministrationService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'role_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return $this->ok($this->accounts->list($request->user(), $filters));
    }

    public function options(Request $request): JsonResponse
    {
        return $this->ok($this->accounts->options($request->user()));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return $this->ok($this->accounts->show($request->user(), $user));
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $user = $this->accounts->create($request->user(), $request->validated(), $request->ip());

        return $this->ok($this->accounts->show($request->user(), $user->fresh()), 'تم إنشاء الحساب بنجاح.', 201);
    }

    public function assignRole(AssignAccountRoleRequest $request, int $user): JsonResponse
    {
        $this->accounts->assignRole($request->user(), $user, (int) $request->validated('role_id'), $request->ip());

        return $this->ok($this->accounts->show($request->user(), User::query()->findOrFail($user)), 'تم إسناد الدور بنجاح.');
    }

    public function revokeRole(Request $request, int $user, int $role): JsonResponse
    {
        $this->accounts->revokeRole($request->user(), $user, $role, $request->ip());

        return $this->ok($this->accounts->show($request->user(), User::query()->findOrFail($user)), 'تم سحب الدور بنجاح.');
    }

    public function updateStatus(UpdateAccountStatusRequest $request, int $user): JsonResponse
    {
        $this->accounts->setStatus($request->user(), $user, (string) $request->validated('account_status'), $request->ip());

        return $this->ok($this->accounts->show($request->user(), User::query()->findOrFail($user)), 'تم تحديث حالة الحساب بنجاح.');
    }

    private function ok(array $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
