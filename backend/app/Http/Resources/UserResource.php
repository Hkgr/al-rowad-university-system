<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'email' => $this->email,
            'account_status_id' => $this->account_status_id,
            'student_id' => $this->student_id,
            'employee_id' => $this->employee_id,
            'board_member_id' => $this->board_member_id,
            'last_login_at' => $this->last_login_at,
            'email_verified_at' => $this->email_verified_at,
            'failed_login_attempts' => $this->failed_login_attempts,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
