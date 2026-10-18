<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRegistrationReplacementEvent extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'student_registration_replacement_event_id';
    protected $guarded = [];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function request() { return $this->belongsTo(StudentRegistrationReplacementRequest::class, 'student_registration_replacement_request_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id', 'user_id'); }
}
