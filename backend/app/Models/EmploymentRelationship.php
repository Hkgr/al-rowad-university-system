<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dated HR ledger; writes belong exclusively to HrOfficeService approval/classification boundaries. */
final class EmploymentRelationship extends Model
{
    protected $table = 'hr_employment_relationships';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'superseded_from' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
