<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — «one active binding per account» is decided under the lock.
 *
 * `ProjectIntegrationController::bind()` refused an account already active on another project, and
 * asked BEFORE it took the tenant lock. Two operators binding the same account to two projects in the
 * same instant both passed and both wrote, and `AccountAssignment::projectIdFor()` then chose one of
 * the two silently for every sync. A race cannot be reproduced in one test connection, so the guard
 * reads the source: the refusal must be asked again inside the transaction, after the lock, before
 * the write.
 */
final class BindRaceGuardTest extends TestCase
{
    public function test_the_active_elsewhere_refusal_is_asked_under_the_tenant_lock(): void
    {
        $source = File::get(app_path('Domains/Integrations/Http/Controllers/ProjectIntegrationController.php'));

        $bind = substr($source, (int) strpos($source, 'public function bind('));
        $bind = substr($bind, 0, (int) strpos($bind, 'public function bindBatch('));

        $lock = strpos($bind, '->lockForUpdate()');
        $recheck = strpos($bind, "->where('project_id', '!=', \$project->id)\n                    ->exists();");
        $write = strpos($bind, 'ProjectIntegrationBinding::create([');

        $this->assertNotFalse($lock, 'bind() no longer locks the tenant row');
        $this->assertNotFalse($recheck, 'bind() no longer re-asks whether the account is active elsewhere');
        $this->assertNotFalse($write);
        $this->assertTrue($lock < $recheck && $recheck < $write, 'the active-elsewhere refusal must sit between the lock and the write');
    }
}
