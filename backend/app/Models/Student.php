<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;
    protected $table = 'students';

    protected $primaryKey = 'student_id';

    protected $fillable = [
        'student_number',
        'admission_application_id',
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
        'academic_program_id',
        'current_academic_level_id',
        'enrollment_date',
        'student_status_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrollment_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function admissionApplication(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id', 'admission_application_id');
    }

    public function currentAcademicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'current_academic_level_id', 'academic_level_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function studentStatus(): BelongsTo
    {
        return $this->belongsTo(StudentStatus::class, 'student_status_id', 'student_status_id');
    }

    public function gradeAppeals(): HasMany
    {
        return $this->hasMany(GradeAppeal::class, 'student_id', 'student_id');
    }

    public function libraryBorrowings(): HasMany
    {
        return $this->hasMany(LibraryBorrowing::class, 'student_id', 'student_id');
    }

    public function studentAcademicTerms(): HasMany
    {
        return $this->hasMany(StudentAcademicTerm::class, 'student_id', 'student_id');
    }

    public function studentAttendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class, 'student_id', 'student_id');
    }

    public function studentCourseRegistrations(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'student_id', 'student_id');
    }

    public function studentCreditLimits(): HasMany
    {
        return $this->hasMany(StudentCreditLimit::class, 'student_id', 'student_id');
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id', 'student_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'student_id', 'student_id');
    }

}
