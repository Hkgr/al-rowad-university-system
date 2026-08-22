<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplementaryExamRegistrationEvent extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'supplementary_exam_registration_event_id';
    protected $guarded = [];
    protected function casts(): array { return ['created_at'=>'datetime']; }
}
