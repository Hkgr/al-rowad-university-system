<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Support\AdministrativeGovernanceException;
use App\Support\HrOffice;
use App\Support\OwnerPortal;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PayrollAccountingAccess
{
    public function __construct(private readonly OwnerPortal $owner, private readonly HrOffice $office) {}

    public function authorize(User $actor, string $permission = OwnerPortal::PAYROLL_VIEW): void
    {
        if ($this->owner->allows($actor, $permission) && $this->owner->allows($actor, OwnerPortal::PAYROLL_VIEW)) {
            return;
        }
        $this->office->payroll($actor, OwnerPortal::PAYROLL_VIEW);
        $this->office->payroll($actor, $permission);
    }

    public function allows(User $actor, string $permission): bool
    {
        try {
            $this->authorize($actor, $permission);

            return true;
        } catch (HttpExceptionInterface|AdministrativeGovernanceException $e) {
            return false;
        }
    }
}
