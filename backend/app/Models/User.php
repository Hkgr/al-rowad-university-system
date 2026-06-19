<?php

namespace App\Models;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'account_status_id',
        'student_id',
        'employee_id',
        'board_member_id',
        'last_login_at',
        'email_verified_at',
        'failed_login_attempts',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function setRememberToken($value): void
    {
        // This schema does not include a remember token column.
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'board_member_id', 'board_member_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function accountStatus(): BelongsTo
    {
        return $this->belongsTo(AccountStatus::class, 'account_status_id', 'account_status_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'decided_by_user_id', 'user_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'created_by_user_id', 'user_id');
    }

    public function boardDecisionAttachments(): HasMany
    {
        return $this->hasMany(BoardDecisionAttachment::class, 'uploaded_by_user_id', 'user_id');
    }

    public function boardMeetings(): HasMany
    {
        return $this->hasMany(BoardMeeting::class, 'created_by_user_id', 'user_id');
    }

    public function gradeAppeals(): HasMany
    {
        return $this->hasMany(GradeAppeal::class, 'reviewed_by_user_id', 'user_id');
    }

    public function gradeApprovals(): HasMany
    {
        return $this->hasMany(GradeApproval::class, 'approved_by_user_id', 'user_id');
    }

    public function gradeApprovalRecords(): HasMany
    {
        return $this->hasMany(GradeApproval::class, 'submitted_by_user_id', 'user_id');
    }

    public function gradeAuditLogs(): HasMany
    {
        return $this->hasMany(GradeAuditLog::class, 'changed_by_user_id', 'user_id');
    }

    public function libraryBorrowings(): HasMany
    {
        return $this->hasMany(LibraryBorrowing::class, 'created_by_user_id', 'user_id');
    }

    public function loginAuditLogs(): HasMany
    {
        return $this->hasMany(LoginAuditLog::class, 'user_id', 'user_id');
    }

    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class, 'user_id', 'user_id');
    }

    public function studentCourseRegistrations(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'advisor_user_id', 'user_id');
    }

    public function studentCourseRegistrationRecords(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'registered_by_user_id', 'user_id');
    }

    public function studentCourseResults(): HasMany
    {
        return $this->hasMany(StudentCourseResult::class, 'calculated_by_user_id', 'user_id');
    }

    public function studentCreditLimits(): HasMany
    {
        return $this->hasMany(StudentCreditLimit::class, 'approved_by_user_id', 'user_id');
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'verified_by_user_id', 'user_id');
    }

    public function studentGradeComponents(): HasMany
    {
        return $this->hasMany(StudentGradeComponent::class, 'entered_by_user_id', 'user_id');
    }

    public function supplementaryExamResults(): HasMany
    {
        return $this->hasMany(SupplementaryExamResult::class, 'entered_by_user_id', 'user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'created_by_user_id', 'user_id');
    }

    public function userActivityLogs(): HasMany
    {
        return $this->hasMany(UserActivityLog::class, 'user_id', 'user_id');
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'assigned_by_user_id', 'user_id');
    }

    public function userRoleRecords(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id', 'user_id');
    }

}
