<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Payroll classification (الهيئة). A user-managed list for this feature only; not an application role or org unit. */
class PayrollBody extends Model
{
    protected $table = 'payroll_bodies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'revision' => 'integer'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(PayrollEmployee::class, 'payroll_body_id');
    }
}
