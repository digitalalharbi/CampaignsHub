<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\BotClassifier;
use App\Domains\Tracking\Services\TrackingRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TRACKING-EVENTS-001 — one measured interaction per row, off until the workspace turns advanced tracking on, and
 * never able to change what the visitor is shown.
 */
final class TrackingEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'trk-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    private function switch(string $scope, ?string $id, bool $on, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant;
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'scope_type' => $scope, 'scope_key' => $id ?? $tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => $id, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $tenant->id);
    }

    /** @param array<string,mixed> $extra */
    private function event(array $extra = [], ?Request $request = null): ?TrackingEvent
    {
        return app(TrackingRecorder::class)->record(array_merge([
            'tenant_id' => (string) $this->tenant->id, 'project_id' => (string) $this->project->id,
            'event_type' => 'short_link_redirect', 'subject_type' => 'short_link', 'subject_id' => (string) Str::uuid(),
            'source' => 'short_link', 'consent_state' => 'not_required',
        ], $extra), $request);
    }

    private function request(string $ua = 'Mozilla/5.0 (iPhone) Safari/604', string $ip = '203.0.113.9'): Request
    {
        return Request::create('/l/x', 'GET', server: ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => $ip]);
    }

    public function test_nothing_is_recorded_until_advanced_tracking_is_switched_on(): void
    {
        $this->assertNull($this->event(), 'entitled but not activated records nothing');
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());

        $this->switch('tenant', null, true);
        $this->assertNotNull($this->event());
        $this->assertSame(1, TrackingEvent::withoutGlobalScopes()->count());
    }

    public function test_a_workspace_that_was_never_entitled_records_nothing_even_if_a_switch_exists(): void
    {
        $stranger = Tenant::create(['name' => 'S', 'slug' => 'str-'.uniqid(), 'status' => 'active']);
        $this->switch('tenant', null, true, $stranger);

        $this->assertNull(app(TrackingRecorder::class)->record([
            'tenant_id' => (string) $stranger->id, 'event_type' => 'report_view', 'subject_type' => 'report_share',
            'subject_id' => (string) Str::uuid(), 'source' => 'report_share',
        ]));
    }

    public function test_a_project_can_switch_tracking_off_beneath_an_agency_that_turned_it_on(): void
    {
        $this->switch('tenant', null, true);
        $this->assertNotNull($this->event());

        $this->switch('project', (string) $this->project->id, false);
        $this->assertNull($this->event(), 'the project is off, so nothing is recorded for it');
        $this->assertNotNull($this->event(['project_id' => null]), 'the rest of the workspace is unaffected');
    }

    public function test_a_visitor_identifier_is_kept_only_with_consent(): void
    {
        $this->switch('tenant', null, true);

        $with = $this->event(['consent_state' => 'granted', 'visitor_key' => 'vk-123'], $this->request());
        $this->assertSame('vk-123', $with->visitor_key);
        $this->assertSame('consented_id', $with->visitor_confidence);
        $this->assertNull($with->visitor_hash);

        foreach (['denied', 'unknown', 'not_required'] as $state) {
            $row = $this->event(['consent_state' => $state, 'visitor_key' => 'vk-123'], $this->request());
            $this->assertNull($row->visitor_key, "{$state} keeps no identifier");
            $this->assertSame('daily_hash', $row->visitor_confidence, $state);
        }

        $bare = $this->event();
        $this->assertSame('none', $bare->visitor_confidence, 'with no request there is nothing to recognise');
    }

    public function test_the_hash_separates_two_devices_on_a_day_and_cannot_follow_one_across_days(): void
    {
        $this->switch('tenant', null, true);
        $monday = Carbon::parse('2026-10-19 10:00:00');

        $a1 = $this->event(['occurred_at' => $monday], $this->request());
        $a2 = $this->event(['occurred_at' => $monday->copy()->addHour()], $this->request());
        $b = $this->event(['occurred_at' => $monday], $this->request(ip: '198.51.100.7'));
        $aTomorrow = $this->event(['occurred_at' => $monday->copy()->addDay()], $this->request());

        $this->assertSame($a1->visitor_hash, $a2->visitor_hash, 'the same device twice in a day');
        $this->assertNotSame($a1->visitor_hash, $b->visitor_hash, 'a different device');
        $this->assertNotSame($a1->visitor_hash, $aTomorrow->visitor_hash, 'the same device the next day is a different hash');
    }

    public function test_no_address_agent_or_full_url_is_stored(): void
    {
        $this->switch('tenant', null, true);
        $row = $this->event(['destination_type' => 'whatsapp', 'destination_host' => 'WA.me'], $this->request('Mozilla/5.0 Secret-Agent-String', '203.0.113.77'));

        $stored = json_encode($row->getAttributes());
        $this->assertStringNotContainsString('203.0.113.77', (string) $stored);
        $this->assertStringNotContainsString('Secret-Agent-String', (string) $stored);
        $this->assertSame('wa.me', $row->destination_host);
        $this->assertSame('other', $row->ua_family);
    }

    public function test_a_replayed_event_is_one_row_and_dedupe_is_per_workspace(): void
    {
        $this->switch('tenant', null, true);
        $a = $this->event(['dedupe_key' => 'evt-1']);
        $b = $this->event(['dedupe_key' => 'evt-1']);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, TrackingEvent::withoutGlobalScopes()->count());

        $other = Tenant::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        $this->switch('tenant', null, true, $other);
        $c = app(TrackingRecorder::class)->record([
            'tenant_id' => (string) $other->id, 'event_type' => 'short_link_redirect', 'subject_type' => 'short_link',
            'subject_id' => (string) Str::uuid(), 'source' => 'short_link', 'dedupe_key' => 'evt-1',
        ]);
        $this->assertNotSame($a->id, $c->id);
    }

    public function test_unknown_event_types_and_missing_fields_are_refused_at_the_door(): void
    {
        $this->switch('tenant', null, true);
        $this->expectException(\InvalidArgumentException::class);
        $this->event(['event_type' => 'page_peek']);
    }

    public function test_announced_machines_are_bots_and_the_in_app_browsers_of_the_ad_platforms_are_people(): void
    {
        foreach ([
            '' => 'missing_user_agent',
            'facebookexternalhit/1.1' => 'link_preview',
            'WhatsApp/2.23.20.0 A' => 'link_preview',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => 'declared_crawler',
            'TikTokSpider' => 'declared_crawler',
            'SnapchatAdsReview/1.0' => 'declared_crawler',
            'curl/8.4.0' => 'automation_tool',
            'Mozilla/5.0 HeadlessChrome/120' => 'automation_tool',
        ] as $ua => $reason) {
            $c = BotClassifier::classify((string) $ua);
            $this->assertTrue($c['is_bot'], $ua);
            $this->assertSame($reason, $c['reason'], $ua);
        }

        foreach ([
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit Mobile/15E148 Snapchat/12.50.0.37',
            'Mozilla/5.0 (Linux; Android 13) AppleWebKit Chrome/120 Mobile Safari/537.36 trill_2023 TikTok/31.0',
            'Mozilla/5.0 (iPhone) AppleWebKit Mobile/15E148 Instagram 300.0.0.0',
            'Mozilla/5.0 (Linux; Android 13) AppleWebKit Chrome/120 Mobile [FB_IAB/FB4A;FBAV/450.0]',
            'Mozilla/5.0 (Macintosh) AppleWebKit/605 Version/17 Safari/605',
        ] as $ua) {
            $this->assertFalse(BotClassifier::classify($ua)['is_bot'], $ua);
        }
    }

    // ── the short link, which is the first thing measured ────────────────────────────────────────────────

    private function link(array $extra = []): ShortLink
    {
        return ShortLink::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'slug' => substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 7),
            'kind' => 'whatsapp', 'destination' => 'https://wa.me/966500000009?text=hi', 'mode' => 'redirect', 'is_active' => true,
        ], $extra));
    }

    public function test_a_short_link_open_is_recorded_with_its_destination_host_only_and_the_response_is_the_same_for_a_bot(): void
    {
        $this->switch('tenant', null, true);
        $link = $this->link();

        $person = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604'])->get('/l/'.$link->slug);
        $bot = $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get('/l/'.$link->slug);

        $person->assertStatus(302)->assertRedirect('https://wa.me/966500000009?text=hi');
        $bot->assertStatus(302)->assertRedirect('https://wa.me/966500000009?text=hi');
        $this->assertSame($person->headers->get('Location'), $bot->headers->get('Location'), 'a machine is shown exactly what a person is shown');

        $events = TrackingEvent::withoutGlobalScopes()->orderBy('created_at')->get();
        $this->assertCount(2, $events);
        $this->assertFalse($events[0]->is_bot);
        $this->assertTrue($events[1]->is_bot);
        $this->assertSame('declared_crawler', $events[1]->bot_reason);
        $this->assertSame('whatsapp', $events[0]->destination_type);
        $this->assertSame('wa.me', $events[0]->destination_host);
        $this->assertSame('short_link_redirect', $events[0]->event_type);
        $this->assertSame((string) $link->getKey(), $events[0]->subject_id);
        $this->assertStringNotContainsString('966500000009', (string) json_encode($events[0]->getAttributes()), 'the number is not in the evidence');

        // The existing counter is what it always was: both opens counted.
        $this->assertSame(2, (int) ShortLink::withoutGlobalScopes()->whereKey($link->getKey())->value('clicks'));
    }

    public function test_with_tracking_off_the_link_still_works_and_still_counts_and_records_nothing_new(): void
    {
        $link = $this->link();

        $this->get('/l/'.$link->slug)->assertStatus(302);

        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
        $this->assertSame(1, (int) ShortLink::withoutGlobalScopes()->whereKey($link->getKey())->value('clicks'));
    }

    public function test_landing_mode_is_recorded_as_an_open_and_an_inactive_link_records_nothing(): void
    {
        $this->switch('tenant', null, true);
        $landing = $this->link(['mode' => 'landing']);
        $this->get('/l/'.$landing->slug)->assertOk();
        $this->assertSame('short_link_open', TrackingEvent::withoutGlobalScopes()->value('event_type'));

        $off = $this->link(['is_active' => false]);
        $this->get('/l/'.$off->slug)->assertStatus(302);
        $this->assertSame(1, TrackingEvent::withoutGlobalScopes()->count());
    }

    public function test_a_failing_recorder_never_breaks_the_redirect(): void
    {
        $this->switch('tenant', null, true);
        $link = $this->link();
        Schema::drop('tracking_events');

        $this->get('/l/'.$link->slug)->assertStatus(302)->assertRedirect('https://wa.me/966500000009?text=hi');
    }
}
