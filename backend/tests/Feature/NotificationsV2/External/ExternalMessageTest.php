<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Entitlements\PlanEntitlementGate;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\ExternalFixtures;
use Tests\TestCase;

/** Spec §8, §11–§13, §16 «Content» / «Identity/privacy» / «CC/BCC»: the external email as sent. */
final class ExternalMessageTest extends TestCase
{
    use BuildsWorkspaces;
    use ExternalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExternal();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_identity_privacy_footer_and_no_internal_links(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], [], ['salutation' => 'أستاذة سارة']);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->assertSame(1, $this->drain()['sent']);

        $mail = $this->sent()[0]['email'];
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();
        $this->assertSame('Launch — Alpha Agency | ملخص 1 نوفمبر – 14 نوفمبر 2026 · CampaignsHub', $mail->getSubject());
        $this->assertSame('Alpha Agency عبر كامبينز هَب', $mail->getFrom()[0]->getName());
        $this->assertSame('info@campaignshub.io', $mail->getFrom()[0]->getAddress());
        // The approved weekly spend-summary template in its external mode (Owner, 2026-11-15): the same header, cards and footer.
        $this->assertStringContainsString('ملخص الإنفاق', $html);
        $this->assertStringContainsString('data-block="spend-total"', $html);
        $this->assertStringContainsString('Alpha Agency · مشروع: Launch', $html);                     // workspace + project identity under the header
        $this->assertStringContainsString('مخصصة لك بصفتك المالية — بيانات Launch فقط', $html);       // the one-line privacy notice
        $this->assertStringContainsString('مرحبًا، أستاذة سارة', $html);                              // shown literally, no title added
        $this->assertStringContainsString('المرجع المالي: فواتير المنصات والمحاسبة المالية.', $html);
        $this->assertStringContainsString('المرجع المالي: فواتير المنصات والمحاسبة المالية.', $text);
        $this->assertSame(1, substr_count($html, 'data-unsubscribe="1"'));
        $this->assertStringContainsString('© '.date('Y').' CampaignsHub', $html);                      // the usual footer
        $this->assertMatchesRegularExpression('/N-[0-9]{6}-[A-Z0-9]{8}/', $text);
        $this->assertStringContainsString('List-Unsubscribe', $mail->getHeaders()->toString());

        // The only links: the signed unsubscribe and the click-counted footprint. No platform page, no button, no id route.
        preg_match_all('/href="([^"]+)"/', $html, $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $href) {
            $this->assertMatchesRegularExpression('#/n/(u|f)/#', html_entity_decode($href), $href);
        }
        $this->assertStringNotContainsString('class="ch-btn', $html);
        foreach (['مطلوب منك', 'أقر بالاستلام', 'آخر موعد', 'فشل', 'خسارة', 'عاجل', 'إنذار', 'تجاوزت', 'افتح', 'إعدادات الإشعارات'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->assertLessThan(100 * 1024, strlen($html));
        $this->assertSame(1, DB::table('usage_counters')->where('tenant_id', $this->tenant->id)->where('metric', 'notif.external_messages')->value('count'));
    }

    public function test_the_admin_platform_texts_reach_the_message_disclaimer_invitation_and_forbidden_words(): void
    {
        // RELEASE-INTEGRATION — builder R's admin page saves `notification_platform_texts`; builder Q's message reads them.
        $put = static fn (string $key, array $value) => DB::table('notification_platform_texts')->insert(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        $put('disclaimer.default', ['ar' => 'تنبيه المنصة من {workspace} للاطلاع.', 'en' => 'Platform note from {workspace}.']);
        $put('footprint.invitation', ['ar' => 'جرّب المنصة لفريقك', 'en' => 'Try it for your team']);
        $put('phrasing.dictionary', ['forbidden' => ['كلمةمحظورةللاختبار']]);

        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->assertSame(1, $this->drain()['sent']);
        $html = (string) $this->sent()[0]['email']->getHtmlBody();

        $this->assertStringContainsString('تنبيه المنصة من Alpha Agency للاطلاع.', $html);
        // Owner 2026-11-15 («مع تذييل التقارير المعتاد»): the external message ends with the usual footer; the footprint invitation sentence is
        // not rendered any more (the platform text stays editable in the admin page for a later use).
        $this->assertStringNotContainsString('جرّب المنصة لفريقك', $html);
        $this->assertContains('كلمةمحظورةللاختبار', app(ExternalSettings::class)->forbiddenWords($this->tenant->id));
    }

    public function test_a_long_custom_subject_is_cut_in_its_custom_part_only(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['subject_template' => str_repeat('ملخص طويل جدًا {period} ', 8)]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();

        $subject = $this->sent()[0]['email']->getSubject();
        $this->assertLessThanOrEqual(90, mb_strlen($subject));
        $this->assertStringStartsWith('Launch — Alpha Agency | ملخص طويل', $subject);
        $this->assertStringEndsWith('… · CampaignsHub', $subject);
    }

    public function test_a_client_with_two_projects_gets_two_separate_messages_naming_only_their_own_project(): void
    {
        $second = $this->project($this->tenant, 'Second Store');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        [, $s] = $this->subscribe('owner@client.test', 'client', [$this->project->id, $second->id]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->raise('digest.periodic', $second->id, $s);
        $this->assertSame(2, $this->drain()['sent']);

        $mails = $this->sent();
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $html = (string) $mail['email']->getHtmlBody();
            $own = str_contains((string) $mail['email']->getSubject(), 'Second Store') ? 'Second Store' : 'Launch';
            $other = $own === 'Launch' ? 'Second Store' : 'Launch';
            $this->assertStringContainsString($own, $html);
            $this->assertStringNotContainsString($other, $html);
            $this->assertStringNotContainsString('Ramadan Sale', $html); // no campaign names in a client message
        }
    }

    public function test_cc_and_bcc_each_get_their_own_envelope_and_unsubscribe_link_and_bcc_is_never_visible(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['cc' => json_encode(['team@alpha.test']), 'bcc' => json_encode(['audit@alpha.test', 'stranger@else.test'])]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->assertSame(1, $this->drain()['sent']);

        $mails = $this->sent();
        $this->assertCount(3, $mails); // stranger@else.test never confirmed: no copy
        $byRcpt = [];
        foreach ($mails as $m) {
            $this->assertCount(1, $m['rcpt']);
            $byRcpt[$m['rcpt'][0]] = $m['email'];
            $headers = $m['email']->getHeaders()->toString();
            $this->assertStringNotContainsString('audit@alpha.test', $headers);
            $this->assertStringContainsString('finance@client.test', $headers); // one unified header: To = the subscriber
            $this->assertStringContainsString('team@alpha.test', $headers);    // visible CC
        }
        $this->assertEqualsCanonicalizing(['finance@client.test', 'team@alpha.test', 'audit@alpha.test'], array_keys($byRcpt));
        $this->assertStringContainsString('نسخة للاطلاع', (string) $byRcpt['team@alpha.test']->getHtmlBody());
        $this->assertStringNotContainsString('نسخة للاطلاع', (string) $byRcpt['finance@client.test']->getHtmlBody());
        $links = array_map(static fn (Email $e): string => (string) preg_replace('/.*List-Unsubscribe: <([^>]+)>.*/s', '$1', $e->getHeaders()->toString()), $byRcpt);
        $this->assertCount(3, array_unique($links));
        $this->assertSame(3, DB::table('notification_delivery_log')->where('status', 'sent')->count()); // primary + 2 copies, each logged
    }

    public function test_the_approved_templates_carry_no_image_at_all(): void
    {
        // The approved digests and spend summaries draw with table cells: no chart image, no inline part, nothing a blocked-images client loses.
        [, $s] = $this->subscribe('pm@client.test', 'project_manager', [$this->project->id]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();
        $mail = $this->sent()[0]['email'];
        $this->assertStringNotContainsString('cid:', (string) $mail->getHtmlBody());
        $this->assertCount(0, $mail->getAttachments());
        $this->assertStringContainsString('ملخص المشروع', (string) $mail->getHtmlBody());   // the project summary template
    }

    public function test_the_display_currency_of_the_summary_is_the_templates_own_with_the_reference_in_the_text(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], [], ['language' => 'en']);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();

        $mail = $this->sent()[0]['email'];
        $text = (string) $mail->getTextBody();
        $this->assertSame('Alpha Agency via CampaignsHub', $mail->getFrom()[0]->getName());
        $this->assertStringContainsString('Spend summary', $text);
        $this->assertStringContainsString('Financial reference: platform invoices and accounting records.', $text);
        $this->assertStringContainsString('Unsubscribe', $text);
    }

    public function test_unsubscribe_type_project_all_and_reduce_leave_the_rest_and_notify_internally(): void
    {
        [$r, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $link = (string) preg_replace('/.*List-Unsubscribe: <([^>]+)>.*/s', '$1', $this->sent()[0]['email']->getHeaders()->toString());
        $path = parse_url($link, PHP_URL_PATH);

        $this->get($path)->assertOk()->assertSee('إيقاف هذا النوع من الرسائل');
        $this->assertSame('active', DB::table('notification_subscriptions')->where('id', $s)->value('status')); // a GET changes nothing
        $this->post($path, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $sub = DB::table('notification_subscriptions')->where('id', $s)->first();
        $this->assertSame(['continuity.planning'], json_decode((string) $sub->event_keys, true));
        $this->assertSame('active', $sub->status);
        $this->assertSame('active', DB::table('notification_external_recipients')->where('id', $r)->value('status'));

        $this->post($path, ['action' => 'reduce'])->assertOk();
        $this->assertSame('weekly', DB::table('notification_subscriptions')->where('id', $s)->value('frequency'));
        $this->post($path, ['action' => 'all'])->assertOk();
        $this->assertSame('cancelled', DB::table('notification_external_recipients')->where('id', $r)->value('status'));
        $this->assertEqualsCanonicalizing(['unsubscribed', 'reduced', 'unsubscribed'], DB::table('notification_subscription_log')->whereIn('action', ['unsubscribed', 'reduced'])->pluck('action')->all());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('event_key', 'external.recipient_unsubscribed')->count());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('event_key', 'external.recipient_reduced')->count());

        // A forged token is refused.
        $this->get(substr((string) $path, 0, -3).'abc')->assertNotFound();
    }

    public function test_footprint_click_is_counted_and_redirects_with_utm_and_removal_key_drops_it(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();
        preg_match('#href="([^"]+/n/f/[^"]+)"#', (string) $this->sent()[0]['email']->getHtmlBody(), $m);
        $this->get(parse_url(html_entity_decode($m[1]), PHP_URL_PATH))->assertRedirect('https://campaignshub.io/?utm_source=email&utm_medium=notification&utm_campaign=digest.periodic');
        $this->assertSame(1, DB::table('notification_footprint_clicks')->where('tenant_id', $this->tenant->id)->count());

        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'branding.remove_platform_footprint', 'enabled' => true, 'grace_days' => 0, 'keep_existing' => false, 'created_at' => now(), 'updated_at' => now()]);
        $gate = app(EntitlementGate::class);
        assert($gate instanceof PlanEntitlementGate);
        $gate->flush();
        $this->raise('digest.periodic', $this->project->id, $s, ['period' => ['key' => 'yesterday', 'from' => '2026-11-14', 'to' => '2026-11-14']]);
        $this->drain();
        $last = $this->sent()[1]['email'];
        $this->assertStringNotContainsString('CampaignsHub', (string) $last->getSubject());
        $this->assertSame('Alpha Agency', $last->getFrom()[0]->getName());
    }
}
