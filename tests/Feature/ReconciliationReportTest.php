<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Reconciliation\RunReconciliation;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReconciliationReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_an_admin_can_read_reconciliation_reports(): void
    {
        $run = app(RunReconciliation::class)->execute();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/v1/admin/reconciliation/runs')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/v1/admin/reconciliation/runs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $run->runId)
            ->assertJsonPath('data.0.status', 'clean')
            ->assertJsonPath('data.0.summary.discrepancy_count', 0)
            ->assertJsonCount(0, 'data.0.discrepancies');
    }

    #[Test]
    public function the_reconciliation_command_returns_success_for_a_clean_run(): void
    {
        $command = $this->artisan('reconciliation:run');

        if (is_int($command)) {
            self::fail('The reconciliation command did not return a pending command.');
        }

        $command
            ->expectsOutputToContain('clean (0 discrepancies)')
            ->assertSuccessful();
    }
}
