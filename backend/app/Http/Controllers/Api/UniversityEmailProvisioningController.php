<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UniversityEmailProvisioningService as Service;
use App\Support\UniversityEmailAccess as Access;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;

final class UniversityEmailProvisioningController extends Controller
{
    private function input(Request $request, array $rules, ?string $permission = null): array
    {
        if ($permission) Access::authorize($request->user(), $permission);
        if (array_diff(array_keys($request->all()), array_keys($rules))) throw ValidationException::withMessages(['request' => 'توجد حقول غير مسموحة.']);
        return $request->validate($rules);
    }
    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']);
    }
    public function state(Request $r, int $student, Service $s): JsonResponse
    {
        $this->input($r, [], Access::VIEW);
        return $this->response($s->state($r->user(), $student));
    }
    public function create(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['english_first_name' => 'required|string|max:64', 'confirmed' => 'required|accepted'], Access::CREATE);
        return $this->response($s->create($r->user(), $student, $i['english_first_name']));
    }
    public function password(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['revision' => 'required|integer|min:1'], Access::CREATE);
        return $this->response($s->password($r->user(), $student, (int) $i['revision'], 'create'));
    }
    public function reissue(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['revision' => 'required|integer|min:1'], Access::RECOVER);
        return $this->response($s->password($r->user(), $student, (int) $i['revision'], 'reset'));
    }
    public function execute(Request $r, int $student, Service $s): JsonResponse
    {
        try {
            $i = $this->input($r, ['operation_id' => 'required|uuid', 'generation' => 'required|integer|min:1',
                'password' => 'required|string|min:24|max:64', 'credential_proof' => 'required|string|size:64', 'confirmed' => 'required|accepted'], Access::VIEW);
            return $this->response($s->execute($r->user(), $student, $i));
        } finally {
            // Scrub before exception handling: never flash credentials or return upstream errors.
            $r->request->remove('password'); $r->request->remove('credential_proof');
            if ($r->isJson()) { $r->json()->remove('password'); $r->json()->remove('credential_proof'); }
        }
    }
    public function reconcile(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['operation_id' => 'required|uuid'], Access::VIEW);
        return $this->response($s->reconcile($r->user(), $student, $i['operation_id']));
    }
    public function cancel(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['operation_id' => 'required|uuid', 'generation' => 'required|integer|min:1', 'confirmed' => 'required|accepted'], Access::VIEW);
        return $this->response($s->cancel($r->user(), $student, $i['operation_id'], (int) $i['generation']));
    }
    public function refreshAccount(Request $r, int $student, Service $s): JsonResponse
    {
        $this->input($r, [], Access::VIEW);
        return $this->response($s->refreshAccount($r->user(), $student));
    }
    public function resetPassword(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:500'], Access::RESET);
        return $this->response($s->password($r->user(), $student, (int) $i['revision'], 'password_reset', $i['reason']));
    }
    public function previewLink(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['email_address' => ['required', 'string', 'email', 'max:254', 'regex:/\A[^A-Z@\s]+@alrowaduni\.edu\.sy\z/D']], Access::LINK);
        return $this->response($s->previewLink($r->user(), $student, $i['email_address']));
    }
    public function prepareAccount(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['revision' => 'required|integer|min:1', 'kind' => 'required|in:suspend,activate,link',
            'reason' => 'required|string|max:500', 'confirmed' => 'required|accepted',
            'email_address' => ['required_if:kind,link', 'prohibited_unless:kind,link', 'string', 'email', 'max:254', 'regex:/\A[^A-Z@\s]+@alrowaduni\.edu\.sy\z/D'],
            'preview_proof' => 'required_if:kind,link|prohibited_unless:kind,link|string|size:64',
            'ownership_confirmed' => 'required_if:kind,link|prohibited_unless:kind,link|accepted_if:kind,link'], Access::VIEW);
        return $this->response($s->prepareAccount($r->user(), $student, $i));
    }
    public function executeAccount(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['operation_id' => 'required|uuid', 'generation' => 'required|integer|min:1', 'confirmed' => 'required|accepted'], Access::VIEW);
        return $this->response($s->executeAccount($r->user(), $student, $i));
    }
    public function receipt(Request $r, int $student, Service $s): JsonResponse
    {
        $i = $this->input($r, ['operation_id' => 'required|uuid', 'generation' => 'required|integer|min:1'], Access::RECEIPT);
        return $this->response($s->receipt($r->user(), $student, $i['operation_id'], (int) $i['generation']));
    }
    public function selfEmail(Request $r, Service $s): JsonResponse
    {
        $this->input($r, []);
        return $this->response($s->selfEmail($r->user()));
    }
}
