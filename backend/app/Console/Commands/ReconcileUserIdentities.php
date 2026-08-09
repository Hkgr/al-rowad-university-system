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
            $students = Student::query()->whereRaw('BINARY email = ?', [$user->email])->pluck('student_id');
            $employees = Employee::query()->whereRaw('BINARY email = ?', [$user->email])->pluck('employee_id');
            $updates = [];
            $statuses = [];
            foreach (['student' => [$user->student_id, $students], 'employee' => [$user->employee_id, $employees]] as $type => [$linkedId, $matches]) {
                if ($linkedId !== null) {
                    $statuses[] = $matches->isEmpty() || ($matches->count() === 1 && $matches->contains($linkedId))
                        ? "$type:linked"
                        : "$type:conflict-linked-id-vs-email";
                } elseif ($matches->count() === 0) {
                    $statuses[] = "$type:unlinked";
                } elseif ($matches->count() > 1 || User::query()->where($type.'_id', $matches->first())->exists()) {
                    $statuses[] = "$type:conflict";
                } else {
                    $statuses[] = "$type:deterministic";
                    $updates[$type.'_id'] = $matches->first();
                }
            }
            if ($this->option('apply') && $updates !== []) {
                User::query()->whereKey($user->user_id)->update($updates);
                $statuses[] = 'applied:'.implode(',', array_keys($updates));
            }
            $rows[] = [$user->user_id, $user->email, implode('; ', $statuses), $students->implode(','), $employees->implode(',')];
        }
        $this->table(['user_id', 'email', 'status', 'student_ids', 'employee_ids'], $rows);
        $this->info($this->option('apply') ? 'Applied deterministic matches.' : 'Dry run; use --apply to persist deterministic matches.');
        return self::SUCCESS;
    }
}
