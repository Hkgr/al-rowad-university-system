<?php

namespace App\Http\Requests\AccountAdministration;

use App\Support\AccountAdministration;
use Illuminate\Validation\Validator;

/** Shared guards for account write requests: server-owned fields are never accepted from the browser. */
trait RejectsServerOwnedFields
{
    /** @param list<string> $allowed */
    protected function rejectUnknownFields(Validator $validator, array $allowed): void
    {
        foreach (array_keys($this->all()) as $field) {
            if (in_array($field, AccountAdministration::SERVER_OWNED_FIELDS, true)) {
                $validator->errors()->add($field, 'هذا الحقل يُحدَّد على الخادم ولا يُقبل من الواجهة.');
            } elseif (! in_array($field, $allowed, true)) {
                $validator->errors()->add($field, 'حقل غير مسموح في هذا الإجراء.');
            }
        }
    }
}
