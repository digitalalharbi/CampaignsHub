<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Reports\Services\ClientReportView;
use Tests\TestCase;

/**
 * The client-facing view must never leak internal content: unapproved recommendations, UUIDs,
 * checksums, technical terms — and, since CLIENT-REPORT-ENTITY-BOUNDARY-001, any campaign-management
 * entity at all.
 *
 * ## What changed, and why the old assertions were the wrong shape
 *
 * This class used to SANITISE names: «Meta — Lead Gen (burner)» became «Meta — Lead Gen» and was
 * handed to the client, and the test below asserted exactly that. The owner's correction is that the
 * name was never the problem — the container is. A campaign is the agency's own filing, the client
 * never chose it and does not manage it, and removing the embarrassing half of the label does not
 * change what the label is.
 *
 * So the assertions moved from «the marker is gone» to «the roster is gone», and the burner case is
 * kept as the stronger claim it now supports: not sanitised, absent.
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
        $this->assertStringNotContainsStringIgnoringCase('burner', json_encode($client, JSON_UNESCAPED_UNICODE));
    }

    /**
     * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the roster does not survive this view.
     *
     * The snapshot is the OLD shape on purpose: every report generated before this requirement is
     * still in the database and still served through this class, on the shared link, the PDF, the
     * spreadsheet and the client email. A boundary that only held for newly generated documents
     * would leave every existing one leaking.
     */
    public function test_no_campaign_survives_the_client_view(): void
    {
        $client = app(ClientReportView::class)->filter($this->internalSnapshot());

        $this->assertSame([], $client['campaigns']);
        $this->assertNull($client['best']['campaign']);
        $this->assertStringNotContainsString('Lead Gen', json_encode($client, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertStringNotContainsString('Google Search', json_encode($client, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * A friendlier label for an internal container is still the internal container.
     *
     * `client_display_name` was the earlier answer to this problem: let an operator write a nicer
     * name and show that instead. It is a fair feature and it is not a boundary — the client still
     * ends up reading a list of the agency's campaigns, one of which happens to be well named.
     */
    public function test_a_client_display_name_does_not_buy_a_campaign_a_place_in_the_report(): void
    {
        $snap = $this->internalSnapshot();
        $snap['campaigns'][0]['client_display_name'] = 'حملة اليوم الوطني';

        $client = app(ClientReportView::class)->filter($snap);

        $this->assertSame([], $client['campaigns']);
        $this->assertStringNotContainsString('حملة اليوم الوطني', json_encode($client, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * A sentence already written down, quoting a campaign, does not reach the client.
     *
     * This is the case the removal above cannot fix by emptying a key: the name is inside prose that
     * a previous release composed correctly, and there is no platform in the stored figures to
     * re-attribute the finding to. The line is dropped rather than served — a cost paid only by
     * documents generated before this requirement, since a report generated today states the same
     * finding by platform and keeps it.
     */
    public function test_a_stored_sentence_that_quotes_a_campaign_is_dropped(): void
    {
        $snap = $this->internalSnapshot();
        $snap['observations'] = [
            ['id' => 'a', 'kind' => 'budget_pace', 'scope' => ['type' => 'campaign', 'name' => 'National Day Sale'],
                'title' => 'حملة «National Day Sale» تستهلك الميزانية أبطأ من الخطة', 'detail' => 'صُرف 37,530.00 SAR.'],
            ['id' => 'b', 'kind' => 'reallocation', 'scope' => ['type' => 'platform', 'name' => 'meta'],
                'title' => 'ميتا تحقق أفضل عائد', 'detail' => 'انقل جزءًا من الميزانية.'],
        ];
        $snap['summary'] = ['تنبيه: حملة «Store Traffic» تنفق دون تحويلات.', 'الإنفاق ارتفع 12%.'];

        $client = app(ClientReportView::class)->filter($snap);

        $this->assertCount(1, $client['observations']);
        $this->assertSame('b', $client['observations'][0]['id'], 'the platform finding was dropped with the campaign one');
        $this->assertSame(['الإنفاق ارتفع 12%.'], $client['summary']);
    }

    /**
     * …and a sentence ABOUT campaigns in general survives.
     *
     * The methodology note every report carries says «تُدار الحملات وفق منهجية قائمة على الأداء». It
     * names nothing, and a filter broad enough to catch it would strip the report's own explanation
     * of how it was produced — which is the opposite of what a client is owed.
     */
    public function test_a_sentence_about_campaigns_in_general_survives(): void
    {
        $snap = $this->internalSnapshot();
        $snap['summary'] = ['تُدار الحملات وفق منهجية قائمة على الأداء.'];

        $client = app(ClientReportView::class)->filter($snap);

        $this->assertCount(1, $client['summary']);
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
