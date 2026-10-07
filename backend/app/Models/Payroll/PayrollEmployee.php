<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Payroll employee: an independent record of the payroll feature. It has no relation to teachers, HR
 * employees, students or user accounts, and creating one never creates any of those.
 */
class PayrollEmployee extends Model
{
    protected $table = 'payroll_employees';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'payroll_body_id' => 'integer'];
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(PayrollBody::class, 'payroll_body_id');
    }

    public function entry(): HasOne
    {
        return $this->hasOne(PayrollEntry::class, 'payroll_employee_id');
    }
}
