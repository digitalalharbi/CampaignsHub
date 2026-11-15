<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\External\Recipients\ExternalSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\ExternalFixtures;
use Tests\TestCase;

/** Spec §9 pre-send checks + review, §7 missing data, §16 «Protection», «Content», «Identity/privacy», log immutability. */
final class ExternalChecksAndReviewTest extends TestCase
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

    /** @return list<string> */
    private function statuses(): array
    {
        return DB::table('notification_delivery_log')->where('scope', 'external')->orderBy('seq')->pluck('status')->all();
    }

    public function test_the_first_three_messages_and_the_client_role_are_held_for_review_with_a_task_notice(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['reviews_remaining' => 3]);
        foreach (range(1, 4) as $i) {
            $this->raise('target.reached', $this->project->id);
            $this->drain();
        }
        $this->assertSame(['held_for_review', 'held_for_review', 'held_for_review', 'sent'], $this->statuses());
        $this->assertSame(['review:first_messages'], DB::table('notification_external_reviews')->distinct()->pluck('reason')->all());
        $this->assertSame(3, DB::table('notification_external_reviews')->count());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('event_key', 'external.held_for_review')->count()); // internal notice + task

        [, $client] = $this->subscribe('owner@client.test', 'client', [$this->project->id], ['review_mode' => true, 'event_keys' => json_encode(['continuity.planning'])]);
        $this->raise('continuity.planning', $this->project->id, null, ['date' => '2026-11-19']);
        $this->drain();
        $this->assertSame('review:mode', DB::table('notification_external_reviews')->where('subscription_id', $client)->value('reason'));
    }

    public function test_send_releases_the_held_message_skip_closes_it_and_no_decision_skips_it_with_a_notice(): void
    {
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['review_mode' => true]);
        $base = '/api/v1/settings/workspace/notifications/external/reviews';

        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $review = DB::table('notification_external_reviews')->first();
        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('data.0.id', $review->id)->assertJsonPath('data.0.recipient_email', 'finance@client.test');
        $this->actingAs($this->owner)->postJson("{$base}/{$review->id}/send")->assertOk();
        $this->drain();
        $this->assertSame(['held_for_review', 'sent'], $this->statuses());
        $this->assertCount(1, $this->sent());
        $this->assertSame((string) $this->owner->id, (string) DB::table('notification_external_reviews')->where('id', $review->id)->value('decided_by'));

        $this->raise('target.reached', $this->project->id, null, ['n' => 2]);
        $this->drain();
        $second = DB::table('notification_external_reviews')->where('status', 'pending')->value('id');
        $this->actingAs($this->owner)->postJson("{$base}/{$second}/skip")->assertOk();
        $this->assertSame('skipped', DB::table('notification_external_reviews')->where('id', $second)->value('status'));

        $this->raise('continuity.planning', $this->project->id);
        $this->drain();
        Carbon::setTestNow(now()->addHours(5));
        $this->artisan('notifications:v2-review-sweep')->assertSuccessful();
        $this->assertSame('window_expired', DB::table('notification_external_reviews')->where('event_key', 'continuity.planning')->value('outcome_reason'));
        $this->assertSame(1, DB::table('notification_outbox')->where('event_key', 'external.review_skipped')->where('channel', 'in_app')->count());
        $this->assertCount(1, $this->sent()); // nothing else left

        // Another workspace cannot decide this workspace's reviews.
        $other = $this->tenant('Beta', 'agency');
        $stranger = $this->member($other);
        $this->actingAs($stranger)->postJson("{$base}/{$review->id}/send")->assertStatus(422);
        $this->actingAs($stranger)->getJson($base.'?status=sent')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_data_of_another_project_is_held_for_privacy_even_after_a_review_release(): void
    {
        $other = $this->project($this->tenant, 'Other');
        $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->data->override = ['data_project_ids' => [$this->project->id, $other->id]];
        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $this->assertSame(['held_for_privacy'], $this->statuses());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('event_key', 'external.held_for_privacy')->count());
        $this->assertCount(0, $this->sent());

        // A producer that slips another project's id into the payload is caught too.
        $this->data->override = [];
        $this->freshRun();
        $this->raise('target.reached', $this->project->id, null, ['comparison_project_id' => $other->id]);
        $this->drain();
        $this->assertSame('privacy:data_outside_subscription', DB::table('notification_delivery_log')->orderByDesc('seq')->value('reason'));
    }

    public function test_missing_data_defers_two_hours_once_then_goes_with_updating(): void
    {
        $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->data->override = ['complete' => false, 'missing' => ['meta']];
        $this->raise('target.reached', $this->project->id);
        $this->assertSame(1, $this->drain()['retried']);
        $this->assertSame(['deferred'], $this->statuses());
        $this->assertSame(0, $this->drain()['sent']); // waits 2 h

        Carbon::setTestNow(now()->addMinutes(121));
        $this->assertSame(1, $this->drain()['sent']);
        $this->assertSame(['deferred', 'sent'], $this->statuses());
        $html = (string) $this->sent()[0]['email']->getHtmlBody();
        $this->assertStringContainsString('قيد التحديث — يصلك في الملخص القادم', $html);
        $this->assertStringNotContainsString('المؤشرات حسب الهدف', $html);
    }

    public function test_currency_without_rate_illogical_numbers_and_forbidden_wording_go_to_review(): void
    {
        $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $settings = app(ExternalSettings::class);
        $cases = [
            ['fx' => [['from' => 'EUR', 'to' => 'USD', 'rate' => null, 'rate_date' => null, 'kind' => null, 'withheld' => true]]],
            ['spend' => -5.0],
            ['spend' => 9999.0],                                                   // platforms do not add up to the total
            ['forbidden_words' => ['ملخص']],                                      // the workspace's own list meets the rendered text
            ['disclaimer' => ['ar' => 'أسبوع رائع 🎉']],                          // an emoji in an editable text
        ];
        foreach ($cases as $i => $override) {
            $this->data->override = array_key_exists('forbidden_words', $override) || array_key_exists('disclaimer', $override) ? [] : $override;
            if ($i === 3) {
                $settings->update($this->tenant->id, ['external' => ['forbidden_words' => ['ملخص']]]);
            }
            if ($i === 4) {
                $settings->update($this->tenant->id, ['external' => ['forbidden_words' => [], 'disclaimer' => $override['disclaimer']]]);
            }
            $this->freshRun();
            $this->raise('target.reached', $this->project->id, null, ['case' => $i]);
            $this->drain();
        }
        $this->assertSame([
            'check:currency_without_rate:EUR', 'check:illogical_numbers:spend', 'check:illogical_numbers:platforms_do_not_add_up', 'check:forbidden_word', 'check:emoji',
        ], DB::table('notification_delivery_log')->orderBy('seq')->where('scope', 'external')->pluck('reason')->all());
        $this->assertCount(0, $this->sent());
    }

    public function test_an_unconfirmed_recipient_gets_nothing_and_an_internal_notice_after_seven_days(): void
    {
        [$r] = $this->subscribe('new@client.test', 'finance', [$this->project->id], ['status' => 'pending'], ['status' => 'pending', 'consent' => 'none', 'confirmation_sent_at' => now()->subDays(8)]);
        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $this->assertCount(0, $this->sent());
        $this->assertSame([], $this->statuses());

        $this->artisan('notifications:v2-consent-sweep')->assertSuccessful();
        $this->assertSame(1, DB::table('notification_subscription_log')->where('recipient_id', $r)->where('action', 'no_confirmation_7d')->count());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('event_key', 'external.recipient_unconfirmed_7d')->count());
    }

    public function test_sensitive_operational_events_never_reach_an_external_recipient_and_the_log_is_immutable(): void
    {
        $this->subscribe('finance@client.test', 'finance', [$this->project->id], ['event_keys' => json_encode(['target.reached', 'budget.overrun', 'sync.failed'])]);
        foreach (['budget.overrun', 'sync.failed'] as $event) {
            $this->raise($event, $this->project->id);
        }
        $this->drain();
        $this->assertSame(0, DB::table('notification_outbox')->where('scope', 'external')->count());

        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $id = DB::table('notification_delivery_log')->where('scope', 'external')->value('id');
        $this->expectException(QueryException::class);
        DB::table('notification_delivery_log')->where('id', $id)->update(['status' => 'failed']);
    }

    public function test_an_archived_project_gets_no_external_message(): void
    {
        $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'archived']);
        $this->raise('target.reached', $this->project->id);
        $this->drain();
        $this->assertSame(0, DB::table('notification_outbox')->where('scope', 'external')->count());
    }
}
