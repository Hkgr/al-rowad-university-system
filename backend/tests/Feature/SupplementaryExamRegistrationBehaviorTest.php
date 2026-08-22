<?php

namespace Tests\Feature;

/**
 * Runs the complete booted Phase-4 schema and workflow fixture as a dedicated
 * behavior suite. The inherited tests execute real database mutations through
 * the registration/window services; source-contract checks live elsewhere.
 */
class SupplementaryExamRegistrationBehaviorTest extends SupplementaryExamEligibilitySchemaReadyRuntimeTest
{
}
