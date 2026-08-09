<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;

class ReconcileUserIdentities extends Command
{
    protected $signature = 'identity:reconcile {--apply : Persist only unique exact-email matches}';
    protected $description = 'Report deterministic user/student/employee identity matches without matching names';

    public function handle(): int
    {
        $rows = [];
        foreach (User::query()->orderBy('user_id')->get() as $user) {
            if ($user->student_id || $user->employee_id) {
                $rows[] = [$user->user_id, $user->email, 'linked', $user->student_id, $user->employee_id];
                continue;
            }
            $students = Student::query()->where('email', $user->email)->pluck('student_id');
            $employees = Employee::query()->where('email', $user->email)->pluck('employee_id');
            $matches = $students->count() + $employees->count();
            $status = $matches === 0 ? 'unlinked' : ($matches === 1 ? 'deterministic' : 'ambiguous');
            if ($this->option('apply') && $matches === 1) {
                User::query()->whereKey($user->user_id)->update([
                    'student_id' => $students->first(), 'employee_id' => $employees->first(),
                ]);
                $status = 'linked-now';
            }
            $rows[] = [$user->user_id, $user->email, $status, $students->implode(','), $employees->implode(',')];
        }
        $this->table(['user_id', 'email', 'status', 'student_ids', 'employee_ids'], $rows);
        $this->info($this->option('apply') ? 'Applied deterministic matches.' : 'Dry run; use --apply to persist deterministic matches.');
        return self::SUCCESS;
    }
}
