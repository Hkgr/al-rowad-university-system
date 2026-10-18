<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Current working-sheet amounts of one payroll employee, in integer USD cents (NULL = blank). */
class PayrollEntry extends Model
{
    protected $table = 'payroll_entries';

    protected $primaryKey = 'payroll_employee_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fixed_salary_cents' => 'integer',
            'deduction_cents' => 'integer',
            'compensation_cents' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }
}
