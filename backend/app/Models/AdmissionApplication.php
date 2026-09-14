<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionApplication extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $application) {
            if ((!$application->exists || $application->isDirty('academic_program_id')) && \App\Services\AcademicPlanContext::installed()) {
                \App\Services\AcademicPlanContext::assertReady();
                if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) throw \App\Exceptions\AcademicPlanException::conflict('academic_plan_transaction_required', 'طلب القبول يتطلب معاملة ذرية للتحقق من تهيئة البرنامج.');
                app(\App\Services\AcademicCatalogTransaction::class)->revision(true);
                \App\Services\AcademicPlanAdmission::assertAccepting((int) $application->academic_program_id);
            }
        });
    }
    protected $table = 'admission_applications';

    protected $primaryKey = 'admission_application_id';

    protected $fillable = [
        'applicant_id',
        'academic_program_id',
        'academic_year_id',
        'application_date',
        'decision_status',
        'decision_date',
        'decided_by_user_id',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'decision_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id', 'applicant_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id', 'user_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'admission_application_id', 'admission_application_id');
    }

}
