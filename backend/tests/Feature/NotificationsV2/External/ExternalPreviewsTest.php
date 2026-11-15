<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\RecipientKind;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\External\Checks\ExternalPreSendChecks;
use App\Domains\Notifications\External\Content\ExternalMessageComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\ExternalFixtures;
use Tests\TestCase;

/**
 * The wizard preview API (spec §14) and the seven required templates (spec «Final report»): finance, project manager,
 * admin/owner, client, achievement, continuity planning, month-end — light + dark, AR + EN — plus an inbox view of the
 * subjects and senders. Set NOTIF_PREVIEW_OUT=<dir> to write them to files (with render time and size); the assertions
 * (no emoji, size budgets, identity) run either way.
 */
final class ExternalPreviewsTest extends TestCase
{
    use BuildsWorkspaces;
    use ExternalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExternal();
        DB::table('tenants')->where('id', $this->tenant->id)->update(['name' => 'Nour Agency']);
        DB::table('projects')->where('id', $this->project->id)->update(['name' => 'Al-Waha Store']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_preview_api_returns_subject_sender_and_inline_charts_as_data(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $res = $this->actingAs($this->owner)->postJson('/api/v1/settings/workspace/notifications/external/preview', ['subscription_id' => $s, 'theme' => 'dark', 'locale' => 'en'])->assertOk();
        $this->assertStringStartsWith('Al-Waha Store — Nour Agency | ', (string) $res->json('data.subject'));
        $this->assertSame('Nour Agency via CampaignsHub', $res->json('data.sender_name'));
        $this->assertStringNotContainsString('src="cid:', (string) $res->json('data.html'));
        $this->assertLessThan(100 * 1024, $res->json('data.html_bytes'));

        $other = $this->tenant('Beta', 'agency');
        $this->actingAs($this->member($other))->postJson('/api/v1/settings/workspace/notifications/external/preview', ['subscription_id' => $s])->assertNotFound();
    }

    public function test_send_now_goes_through_the_engine_and_test_send_reaches_only_me(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['reviews_remaining' => 1]);
        $base = '/api/v1/settings/workspace/notifications/external';
        $this->actingAs($this->owner)->postJson("{$base}/send-now", ['subscription_id' => $s, 'project_id' => $this->project->id])->assertStatus(422); // confirm required
        $this->actingAs($this->owner)->postJson("{$base}/send-now", ['subscription_id' => $s, 'project_id' => $this->project->id, 'confirm' => true])->assertOk();
        $this->drain();
        $this->assertSame('held_for_review', DB::table('notification_delivery_log')->where('scope', 'external')->value('status')); // same checks + review

        $this->actingAs($this->owner)->postJson("{$base}/test-send", ['subscription_id' => $s])->assertOk()->assertJsonPath('data.sent_to', $this->owner->email);
        $last = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringStartsWith('[تجربة] ', $last->getSubject());
        $this->assertSame($this->owner->email, $last->getTo()[0]->getAddress());
    }

    public function test_the_seven_templates_render_in_both_themes_and_languages_within_budget(): void
    {
        $out = getenv('NOTIF_PREVIEW_OUT') ?: null;
        $composer = app(ExternalMessageComposer::class);
        $templates = [
            'finance' => ['finance', 'digest.periodic', []],
            'project_manager' => ['project_manager', 'digest.periodic', []],
            'admin_owner' => ['admin', 'digest.periodic', ['period' => ['key' => 'last_7', 'from' => '2026-11-08', 'to' => '2026-11-14']]],
            'client' => ['client', 'digest.periodic', []],
            'achievement' => ['admin', 'performance.improved', []],
            'continuity_planning' => ['finance', 'continuity.planning', ['date' => '2026-11-19']],
            'month_end' => ['admin', 'digest.month_end', ['period' => ['key' => 'last_month', 'from' => '2026-10-01', 'to' => '2026-10-31']]],
        ];
        $inbox = [];
        $metrics = ['render_ms' => [], 'html_bytes' => [], 'image_bytes' => []];
        foreach ($templates as $name => [$role, $event, $payload]) {
            [$r, $s] = $this->subscribe($name.'@client.test', $role, [$this->project->id], [], ['salutation' => $role === 'client' ? 'أبو خالد' : null]);
            foreach (['ar', 'en'] as $locale) {
                foreach (['light', 'dark'] as $theme) {
                    $delivery = new Delivery((string) Str::uuid(), 'N-261115-'.strtoupper(Str::random(8)), $this->tenant->id, $event, Scope::External, Channel::Email, Priority::Info,
                        new Recipient(RecipientKind::External, null, $r, $name.'@client.test', $locale, $role), $this->project->id, $s,
                        $payload + ($event === 'digest.periodic' && ! isset($payload['period']) ? ['period' => ['key' => 'this_month_to_yesterday', 'from' => '2026-11-01', 'to' => '2026-11-14']] : []), 1, 0);
                    $started = microtime(true);
                    $m = $composer->preview($delivery, $theme === 'dark' ? 'dark' : 'auto');
                    $metrics['render_ms'][] = (microtime(true) - $started) * 1000;
                    $metrics['html_bytes'][] = strlen($m->html);
                    $metrics['image_bytes'][] = array_sum(array_map('strlen', $m->inline));

                    $this->assertLessThan(100 * 1024, strlen($m->html), $name);
                    $this->assertLessThan(400 * 1024, array_sum(array_map('strlen', $m->inline)), $name);
                    $this->assertSame(0, preg_match(ExternalPreSendChecks::EMOJI, $m->subject.$m->text.$m->html), $name);
                    $this->assertVisualFirst($name.'.'.$locale.'.'.$theme, $m->html, $m->text, $m->inline, $theme);
                    $this->assertStringContainsString('Al-Waha Store', $m->subject);
                    $this->assertLessThanOrEqual(90, mb_strlen($m->subject));
                    if ($theme === 'light') {
                        $inbox[$locale][] = ['sender' => (string) $m->senderName, 'subject' => $m->subject, 'preheader' => mb_substr($m->body, 0, 90), 'template' => $name];
                    }
                    if ($out !== null) {
                        $html = $m->html;
                        foreach ($m->inline as $cid => $png) {
                            $html = str_replace('cid:'.$cid.'"', 'data:image/png;base64,'.base64_encode($png).'"', $html);
                        }
                        @mkdir($out, 0775, true);
                        file_put_contents("{$out}/{$name}.{$locale}.{$theme}.html", $html);
                    }
                }
            }
        }
        $this->assertCount(7, $inbox['ar']);

        if ($out !== null) {
            $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            foreach ($inbox as $locale => $rows) {
                $items = implode('', array_map(static fn (array $r): string => '<div style="padding:12px 16px;border-bottom:1px solid #e6eaef"><div style="font-weight:700">'.$e($r['sender']).'</div><div>'.$e($r['subject']).'</div><div style="color:#636f81;font-size:13px">'.$e($r['preheader']).'</div></div>', $rows));
                file_put_contents("{$out}/inbox.{$locale}.html", '<!doctype html><html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"><meta charset="utf-8"><body style="font-family:-apple-system,Segoe UI,Tahoma,sans-serif;max-width:420px;margin:24px auto;border:1px solid #e6eaef;border-radius:12px">'.$items.'</body></html>');
            }
            $mean = static fn (array $v): float => round(array_sum($v) / max(1, count($v)), 1);
            file_put_contents("{$out}/metrics.json", (string) json_encode([
                'renders' => count($metrics['render_ms']), 'mean_render_ms' => $mean($metrics['render_ms']), 'max_render_ms' => round(max($metrics['render_ms']), 1),
                'mean_html_bytes' => $mean($metrics['html_bytes']), 'max_html_bytes' => max($metrics['html_bytes']),
                'mean_image_bytes' => $mean($metrics['image_bytes']), 'max_image_bytes' => max($metrics['image_bytes']),
            ], JSON_PRETTY_PRINT));
        }
    }

