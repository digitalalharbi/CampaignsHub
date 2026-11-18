<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Checks;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\PreSendCheck;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Notifications\External\Review\ReviewQueue;
use Illuminate\Support\Facades\DB;

/**
 * The automated checks run right before an external message leaves (spec §8, §9, §7) — one `PreSendCheck` on M's
 * `notifications.presend` tag, in this order:
 *
 * 1. privacy — any figure of a project outside the recipient's subscription (or, for the client role, any project but
 *    this one) → `held_for_privacy`, internal notice. Never overridable, not even by a reviewer's «أرسل».
 * 2. incomplete data → `deferred` 2 h, once; on the retry the message goes with «قيد التحديث — يصلك في الملخص القادم».
 * 3. currency without a rate (FxEngine withheld a figure) → review.
 * 4. illogical numbers (negative money or results, a ratio out of range, platforms not adding up to the total) → review.
 * 5. mismatch with the screen — the composed figures against a fresh read of the screens' own source → review.
 * 6. forbidden words (platform list + workspace additions), emoji, action-request phrases → review.
 * 7. review mode — on for the subscription (client role by default) or within its first N messages → review.
 *
 * Checks 3–7 are skipped for a message a person released from review: they asked for it to go as it is.
 */
final class ExternalPreSendChecks implements PreSendCheck
{
    /** The emoji definition shared with `NoEmojiInNotificationsTest` (U+2713–2718 check/cross glyphs are typography). */
    public const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{FE0F}\x{20E3}\x{2B00}-\x{2BFF}\x{231A}\x{231B}\x{23E9}-\x{23FA}]|(?![\x{2713}-\x{2718}])[\x{2600}-\x{27BF}]/u';

    public function __construct(
        private readonly MessageComposer $composer,
        private readonly ExternalSettings $settings,
        private readonly ReviewQueue $reviews,
        private readonly DigestDataSource $data,
        private readonly NotificationEngine $engine,
    ) {}

    public function check(Delivery $delivery): ?array
    {
        if ($delivery->scope !== Scope::External) {
            return null;
        }
        $m = $this->composer->compose($delivery);
        if (! $m->externalReady || $m->facts === []) {
            return null; // not this pipeline's message: the engine skips it as `external_template_not_installed`
        }
        $f = $m->facts;

        if (($why = $this->privacy($delivery, $f)) !== null) {
            if ($delivery->projectId !== null) {
                $this->engine->raise(new DeliveryIntent(
                    eventKey: 'external.held_for_privacy', tenantId: $delivery->tenantId, projectId: $delivery->projectId, objectType: 'delivery', objectId: $delivery->id,
                    payload: ['recipient' => (string) $delivery->recipient->address, 'reference' => $delivery->reference],
                ));
            }

            return [DeliveryStatus::HeldForPrivacy, $why];
        }

        $released = isset($delivery->payload['_review_released']);
        if (! $released && ($f['complete'] ?? true) !== true && ! isset($delivery->payload['_data_retry'])) {
            return [DeliveryStatus::Deferred, 'data_incomplete', (int) config('notifications_v2.external.data_retry_minutes', 120)];
        }
        if ($released) {
            return null;
        }

        $reason = $this->currency($f) ?? $this->numbers($f) ?? $this->screen($delivery, $f) ?? $this->content($delivery, $m) ?? $this->reviewMode($delivery);
        if ($reason === null) {
            return null;
        }
        $this->reviews->hold($delivery, $reason);

        return [DeliveryStatus::HeldForReview, $reason];
    }

    /** @param array<string, mixed> $f */
    private function privacy(Delivery $d, array $f): ?string
    {
        $allowed = array_map('strval', (array) ($f['subscription_project_ids'] ?? []));
        if ($d->projectId === null || ! in_array($d->projectId, $allowed, true)) {
            return 'privacy:project_outside_subscription';
        }
        $seen = array_map('strval', (array) ($f['data_project_ids'] ?? []));
        foreach ($this->payloadProjectIds($d->payload) as $p) {
            $seen[] = $p;
        }
        $limit = ($f['is_client'] ?? false) === true ? [$d->projectId] : $allowed;
        foreach (array_unique($seen) as $p) {
            if (! in_array($p, $limit, true)) {
                return 'privacy:data_outside_subscription';
            }
        }

        return null;
    }

    /** Any project id a producer put in the payload (`project_id`, `*_project_id`, `project_ids`). @return list<string> */
    private function payloadProjectIds(array $payload): array
    {
        $out = [];
        array_walk_recursive($payload, function ($v, $k) use (&$out): void {
            if (is_string($k) && ($k === 'project_id' || str_ends_with($k, '_project_id')) && is_string($v) && $v !== '') {
                $out[] = $v;
            }
        });
        foreach ((array) ($payload['project_ids'] ?? []) as $p) {
            if (is_string($p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $f */
    private function currency(array $f): ?string
    {
        foreach ((array) ($f['fx'] ?? []) as $fx) {
            if (($fx['withheld'] ?? false) === true) {
                return 'check:currency_without_rate:'.($fx['from'] ?? '?');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $f */
    private function numbers(array $f): ?string
    {
        $d = (array) ($f['data'] ?? []);
        $neg = static fn ($v): bool => is_numeric($v) && ((float) $v < 0 || is_nan((float) $v) || is_infinite((float) $v));
        foreach (['spend', 'previous_spend'] as $k) {
            if ($neg($d[$k] ?? null)) {
                return 'check:illogical_numbers:'.$k;
            }
        }
        foreach ((array) ($d['objectives'] ?? []) as $o) {
            foreach (['spend', 'result', 'cost_per_result', 'revenue', 'roas'] as $k) {
                if ($neg($o[$k] ?? null)) {
                    return 'check:illogical_numbers:objective_'.$k;
                }
            }
        }
        $ratio = $d['budget']['used_ratio'] ?? null;
        if (is_numeric($ratio) && ((float) $ratio < 0 || (float) $ratio > 50)) {
            return 'check:illogical_numbers:budget_ratio';
        }
        $platforms = array_sum(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), (array) ($d['platforms'] ?? [])));
        $total = is_numeric($d['spend'] ?? null) ? (float) $d['spend'] : null;
        if ($total !== null && $platforms > 0 && abs($platforms - $total) > max(0.01, $total * 0.01)) {
            return 'check:illogical_numbers:platforms_do_not_add_up';
        }

        return null;
    }

    /** The figures in the message against a fresh read of the screens' source for the same window (max 0.01 apart). @param array<string, mixed> $f */
    private function screen(Delivery $d, array $f): ?string
    {
        $sent = (array) ($f['data'] ?? []);
        if ($sent === [] || $d->projectId === null || ! isset($f['period']['from'], $f['period']['to'])) {
            return null;
        }
        $fresh = $this->data->project($d->tenantId, $d->projectId, (string) $f['period']['from'], (string) $f['period']['to'], (string) $f['currency']);
        $close = static fn ($a, $b): bool => ($a === null && $b === null) || (is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) <= 0.01);
        if (! $close($sent['spend'] ?? null, $fresh['spend'] ?? null)) {
            return 'check:mismatch_with_screen:spend';
        }
        $byObjective = [];
        foreach ((array) ($fresh['objectives'] ?? []) as $o) {
            $byObjective[(string) $o['objective']] = $o;
        }
        foreach ((array) ($sent['objectives'] ?? []) as $o) {
            $other = $byObjective[(string) $o['objective']] ?? null;
            if ($other === null || ! $close($o['result'] ?? null, $other['result'] ?? null) || ! $close($o['cost_per_result'] ?? null, $other['cost_per_result'] ?? null)) {
                return 'check:mismatch_with_screen:'.$o['objective'];
            }
        }

        return null;
    }

    private function content(Delivery $d, ComposedMessage $m): ?string
    {
        $text = $m->subject."\n".$m->text;
        foreach ($this->settings->forbiddenWords($d->tenantId) as $word) {
            $hit = preg_match('/^[\x00-\x7F]+$/', $word) === 1
                ? preg_match('/\b'.preg_quote($word, '/').'\b/iu', $text) === 1
                : mb_stripos($text, $word) !== false;
            if ($hit) {
                return 'check:forbidden_word';
            }
        }
        if (preg_match(self::EMOJI, $text) === 1) {
            return 'check:emoji';
        }
        foreach (ExternalSettings::ACTION_PHRASES as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                return 'check:action_request';
            }
        }

        return null;
    }

    private function reviewMode(Delivery $d): ?string
    {
        if ($d->subscriptionId === null) {
            return null;
        }
        $sub = DB::table('notification_subscriptions')->where('tenant_id', $d->tenantId)->where('id', $d->subscriptionId)->first(['review_mode', 'reviews_remaining']);
        if ($sub === null) {
            return null;
        }
        if ((int) $sub->reviews_remaining > 0) {
            DB::table('notification_subscriptions')->where('id', $d->subscriptionId)->where('reviews_remaining', '>', 0)->decrement('reviews_remaining');

            return 'review:first_messages';
        }

        return $sub->review_mode === true || $sub->review_mode === 1 || $sub->review_mode === '1' ? 'review:mode' : null;
    }
}
