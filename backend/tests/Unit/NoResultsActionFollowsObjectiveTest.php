<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Alerts\Services\AlertAnatomy;
use App\Domains\Notifications\Support\AlertEventText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ALERT-OBJECTIVE-RESULT-001 — «صرف بدون نتائج» tells the reader what to check for THIS objective. «حلويات برديسي» (traffic,
 * Snapchat swipes) was told «تحقق من تتبع التحويلات»: a visits campaign has no conversions to track. Conversion tracking is
 * the advice for sales and leads only.
 */
final class NoResultsActionFollowsObjectiveTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool}> family, result key, an Arabic phrase it must say, whether it may speak of conversions */
    public static function families(): array
    {
        return [
            'traffic' => ['traffic', 'landing_page_views', 'رابط الصفحة وسرعتها', false],
            'traffic on clicks' => ['traffic', 'clicks', 'رابط الصفحة وسرعتها', false],
            'awareness' => ['awareness', 'impressions', 'الاستهداف', false],
            'engagement' => ['engagement', 'engagements', 'المحتوى', false],
            'video' => ['video', 'video_views', 'الفيديو', false],
            'app' => ['app', 'installs', 'رابط المتجر', false],
            'leads' => ['leads', 'leads', 'النموذج', true],
            'sales' => ['sales', 'conversions', 'التحويلات', true],
        ];
    }

    #[DataProvider('families')]
    public function test_the_action_follows_the_objective(string $family, string $key, string $phrase, bool $conversionsAllowed): void
    {
        $ctx = ['spend' => 500.0, 'conversions' => 0.0, 'days' => 3, 'result_family' => $family, 'result_key' => $key];
        $ar = AlertEventText::plain('no_results', $ctx, true)['action'];
        $en = AlertEventText::plain('no_results', $ctx, false)['action'];

        $this->assertStringContainsString($phrase, $ar);
        if (! $conversionsAllowed) {
            $this->assertStringNotContainsString('التحويلات', $ar, $family);
            $this->assertStringNotContainsStringIgnoringCase('conversion', $en, $family);
        }
        if ($family === 'traffic') {
            // ALERT-CRITERIA-001 (§10أ) — the Owner's order: the content first, the tracking/link next, pausing last and only if needed.
            $this->assertSame('راجع المحتوى ← تحقق من رابط الصفحة وسرعتها ← أوقفها إن لزم', $ar);
        }

        // The finding's own anatomy carries the same advice and names the result it measured.
        $anatomy = AlertAnatomy::build('no_results', $ctx, 'warning');
        $this->assertSame($ar, $anatomy['anatomy']['action']['ar'] ?? null);
        $this->assertSame($key, $anatomy['evidence']['metric']);
    }

    /** An alert raised before the objective decided the result names no family: the advice stays general, never «التحويلات». */
    public function test_an_older_alert_without_a_family_is_not_told_to_check_conversions(): void
    {
        $ar = AlertEventText::plain('no_results', ['spend' => 500.0, 'conversions' => 0.0, 'days' => 3], true)['action'];

        $this->assertStringNotContainsString('التحويلات', $ar);
    }
}
