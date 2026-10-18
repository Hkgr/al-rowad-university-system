<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FacultyRosterImportService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Teachers + college deans roster import. Dry-run by default; --apply writes through
 * the application services (authorization, transactions, audit). Reports never contain
 * passwords; new accounts' temporary passwords go only to a 0600 credentials file.
 */
class ImportFacultyRoster extends Command
{
    protected $signature = 'faculty:import-roster
        {file : Operator-completed roster CSV (UTF-8)}
        {--dry-run : Preview only (default)}
        {--apply : Write the rows that pass every check}
        {--vp-actor= : Username of the administrative VP (or super_admin) with faculty.manage + deans.manage and a university scope}
        {--accounts-actor= : Username of an account manager (super_admin or technical team with user_accounts.manage)}
        {--effective-date=2025-01-01 : Start date of the college affiliation and of the DEAN position}
        {--report= : Report path without extension (default storage/app/private/faculty-roster/report-<time>)}
        {--credentials-out= : Credentials CSV path (default storage/app/private/faculty-roster/credentials-<time>.csv)}';

    protected $description = 'Preview or apply the teachers/deans roster through the administrative services';

    public function handle(FacultyRosterImportService $import): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply.');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $stamp = now()->format('Ymd-His');
        $directory = storage_path('app/private/faculty-roster');

        try {
            $rows = $import->readCsv((string) $this->argument('file'));
            if ($apply) {
                $vp = $this->actor('vp-actor');
                $accounts = $this->actor('accounts-actor');
                $import->assertActors($vp, $accounts);
                // Created (0600) before any write so issued passwords are never lost.
                $credentialsPath = $this->option('credentials-out') ?: $directory.'/credentials-'.$stamp.'.csv';
                $credentialsHandle = $this->openCredentials($credentialsPath);
                $result = $import->apply($rows, $vp, $accounts, (string) $this->option('effective-date'), 'cli:faculty:import-roster',
                    function (array $issued) use ($credentialsHandle): void {
                        if (ftell($credentialsHandle) === 3) {
                            fputcsv($credentialsHandle, array_keys($issued), ',', '"', '\\');
                        }
                        fputcsv($credentialsHandle, array_values($issued), ',', '"', '\\');
                        fflush($credentialsHandle);
                    });
                fclose($credentialsHandle);
            } else {
                $result = $import->preview($rows, (string) $this->option('effective-date'));
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['#', 'college', 'source name', 'dean', 'status', 'reason / change'],
            array_map(fn (array $row) => [
                $row['row_no'],
                $row['college']['college_code'] ?: $row['source_college'],
                $row['source_name'],
                $row['is_dean'] ? 'yes' : '',
                $row['status'].($apply ? ($row['applied'] ? ' ✓' : ' ✗') : ''),
                mb_strimwidth(implode(' | ', $row['reasons'] ?: $row['changes']), 0, 110, '…'),
            ], $result['rows'])
        );
        $summary = $result['summary'];
        $this->info(sprintf('%s: %d rows (%d deans) — ready %d (%d deans), blocked %d.', $apply ? 'APPLY' : 'DRY-RUN', $summary['rows'], $summary['dean_rows'], $summary['ready'], $summary['ready_deans'], $summary['blocked']));
        $this->line('By status: '.json_encode($summary['by_status']));

        $report = $this->option('report') ?: $directory.'/report-'.$stamp.($apply ? '-apply' : '-dry-run');
        $this->writeReport($report, $result, $apply);
        $this->info("Report: {$report}.json and {$report}.csv (no passwords).");

        if ($apply) {
            $credentials = $result['credentials'];
            if ($credentials !== []) {
                $this->warn(count($credentials)." new account(s). Temporary passwords are ONLY in {$credentialsPath} (mode 0600). Hand them over securely, then delete the file. The system cannot force a password change at first login.");
            } else {
                @unlink($credentialsPath);
                $this->line('No new accounts were created; no credentials file kept.');
            }
        } else {
            $this->line('Dry run: nothing was written. Use --apply --vp-actor=... --accounts-actor=... to write the ready rows.');
        }

        return self::SUCCESS;
    }

    private function actor(string $option): User
    {
        $username = trim((string) $this->option($option));
        if ($username === '') {
            throw new RuntimeException("--{$option} is required with --apply.");
        }
        $user = User::query()->with('accountStatus')->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->first();
        if ($user === null) {
            throw new RuntimeException("--{$option}: account {$username} not found.");
        }

        return $user;
    }

    private function writeReport(string $base, array $result, bool $apply): void
    {
        $this->ensureDirectory(dirname($base));
        $payload = ['mode' => $apply ? 'apply' : 'dry-run', 'generated_at' => now()->toIso8601String(), 'effective_date' => $this->option('effective-date')] + $result;
        unset($payload['credentials']);
        file_put_contents($base.'.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $handle = fopen($base.'.csv', 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['row_no', 'source_college', 'college_code', 'source_name', 'source_designation', 'equivalence_status_source', 'is_dean', 'status', 'applied', 'reasons', 'changes', 'employee_id', 'faculty_member_id', 'user_id', 'username', 'before', 'after'], ',', '"', '\\');
        foreach ($result['rows'] as $row) {
            fputcsv($handle, [
                $row['row_no'], $row['source_college'], $row['college']['college_code'], $row['source_name'], $row['source_designation'],
                $row['equivalence_status_source'], $row['is_dean'] ? 'yes' : 'no', $row['status'], isset($row['applied']) ? ($row['applied'] ? 'yes' : 'no') : '',
                implode(' | ', $row['reasons']), implode(' | ', $row['changes']), $row['employee_id'], $row['faculty_member_id'], $row['user_id'], $row['username'],
                json_encode($row['before'], JSON_UNESCAPED_UNICODE), json_encode($row['after'], JSON_UNESCAPED_UNICODE),
            ], ',', '"', '\\');
        }
        fclose($handle);
    }

    /** @return resource */
    private function openCredentials(string $path)
    {
        $this->ensureDirectory(dirname($path));
        $previous = umask(0077);
        $handle = @fopen($path, 'xb');
        umask($previous);
        if ($handle === false) {
            throw new RuntimeException("Cannot create credentials file {$path} (it must not already exist).");
        }
        chmod($path, 0600);
        fwrite($handle, "\xEF\xBB\xBF");

        return $handle;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }
}
