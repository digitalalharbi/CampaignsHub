<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Reports\Services\ClientReportView;
use Tests\TestCase;

/**
 * The client-facing view must never leak internal content: unapproved recommendations, internal
 * campaign markers ("burner"), UUIDs, checksums or technical terms.
 */
final class ClientReportViewTest extends TestCase
{
    private function internalSnapshot(): array
    {
        return [
            'checksum' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'data_version' => 1,
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'kpis' => ['spend' => 100, 'revenue' => 400, 'conversions' => 5, 'roas' => 4.0, 'cpa' => 20.0],
            'campaigns' => [
                ['campaign_name' => 'Meta — Lead Gen (burner)', 'provider' => 'meta', 'spend' => 60],
                ['campaign_name' => 'Google Search — Brand', 'provider' => 'google', 'spend' => 40],
            ],
            'best' => ['campaign' => 'Meta — Lead Gen (burner)'],
            'findings' => [
                ['title' => 'أعلى ROAS على google', 'detail' => 'أفضل عائد.', 'status' => 'reviewed', 'type' => 'finding'],
            ],
            'recommendations' => [
                ['id' => 'a1', 'title' => 'زيادة ميزانية google', 'detail' => 'وسّع بحذر.', 'status' => 'approved', 'type' => 'recommendation'],
                ['id' => 'b2', 'title' => 'مراجعة Meta — Lead Gen (burner)', 'detail' => 'راجع التتبع.', 'status' => 'draft', 'type' => 'recommendation'],
            ],
        ];
    }

    public function test_client_view_strips_internal_content(): void
    {
        $client = app(ClientReportView::class)->filter($this->internalSnapshot());

        // internal keys gone
        $this->assertArrayNotHasKey('checksum', $client);
        $this->assertArrayNotHasKey('tenant_id', $client);
        $this->assertArrayNotHasKey('data_version', $client);
        // only approved recommendation remains
        $this->assertCount(1, $client['recommendations']);
        $this->assertSame('approved', $client['recommendations'][0]['status']);
        // internal marker "(burner)" removed from client-facing names
        $this->assertStringNotContainsStringIgnoringCase('burner', json_encode($client, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Meta — Lead Gen', $client['best']['campaign']);
    }

    public function test_client_display_name_wins_over_internal_name(): void
    {
        $snap = $this->internalSnapshot();
        $snap['campaigns'][0]['client_display_name'] = 'حملة اليوم الوطني';
        $client = app(ClientReportView::class)->filter($snap);

        // Explicit client name used; internal name + ids never exposed.
        $this->assertSame('حملة اليوم الوطني', $client['campaigns'][0]['campaign_name']);
        $this->assertArrayNotHasKey('client_display_name', $client['campaigns'][0]);
        $this->assertArrayNotHasKey('campaign_id', $client['campaigns'][0]);
        $this->assertStringNotContainsStringIgnoringCase('burner', json_encode($client, JSON_UNESCAPED_UNICODE));
    }

    public function test_content_validator_passes_client_view_and_catches_leaks(): void
    {
        $validator = app(ClientReportContentValidator::class);

        $client = app(ClientReportView::class)->filter($this->internalSnapshot());
        $this->assertTrue($validator->passes($client), json_encode($validator->scan($client)));

        // A leak (draft rec + burner + uuid) must be caught.
        $leaky = $client;
        $leaky['recommendations'][] = ['title' => 'internal burner note', 'status' => 'draft', 'type' => 'recommendation'];
        $leaky['campaigns'][0]['campaign_name'] = 'test campaign 22222222-2222-2222-2222-222222222222';
        $codes = array_column($validator->scan($leaky), 'code');
        $this->assertContains('unapproved_recommendation', $codes);
        $this->assertContains('internal_marker_burner', $codes);
        $this->assertContains('uuid', $codes);
    }
}
