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
use App\Domains\Notifications\Support\MailGallery;
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
                    $this->assertStringContainsString('Al-Waha Store', $m->subject);
                    $this->assertLessThanOrEqual(90, mb_strlen($m->subject));
                    if ($theme === 'light') {
                        $inbox[$locale][] = ['sender' => (string) $m->senderName, 'subject' => $m->subject, 'preheader' => mb_substr($m->body, 0, 90), 'template' => $name];
                    }
                    if ($out !== null) {
                        // The preview file shows the approved template in its external mode on FIXTURE figures (the composed message above is
                        // built from the project's own figures, which this test database does not hold); light and dark are the same file — the
                        // approved templates have no dark variant beyond the header band.
                        $html = (string) MailGallery::externalDemo($name, $locale)?->render();
                        // The brand banner is served from the app origin in a real mail; a file preview inlines the same asset.
                        $html = (string) preg_replace_callback('#src="[^"]*/brand/email/(banner-(?:ar|en))\.png"#', static fn (array $b): string => 'src="data:image/png;base64,'.base64_encode((string) file_get_contents(base_path('../frontend/public/brand/email/'.$b[1].'.png'))).'"', $html);
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

    /**
     * Owner, 2026-11-15: the external message is the approved template with EVERY route back to the platform removed — a summary only.
     * No button, no link into the app, no decision list, no connection prompt; only the signed one-click unsubscribe.
     */
    public function test_an_external_message_has_no_route_back_to_the_platform(): void
    {
        foreach (['finance', 'project_manager', 'admin_owner', 'client', 'achievement', 'continuity_planning', 'month_end'] as $template) {
            foreach (['ar', 'en'] as $locale) {
                $html = (string) MailGallery::externalDemo($template, $locale)?->render();
                $this->assertNotSame('', $html, $template);
                preg_match_all('/href="([^"]+)"/', $html, $m);
                $this->assertSame(1, count($m[1]), "{$template}.{$locale}: only the unsubscribe link may remain, found ".implode(' ', $m[1]));
                $this->assertStringContainsString('data-unsubscribe="1"', $html);
                $this->assertStringNotContainsString('class="ch-btn', $html, "{$template}.{$locale}: a CTA button");
                $this->assertDoesNotMatchRegularExpression('#/app/|/agency/|/login#', $html, "{$template}.{$locale}: a platform path");
                foreach (['افتح', 'Open the', 'Open budgets', 'يحتاج قرارك', 'Needs your decision', 'إعدادات الإشعارات', 'Notification settings'] as $internal) {
                    $this->assertStringNotContainsString($internal, $html, "{$template}.{$locale}: «{$internal}»");
                }
                $this->assertStringContainsString($locale === 'ar' ? 'المرجع المالي: فواتير المنصات والمحاسبة المالية.' : 'Financial reference: platform invoices and accounting records.', $html);
                $this->assertStringContainsString('Nour Agency', $html);            // the workspace identity under the header
                $this->assertStringContainsString($locale === 'ar' ? 'مخصصة لك بصفتك' : 'For you as', $html); // the one-line privacy notice
                $this->assertSame(0, preg_match(ExternalPreSendChecks::EMOJI, $html), $template);
                foreach (['تجاوزت', 'فشل', 'خسارة', 'قصور', 'إنذار', 'عاجل', 'مشكلة'] as $alarm) {
                    $this->assertStringNotContainsString($alarm, $html, "{$template}.{$locale}: alarm wording «{$alarm}»");
                }
            }
        }
    }
}
