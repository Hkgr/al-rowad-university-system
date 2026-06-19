<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationalUnit extends Model
{
    protected $table = 'organizational_units';

    protected $primaryKey = 'organizational_unit_id';

    protected $fillable = [
        'unit_code',
        'unit_name',
        'unit_type_id',
        'parent_unit_id',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'parent_unit_id', 'organizational_unit_id');
    }

    public function organizationalUnitType(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnitType::class, 'unit_type_id', 'unit_type_id');
    }

    public function boards(): HasMany
    {
        return $this->hasMany(Board::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function colleges(): HasMany
    {
        return $this->hasMany(College::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function employeePositions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function employeeUnitAssignments(): HasMany
    {
        return $this->hasMany(EmployeeUnitAssignment::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function organizationalUnits(): HasMany
    {
        return $this->hasMany(OrganizationalUnit::class, 'parent_unit_id', 'organizational_unit_id');
    }

}
