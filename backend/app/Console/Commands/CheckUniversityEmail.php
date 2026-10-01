<?php

namespace App\Console\Commands;

use App\Exceptions\UniversityEmailException;
use App\Services\MailcowReadService;
use Illuminate\Console\Command;

class CheckUniversityEmail extends Command
{
    protected $signature = 'university-email:check';
    protected $description = 'Check the student Mailcow domain using a fixed read-only request';
    public function handle(MailcowReadService $service): int
    {
        try {
            $data = $service->checkDomain();
            $this->table(['Domain', 'Active', 'Mailboxes', 'Limit', 'Remaining'], [[$data['domain'], $data['active'] ? 'yes' : 'no', $data['mailbox_count'], $data['mailbox_limit'], $data['remaining_mailboxes']]]);
            return self::SUCCESS;
        } catch (UniversityEmailException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
