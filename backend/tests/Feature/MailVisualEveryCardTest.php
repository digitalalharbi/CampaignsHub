<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Support\AlertMailItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALERT-VISUAL-EVERY-CARD-001 — `docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md` §9: every notification card carries a graphic
 * (bars, a pace bar, a count, or a status ring with its timeline) — never a text-only card, «رجعت الأمور طبيعية» included.
 */
final class MailVisualEveryCardTest extends TestCase
{
    /** The card-level blocks a mail template can draw; whichever template draws one must draw it through the shared partial. */
    private const CARD_BLOCKS = ['data-block="alert-card"', 'data-block="issue"', 'data-block="linking"', 'data-block="linking-platforms"'];

    public function test_every_template_that_draws_a_card_includes_the_shared_visual(): void
    {
        $offenders = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/mail')));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $draws = array_filter(self::CARD_BLOCKS, static fn (string $b): bool => str_contains($source, $b));
            if ($draws === []) {
                continue;
            }
            $checked++;
            if (! str_contains($source, "@include('mail.partials.visual'")) {
                $offenders[] = str_replace(resource_path('views/'), '', $file->getPathname()).' draws '.implode(', ', $draws).' with no visual';
            }
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'the alert card, the decision cards and both «ملخص الربط» sections');
        $this->assertSame([], $offenders, "A notification card must carry a graphic:\n".implode("\n", $offenders));
    }

    /** Opened, recovered with a figure, recovered with none, linking, budget: each rendered card holds a graphic. */
    public function test_every_rendered_alert_card_holds_a_graphic_including_the_recovery(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 05:00:00', 'UTC'));
        $item = static fn (string $type, array $ctx, string $triggered = '2026-11-12T05:00:00+00:00', string $severity = 'warning'): array => [
            'event_id' => (string) Str::uuid(), 'notification_type' => 'performance', 'rule_type' => $type, 'entity_type' => '', 'entity_id' => '',
            'severity' => $severity, 'title' => $type, 'title_ar' => null, 'detail' => '', 'detail_ar' => null, 'project_id' => null,
            'project_name' => 'razzahavenu', 'triggered_at' => $triggered, 'context' => $ctx, 'priority' => 'P2',
        ];
        $items = array_map(static fn (array $i): array => AlertMailItem::build($i, true), [
            $item('ctr_drop', ['ctr_current' => 0.012, 'ctr_previous' => 0.02, 'window_from' => '2026-11-11', 'window_to' => '2026-11-11', 'provider' => 'snapchat']),
            $item('roas_drop', ['roas_current' => 2.0, 'roas_previous' => 4.0, 'window_from' => '2026-11-05', 'window_to' => '2026-11-11', 'opened_at' => '2026-11-10T05:00:00+00:00', 'recovered_at' => '2026-11-12T05:00:00+00:00'], 'recovered:2026-11-12T05:00:00+00:00', 'info'),
            $item('cpa_increase', ['opened_at' => '2026-11-09T05:00:00+00:00', 'recovered_at' => '2026-11-12T05:00:00+00:00'], 'recovered:2026-11-12T05:00:00+00:00', 'info'),
            $item('sync_failure', ['provider' => 'google', 'link_state' => 'not_synced', 'required_day' => '2026-11-11', 'last_day' => '2026-11-10'], '2026-11-12T05:00:00+00:00', 'critical'),
            $item('budget_risk', ['consumed' => 900.0, 'limit' => 1000.0, 'threshold' => 80, 'currency' => 'SAR']),
        ]);

        $html = (new AlertBundleMail($items, 'ar', 'مدير'))->render();
        preg_match_all('/data-block="alert-card".*?(?=data-block="alert-card"|$)/us', $html, $cards);

        $this->assertCount(5, $cards[0]);
        foreach ($cards[0] as $card) {
            $this->assertMatchesRegularExpression('/data-viz="(before_now|pace|count|ring)"/', $card, 'a card with no graphic');
        }
        // The recovery with a figure draws it, dated; the one without draws the ring with «بدأ … ← انحلّ …».
        $this->assertSame(2, substr_count($html, 'data-viz="before_now"'), 'the opened CTR card and the RECOVERED ROAS card both draw their figure');
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-viz="ring"'), 'the recovery with no figure and the link draw the ring');
        $text = preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html)));
        $this->assertStringContainsString('انحلّ', $text);
        $this->assertStringContainsString('بدأ', $text);
        Carbon::setTestNow();
    }
}
