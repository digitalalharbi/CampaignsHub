<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyncEngineWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 item 1, §3 rows «429/5xx/partial pages» and «task running during detach» — a checkpoint is the last window
 * of one account and grain that was fully read and written. It is not an attempt stamp: it moves only when every page of the window
 * landed, it stays where it was otherwise, and the next scheduled sync starts from it and still re-reads the restatement window.
 */
final class SyncCheckpointTest extends TestCase
{
    use BuildsSyncEngineWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyncEngineWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runWindow(string $from, string $to, array $meta = []): void
    {
        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, $from, $to, $meta);
    }

    public function test_a_window_that_landed_records_the_window_when_and_which_run_for_every_grain(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('success', $run->status, (string) $run->error);

        foreach (['campaign', 'ad_set', 'ad'] as $grain) {
            $this->assertSame([
                'from' => '2026-07-30', 'to' => '2026-08-02',
                'completed_at' => Carbon::now()->toIso8601String(), 'run' => $run->id,
            ], $this->checkpointFacts($grain), $grain);
        }
        $this->assertSame($this->project->id, $this->checkpoint()->project_id);
        $this->assertSame($this->tenant->id, $this->checkpoint()->tenant_id);
    }

    public function test_no_rows_is_a_complete_answer_and_advances(): void
    {
        $this->script = ['mode' => 'no_rows'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $this->assertSame('2026-08-02', $this->checkpointFacts()['to'] ?? null);
    }

    /** @return array<string, array{0:string}> */
    public static function mid_window_failures(): array
    {
        return [
            '429 on page 2' => ['page2_429'],
            '500 on page 2' => ['page2_500'],
            'timeout on page 2' => ['page2_timeout'],
            '503 on the first page' => ['first_503'],
            'a refusal that is no one\'s to retry' => ['first_401'],
        ];
    }

    #[DataProvider('mid_window_failures')]
    public function test_a_failure_mid_window_keeps_the_checkpoint_and_records_no_success(string $mode): void
    {
        $this->runWindow('2026-07-30', '2026-08-01');
        $before = $this->checkpointFacts();
        $this->assertNotSame([], $before);

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->script = ['mode' => $mode, 'spend' => '5'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertSame($before, $this->checkpointFacts(), 'the checkpoint moved on a window that did not land');
        $this->assertEqualsWithDelta(1200.0, $this->storedSpend(), 0.001, 'page 1 overwrote what the previous success stored');
    }

    public function test_a_read_stopped_at_the_page_ceiling_is_not_a_complete_window_and_does_not_read_as_success(): void
    {
        $this->script = ['mode' => 'endless'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('page', (string) $run->error);
        $this->assertNull($this->checkpoint(), 'a window cut off at the page ceiling claimed to be fully read');
        $this->assertSame('pages_truncated', $this->account->fresh()->last_sync_error_category);
    }

    public function test_rows_that_could_not_be_placed_leave_the_campaign_grain_where_it_was(): void
    {
        $this->script = ['mode' => 'unmapped'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $this->assertSame('partial_mapping', $this->lastRun()->status);
        $this->assertNull($this->checkpoint('campaign'));
    }

    public function test_a_grain_that_failed_stays_while_the_grain_that_landed_moves(): void
    {
        $this->runWindow('2026-07-30', '2026-08-01');
        $adSetBefore = $this->checkpointFacts('ad_set');

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->script = ['mode' => 'adset_500'];
        $this->runWindow('2026-07-31', '2026-08-02');

        $this->assertSame('partial_mapping', $this->lastRun()->status);
        $this->assertSame($adSetBefore, $this->checkpointFacts('ad_set'));
        $this->assertSame('2026-08-02', $this->checkpointFacts('campaign')['to']);
    }

    public function test_money_withheld_for_want_of_a_currency_is_not_a_landed_window(): void
    {
        $this->account->forceFill(['currency' => null])->save();
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('partial_mapping', $run->status);
        $this->assertSame('currency_unknown', $this->account->fresh()->last_sync_error_category);
        $this->assertNull($this->checkpoint('campaign'));
    }

    public function test_a_job_running_during_a_detach_writes_no_figures_and_no_checkpoint(): void
    {
        $this->duringCall = function (): void {
            ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);
        };
        $this->runWindow('2026-07-30', '2026-08-02');

        $this->assertSame('awaiting_assignment', $this->lastRun()->status);
        $this->assertSame(0.0, $this->storedSpend());
        $this->assertNull($this->checkpoint('campaign'), 'a checkpoint claimed a window that was refused at the write');
    }

    public function test_a_checkpoint_belongs_to_the_binding_so_a_new_project_starts_with_none(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');
        $this->assertNotNull($this->checkpoint());

        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);
        $elsewhere = $this->bwProject($this->tenant, $this->bwWorkspace($this->tenant, 'W2'), 'P2');
        $this->bwBind($this->account, $elsewhere);

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $this->asked = [];
        $this->runWindow('2026-08-17', '2026-08-20', ['source' => 'scheduler']);

        $this->assertTrue($this->askedSince('2026-08-17'), 'the old project\'s checkpoint steered a sync for the new project');
    }

    // ── Resuming ──────────────────────────────────────────────────────────────────────────────

    public function test_a_healthy_account_asks_for_exactly_the_window_the_sweep_asked_for(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'UTC'));
        $this->asked = [];
        $this->runWindow('2026-07-31', '2026-08-03', ['source' => 'scheduler']);

        $this->assertTrue($this->askedSince('2026-07-31'));
        $this->assertSame('2026-07-31', $this->lastRun()->window_start->toDateString());
        $this->assertArrayNotHasKey('resume', (array) $this->lastRun()->meta);
    }

    public function test_after_a_gap_the_sweep_resumes_from_the_checkpoint_and_rereads_the_restatement_window(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        // Ten days of failure later: the sweep's three days would leave a hole, so it goes back to the checkpoint less seven.
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $this->asked = [];
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'scheduler']);

        $this->assertTrue($this->askedSince('2026-07-26'), implode("\n", $this->asked));
        $run = $this->lastRun();
        $this->assertSame('2026-07-26', $run->window_start->toDateString());
        $this->assertSame('2026-08-09', $run->meta['resume']['requested_from']);
        $this->assertSame('2026-07-26', $run->meta['resume']['resumed_from']);
        $this->assertFalse($run->meta['resume']['clamped']);
        $this->assertSame('2026-08-12', $this->checkpointFacts()['to']);
        $this->assertSame('2026-07-26', $this->checkpointFacts()['from']);
    }

    public function test_a_person_asking_for_a_window_gets_that_window(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $this->asked = [];
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'resync', 'manual' => true]);

        $this->assertTrue($this->askedSince('2026-08-09'));
        $this->assertFalse($this->askedSince('2026-07-26'));
    }

    public function test_a_later_window_that_leaves_a_hole_does_not_move_the_checkpoint_past_it(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');
        $before = $this->checkpointFacts();

        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'resync', 'manual' => true]);

        $this->assertSame('success', $this->lastRun()->status);
        $this->assertSame($before, $this->checkpointFacts());
    }

    public function test_an_older_window_read_again_never_moves_the_checkpoint_back(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');
        $before = $this->checkpointFacts();

        $this->runWindow('2026-06-01', '2026-06-05', ['source' => 'resync', 'manual' => true]);

        $this->assertSame($before, $this->checkpointFacts());
    }

    public function test_a_wider_read_that_landed_nothing_new_is_not_repeated_on_every_sweep(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        // Stuck: rows keep landing unplaced, so the grain never advances. The wide read is tried once, not every half hour.
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $this->script = ['mode' => 'unmapped'];
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'scheduler']);
        $this->assertSame('2026-07-26', $this->lastRun()->window_start->toDateString());

        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'scheduler']);
        $this->assertSame('2026-08-09', $this->lastRun()->window_start->toDateString(), 'an account stuck partial re-read the whole gap every half hour');

        Carbon::setTestNow(Carbon::now()->addHours(25));
        $this->runWindow('2026-08-10', '2026-08-13', ['source' => 'scheduler']);
        $this->assertSame('2026-07-26', $this->lastRun()->window_start->toDateString());
    }

    public function test_a_wide_read_that_failed_is_tried_again_at_the_next_sweep(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $this->script = ['mode' => 'first_503'];
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'scheduler']);
        $this->assertSame('failed', $this->lastRun()->status);

        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->script = ['mode' => 'ok'];
        $this->runWindow('2026-08-09', '2026-08-12', ['source' => 'scheduler']);
        $this->assertSame('2026-07-26', $this->lastRun()->window_start->toDateString());
        $this->assertSame('2026-08-12', $this->checkpointFacts()['to']);
    }

    public function test_a_gap_longer_than_the_ceiling_is_capped_and_the_days_never_read_are_written_down(): void
    {
        $this->runWindow('2026-07-30', '2026-08-02');

        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'UTC'));
        $this->asked = [];
        $this->runWindow('2026-11-28', '2026-12-01', ['source' => 'scheduler']);

        $this->assertSame('2026-10-27', $this->lastRun()->window_start->toDateString(), 'the resume ceiling did not hold');
        $this->assertSame('success', $this->lastRun()->status, (string) $this->lastRun()->error);
        $row = $this->checkpoint();
        $this->assertSame('2026-12-01', $row->window_to->toDateString());
        $this->assertSame('2026-08-03', $row->unread_from?->toDateString());
        $this->assertSame('2026-10-26', $row->unread_to?->toDateString());
        $this->assertTrue((bool) ($this->lastRun()->meta['resume']['clamped'] ?? false));
    }

    public function test_no_provider_page_cursor_is_stored(): void
    {
        /*
         * Meta's `paging.next` is an absolute URL carrying the access token, Snapchat's `next_link` expires, and a window's pages are
         * buffered and written all at once, so there is nothing to resume mid-window into. A cursor column would hold a secret.
         */
        foreach (Schema::getColumnListing('metric_sync_checkpoints') as $column) {
            $this->assertStringNotContainsString('cursor', $column);
            $this->assertStringNotContainsString('next', $column);
        }
    }

    public function test_the_syncer_called_directly_keeps_its_signature_and_still_records_a_checkpoint(): void
    {
        app(AccountMetricsSyncer::class)->sync($this->account, Carbon::parse('2026-07-30'), Carbon::parse('2026-08-02'));

        $this->assertSame('2026-08-02', $this->checkpointFacts()['to'] ?? null);
    }

    public function test_a_stored_error_never_carries_a_token_from_the_url_that_failed(): void
    {
        $this->script = ['mode' => 'page2_token_timeout'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertStringNotContainsString('SEKRET-TOKEN', (string) $run->error);
    }

    public function test_a_grain_failure_recorded_on_the_run_never_carries_a_token_either(): void
    {
        $this->script = ['mode' => 'adset_token_timeout'];
        $this->runWindow('2026-07-30', '2026-08-02');

        $run = $this->lastRun();
        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('cURL error 28', (string) $run->error);
        $this->assertStringNotContainsString('SEKRET-TOKEN', json_encode($run->getAttributes(), JSON_THROW_ON_ERROR));
    }
}
