<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountAdministration\AssignAccountRoleRequest;
use App\Http\Requests\AccountAdministration\CorrectAccountHolderNameRequest;
use App\Http\Requests\AccountAdministration\ResetAccountPasswordRequest;
use App\Http\Requests\AccountAdministration\UpdateAccountLoginRequest;
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

    public function updateLogin(UpdateAccountLoginRequest $request, int $user): JsonResponse
    {
        $this->accounts->updateLoginIdentity($request->user(), $user, $request->safe()->only(['username', 'email']), $request->ip());

        return $this->ok($this->accounts->show($request->user(), User::query()->findOrFail($user)), 'تم تحديث بيانات الدخول.');
    }

    public function resetPassword(ResetAccountPasswordRequest $request, int $user): JsonResponse
    {
        $revoked = $this->accounts->resetPassword($request->user(), $user, (string) $request->validated('password'), $request->ip());

        return $this->ok(
            $this->accounts->show($request->user(), User::query()->findOrFail($user)) + ['sessions_ended' => $revoked],
            'تم تعيين كلمة مرور جديدة وإنهاء جلسات الحساب. سلّم الكلمة لصاحبها بقناة آمنة؛ لا تُعرض مرة أخرى.'
        );
    }

    public function correctHolderName(CorrectAccountHolderNameRequest $request, int $user): JsonResponse
    {
        $validated = $request->validated();
        $this->accounts->correctHolderName($request->user(), $user, $validated['person_type'], array_intersect_key($validated, array_flip(['first_name', 'last_name', 'father_name', 'mother_name'])), $request->ip());

        return $this->ok($this->accounts->show($request->user(), User::query()->findOrFail($user)), 'تم تصحيح اسم صاحب الحساب في سجله المرتبط.');
    }

    private function ok(array $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