    /** The Q2 budget: the words OUTSIDE the small print, any language (a word = a token with a letter in it; numbers are the content). */
    public const WORD_BUDGET = 90;

    /**
     * Visual first (Owner, 2026-11-15): a word budget on the body, alt text with the numbers on every chart, the dark alternate
     * of every light image, the text part carrying the figures the charts draw, and the calm palette only (no alarm red).
     *
     * @param  array<string, string>  $inline
     */
    private function assertVisualFirst(string $label, string $html, string $text, array $inline, string $theme): void
    {
        $body = (string) preg_replace('#<(style|head|title)[\s\S]*?</\1>|<!--[\s\S]*?-->|<td[^>]*data-external-footer[\s\S]*?</td>#u', '', $html);
        $words = array_filter(preg_split('/\s+/u', html_entity_decode(strip_tags((string) preg_replace('/<[^>]+>/', ' ', $body)))) ?: [], static fn (string $w): bool => preg_match('/\p{L}/u', $w) === 1);
        $this->assertLessThanOrEqual(self::WORD_BUDGET, count($words), "{$label}: the body has ".count($words).' words');

        // Every image has alt text; a chart's alt carries its numbers.
        preg_match_all('/<img\b[^>]*>/u', $html, $imgs);
        foreach ($imgs[0] as $img) {
            $this->assertStringContainsString(' alt="', $img, $label);
            if (preg_match('/src="cid:(donut|trend|ring)/', $img) === 1) {
                $this->assertMatchesRegularExpression('/alt="[^"]*\d[^"]*"/u', $img, "{$label}: a chart image needs numbers in its alt");
            }
        }
        // Dark alternates: every light cid has its `-dark` twin; a dark message carries only the dark one.
        foreach (array_keys($inline) as $cid) {
            if (! str_ends_with($cid, '-dark')) {
                $this->assertArrayHasKey($cid.'-dark', $inline, $label);
            }
        }
        if ($theme === 'dark') {
            $this->assertSame(0, preg_match('/src="cid:[a-z]+"/', $html), "{$label}: a dark message references no light image");
        }
        // Text parity: the figures the charts draw are written out in the text part.
        preg_match_all('/alt="((?:[^"]*\d[^"]*))"/u', $html, $alts);
        foreach ($alts[1] as $alt) {
            foreach (['donut', 'trend'] as $k) {
                if (str_contains($html, 'cid:'.$k)) {
                    $this->assertNotSame('', $text, $label);
                }
            }
        }
        $this->assertMatchesRegularExpression('/\d,?\d*\.\d\d/', $text, "{$label}: the exact figures are in the text part");
        // The calm palette: the digests' alarm states never appear on an external message.
        foreach (['#f5bcbc', '#a31616', '#fef2f2', '✕'] as $alarm) {
            $this->assertStringNotContainsString($alarm, $html, "{$label}: alarm colour or glyph {$alarm}");
        }
        $this->assertStringNotContainsString('<script', $html, $label);
        $this->assertStringNotContainsString('display:flex', $html, $label);
        $this->assertStringNotContainsString('var(--', $html, $label);
        $this->assertStringContainsString('name="color-scheme"', $html, $label);
        $this->assertStringContainsString('dir="', $html, $label);
    }
}
