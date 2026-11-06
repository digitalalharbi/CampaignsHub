<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 — every connector that stops at a page ceiling says it stopped, in the terms of its own provider's paging.
 * A read that ended because the provider ran out of pages says nothing. Without this a capped window is a complete one.
 */
final class ConnectorPageCeilingIsNotedTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    /** @return array<string, array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public static function providers(): array
    {
        $next = 'https://provider.test/next?x=1';

        return [
            'meta follows paging.next' => ['meta', ['data' => [['id' => '1']], 'paging' => ['next' => $next]], ['data' => [['id' => '1']]]],
            'snapchat follows next_link' => ['snapchat', ['request_status' => 'SUCCESS', 'campaigns' => [], 'paging' => ['next_link' => $next]], ['request_status' => 'SUCCESS', 'campaigns' => []]],
            'tiktok counts pages' => ['tiktok', ['code' => 0, 'data' => ['list' => [['campaign_id' => '1']], 'page_info' => ['total_page' => 999]]], ['code' => 0, 'data' => ['list' => [['campaign_id' => '1']], 'page_info' => ['total_page' => 1]]]],
            'linkedin stops on a short page' => ['linkedin', ['elements' => array_fill(0, 500, ['id' => 1])], ['elements' => [['id' => 1]]]],
        ];
    }

    #[DataProvider('providers')]
    public function test_a_read_cut_off_by_the_ceiling_is_noted_and_a_finished_one_is_not(string $provider, array $endless, array $finished): void
    {
        Sleep::fake();
        $this->bwConfigure($provider);
        $tenant = $this->bwTenant();
        $connection = $this->bwConnection($tenant, $provider);
        /** @var ApiAdvertisingConnector $connector */
        $connector = app(AdvertisingConnectorRegistry::class)->get($provider)->withConnection($connection);

        $answer = $endless;
        Http::preventStrayRequests();
        Http::fake(static function (Request $request) use (&$answer) {
            return Http::response($answer, 200);
        });

        $connector->syncCampaigns('1');
        $this->assertNotSame([], $connector->takeTruncatedReads(), 'a read stopped at the ceiling said nothing');
        $this->assertSame([], $connector->takeTruncatedReads(), 'the note is drained, so it cannot be charged to the next window');

        $answer = $finished;
        $connector->syncCampaigns('1');
        $this->assertSame([], $connector->takeTruncatedReads(), 'a read that ran out of pages claimed to be cut off');
    }
}
