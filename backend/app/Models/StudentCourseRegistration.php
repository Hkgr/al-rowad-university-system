<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentCourseRegistration extends Model
{
    public const CURRENT_STATUS = 'registered';

    public const HISTORICAL_ATTEMPT_STATUSES = ['registered', 'completed'];

    public const EXCLUDED_STATUSES = ['dropped', 'withdrawn'];

    protected $table = 'student_course_registrations';

    protected $primaryKey = 'student_course_registration_id';

    protected $fillable = [
        'student_id',
        'course_offering_id',
        'registration_date',
        'registered_by_user_id',
        'advisor_user_id',
        'registration_status_id',
        'result_status_id',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'registration_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_user_id', 'user_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id', 'user_id');
    }

    public function resultStatus(): BelongsTo
    {
        return $this->belongsTo(ResultStatus::class, 'result_status_id', 'result_status_id');
    }

    public function registrationStatus(): BelongsTo
    {
        return $this->belongsTo(RegistrationStatus::class, 'registration_status_id', 'registration_status_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function gradeAppeals(): HasMany
    {
        return $this->hasMany(GradeAppeal::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function studentCourseResults(): HasMany
    {
        return $this->hasMany(StudentCourseResult::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function studentCourseResult(): HasOne
    {
        return $this->hasOne(StudentCourseResult::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function studentGradeComponents(): HasMany
    {
        return $this->hasMany(StudentGradeComponent::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function supplementaryExamResults(): HasMany
    {
        return $this->hasMany(SupplementaryExamResult::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereHas(
            'registrationStatus',
            fn (Builder $status) => $status->where('status_code', self::CURRENT_STATUS)
        );
    }

    public function scopeAcademicAttempts(Builder $query, bool $requireResult = true): Builder
    {
        $query->whereHas(
            'registrationStatus',
            fn (Builder $status) => $status->whereIn('status_code', self::HISTORICAL_ATTEMPT_STATUSES)
        );

        return $requireResult ? $query->whereHas('studentCourseResult') : $query;
    }

    public function scopeCurrentOrHistoricalWithResult(Builder $query): Builder
    {
        return $query->where(function (Builder $registration): void {
            $registration->current()->orWhere(
                fn (Builder $historical) => $historical->academicAttempts()
            );
        });
    }

    public function allowsAttendanceEntry(): bool
    {
        return $this->registrationStatus?->status_code === self::CURRENT_STATUS;
    }

    public function allowsGradeEntry(): bool
    {
        return $this->registrationStatus?->status_code === self::CURRENT_STATUS;
    }

}
