<?php

namespace App\Support;

/**
 * University vice-presidency RBAC identities.
 *
 * Roles describe job position. Permissions authorize actions.
 * Do not treat the legacy vice_president role as either new identity.
 */
final class VicePresidency
{
    public const ROLE_SCIENTIFIC = 'vice_president_scientific';

    public const ROLE_ADMINISTRATIVE = 'vice_president_administrative';

    public const ROLE_LEGACY = 'vice_president';

    public const PERMISSION_SCIENTIFIC_ACCESS = 'vice_presidency.scientific.access';

    public const PERMISSION_ADMINISTRATIVE_ACCESS = 'vice_presidency.administrative.access';

    public const MODULE_CODE = 'vice_presidency';
}
