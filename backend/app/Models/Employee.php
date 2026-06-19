<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table = 'employees';

    protected $primaryKey = 'employee_id';

    protected $fillable = [
        'employee_number',
        'first_name',
        'last_name',
        'father_name',
        'mother_name',
        'phone_number',
        'email',
        'hire_date',
        'employee_type_id',
        'employee_status_id',
        'organizational_unit_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function employeeStatus(): BelongsTo
    {
        return $this->belongsTo(EmployeeStatus::class, 'employee_status_id', 'employee_status_id');
    }

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class, 'employee_type_id', 'employee_type_id');
    }

    public function boardMembers(): HasMany
    {
        return $this->hasMany(BoardMember::class, 'employee_id', 'employee_id');
    }

    public function employeePositions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class, 'employee_id', 'employee_id');
    }

    public function employeeUnitAssignments(): HasMany
    {
        return $this->hasMany(EmployeeUnitAssignment::class, 'employee_id', 'employee_id');
    }

    public function facultyMembers(): HasMany
    {
        return $this->hasMany(FacultyMember::class, 'employee_id', 'employee_id');
    }

    public function libraryBorrowings(): HasMany
    {
        return $this->hasMany(LibraryBorrowing::class, 'employee_id', 'employee_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'employee_id', 'employee_id');
    }

}
