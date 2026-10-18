<?php

namespace App\Models\Payroll;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Financial working-sheet identity with an OPTIONAL explicit HR identity link. Creating one
 * still never creates personnel/accounts; linkage never changes financial body or amounts.
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

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function entry(): HasOne
    {
        return $this->hasOne(PayrollEntry::class, 'payroll_employee_id');
    }
}
