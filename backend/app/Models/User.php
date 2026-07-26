<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id',
            'user_id',
            'role_id'
        )
            ->withPivot(['user_role_id', 'assigned_by_user_id', 'assigned_at', 'is_active'])
            ->wherePivot('is_active', true)
            ->where('roles.is_active', true);
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

    public function isAccountActive(): bool
    {
        $this->loadMissing('accountStatus');

        return $this->accountStatus !== null
            && $this->accountStatus->is_active
            && $this->accountStatus->status_code === config('authorization.active_account_status', 'active');
    }

    public function roleCodes(): array
    {
        $this->loadMissing('roles');

        return $this->roles
            ->pluck('role_code')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function permissionCodes(): array
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('permission_code'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function hasRole(string|array $roles): bool
    {
        $requiredRoles = is_array($roles) ? $roles : [$roles];

        return count(array_intersect($requiredRoles, $this->roleCodes())) > 0;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('authorization.super_admin_role', 'super_admin'));
    }

    public function isStudentOnly(): bool
    {
        $roleCodes = $this->roleCodes();
        $studentRole = config('authorization.student_role', 'student');

        return in_array($studentRole, $roleCodes, true)
            && count(array_diff($roleCodes, [$studentRole])) === 0;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissionCodes = $this->permissionCodes();

        if (in_array($permission, $permissionCodes, true)) {
            return true;
        }

        if (
            config('authorization.manage_implies_view', true)
            && str_ends_with($permission, '.view')
        ) {
            $managePermission = substr($permission, 0, -strlen('.view')).'.manage';

            return in_array($managePermission, $permissionCodes, true);
        }

        return false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function accessibleDashboards(): array
    {
        $dashboards = config('authorization.dashboards', []);

        return collect($dashboards)
            ->filter(function (array $rules): bool {
                $requiredProfile = $rules['required_profile'] ?? null;

                if ($requiredProfile && empty($this->getAttribute($requiredProfile))) {
                    return false;
                }

                if ($this->isSuperAdmin()) {
                    return true;
                }

                $hasRole = $this->hasRole($rules['roles'] ?? []);
                $hasPermission = $this->hasAnyPermission($rules['permissions'] ?? []);

                return $hasRole || $hasPermission;
            })
            ->map(fn (array $rules, string $code): array => [
                'code' => $code,
                'path' => $rules['path'],
            ])
            ->values()
            ->all();
    }

    public function defaultDashboardPath(): ?string
    {
        $dashboards = collect($this->accessibleDashboards())->keyBy('code');

        foreach (config('authorization.dashboard_priority', []) as $dashboardCode) {
            if ($dashboards->has($dashboardCode)) {
                return $dashboards->get($dashboardCode)['path'];
            }
        }

        return null;
    }

}
