<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    protected $table = 'applicants';

    protected $primaryKey = 'applicant_id';

    protected $fillable = [
        'applicant_number',
        'first_name',
        'last_name',
        'father_name',
        'mother_name',
        'date_of_birth',
        'gender',
        'phone_number',
        'email',
        'address',
        'nationality',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'applicant_id', 'applicant_id');
    }

}
