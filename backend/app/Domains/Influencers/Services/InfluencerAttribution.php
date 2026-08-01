<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Influencers\Models\InfluencerDeliverableResult;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tracking links, discount codes, and what each post did (INFL-003).
 *
 * The line this class holds is the one the contract cares about: **a click is measured, a redemption
 * is reported.** This platform serves the redirect, so it counts clicks itself; a discount code is
 * redeemed in the brand's own store, which it cannot see. Both numbers appear in the same table and
 * they are not the same kind of fact, so the second one always carries where it came from.
 */
final class InfluencerAttribution
{
    /**
     * No `0`/`O`, `1`/`l`/`I`: the codes get read aloud, typed off a phone screen and printed, and a
     * pair that looks identical is a customer whose discount does not work.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Mint a tracking link or a discount code.
     *
     * A link needs somewhere to go — one without a destination is a URL that 404s for whoever the
     * creator sends it to, which is worse than not issuing it.
     */
    public function issue(
        InfluencerCollaboration $collaboration,
        string $kind,
        array $data,
        ?int $userId,
    ): InfluencerTrackingAsset {
        if (! in_array($kind, InfluencerTrackingAsset::KINDS, true)) {
            throw new RuntimeException('A tracking asset is a link or a discount code.');
        }

        if ($kind === 'link' && trim((string) ($data['destination_url'] ?? '')) === '') {
            throw new RuntimeException('A tracking link needs a destination.');
        }

        $asset = InfluencerTrackingAsset::create([
            'collaboration_id' => $collaboration->getKey(),
            'deliverable_id' => $data['deliverable_id'] ?? null,
            'kind' => $kind,
            'code' => $this->mintCode($collaboration, $data['code'] ?? null),
            'destination_url' => $data['destination_url'] ?? null,
            'discount_type' => $kind === 'discount_code' ? ($data['discount_type'] ?? 'percent') : null,
            'discount_value' => $kind === 'discount_code' ? ($data['discount_value'] ?? null) : null,
            // A code starts life knowing nothing about its redemptions, and says so.
            'redemptions_source' => $kind === 'discount_code' ? 'awaiting_credentials' : 'manual',
            'created_by' => $userId,
        ]);

        $this->audit->log(
            action: 'influencer.tracking.issued',
            entityType: InfluencerTrackingAsset::class,
            entityId: (string) $asset->getKey(),
            after: ['kind' => $kind, 'code' => $asset->code],
            userId: $userId,
        );

        return $asset->refresh();
    }

    /**
     * Resolve a public code, WITHOUT a tenant in context, and count the click.
     *
     * The redirect is hit by strangers — the creator's followers — so there is no session and no
     * tenant to scope by. That is only safe because the code is globally unique and is the entire
     * key; the scope is deliberately dropped here and nowhere else.
     *
     * An inactive or unknown code resolves to null and the caller sends the visitor somewhere
     * neutral. Nothing is created, and no tenant data leaks into a public response.
     */
    public function resolveAndCount(string $code): ?InfluencerTrackingAsset
    {
        $asset = InfluencerTrackingAsset::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('code', $code)
            ->where('kind', 'link')
            ->where('is_active', true)
            ->first();

        if ($asset === null) {
            return null;
        }

        // An atomic increment, not read-modify-write: this endpoint is the one thing here that can
        // be hit hard and concurrently, and a lost click is a number a creator gets paid on.
        InfluencerTrackingAsset::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($asset->getKey())
            ->update(['clicks' => DB::raw('clicks + 1'), 'last_clicked_at' => Carbon::now()]);

        return $asset->refresh();
    }

    /**
     * Record what the store said a code did.
     *
     * `manual` is a person typing a number they read somewhere else, and it is stored as exactly
     * that. It is never labelled as measured, because this platform did not measure it.
     */
    public function recordRedemptions(InfluencerTrackingAsset $asset, int $redemptions, ?int $userId): InfluencerTrackingAsset
    {
        if ($asset->kind !== 'discount_code') {
            throw new RuntimeException('Only a discount code has redemptions; a link has clicks, which are counted here.');
        }

        $asset->forceFill([
            'redemptions' => max(0, $redemptions),
            'redemptions_source' => 'manual',
            'redemptions_updated_at' => Carbon::now(),
        ])->save();

        $this->audit->log(
            action: 'influencer.tracking.redemptions_recorded',
            entityType: InfluencerTrackingAsset::class,
            entityId: (string) $asset->getKey(),
            after: ['redemptions' => $redemptions, 'source' => 'manual'],
            userId: $userId,
        );

        return $asset->refresh();
    }

    /**
     * Record what one post did.
     *
     * Keyed on (deliverable, source), so re-entering a figure corrects the existing row rather than
     * stacking a second one — and a platform sync landing later sits BESIDE the manual number
     * instead of overwriting somebody's work.
     */
    public function recordResult(
        InfluencerDeliverable $deliverable,
        array $data,
        string $source,
        ?int $userId,
    ): InfluencerDeliverableResult {
        if (! in_array($source, InfluencerDeliverableResult::SOURCES, true)) {
            throw new RuntimeException('A result is recorded by hand or synced from a platform.');
        }

        $result = InfluencerDeliverableResult::query()
            ->where('deliverable_id', $deliverable->getKey())
            ->where('source', $source)
            ->first() ?? new InfluencerDeliverableResult;

        $result->forceFill([
            'tenant_id' => $result->tenant_id ?? $deliverable->tenant_id,
            'deliverable_id' => $deliverable->getKey(),
            'source' => $source,
            // Null is kept as null rather than coerced to 0: «not known» and «none» are different
            // answers, and a chart cannot tell them apart once one has become the other.
            'impressions' => $data['impressions'] ?? null,
            'reach' => $data['reach'] ?? null,
            'engagements' => $data['engagements'] ?? null,
            'clicks' => $data['clicks'] ?? null,
            'conversions' => $data['conversions'] ?? null,
            'revenue' => $data['revenue'] ?? null,
            'currency' => $data['currency'] ?? null,
            'measured_at' => $data['measured_at'] ?? Carbon::now(),
            'recorded_by' => $userId,
            'note' => $data['note'] ?? null,
        ])->save();

        $this->audit->log(
            action: 'influencer.deliverable.result_recorded',
            entityType: InfluencerDeliverableResult::class,
            entityId: (string) $result->getKey(),
            after: ['source' => $source, 'deliverable_id' => (string) $deliverable->getKey()],
            userId: $userId,
        );

        return $result->refresh();
    }

    /**
     * A short, unique, human-readable code.
     *
     * Retried against the unique index rather than trusted: `code` is what appears in a public URL,
     * so a collision would send one brand's traffic into another's report — the one failure mode
     * here that is worse than an error.
     */
    private function mintCode(InfluencerCollaboration $collaboration, ?string $preferred): string
    {
        if ($preferred !== null && trim($preferred) !== '') {
            $code = Str::upper(preg_replace('/[^A-Za-z0-9-]/', '', $preferred) ?? '');

            if ($code !== '' && ! $this->codeTaken($code)) {
                return $code;
            }

            throw new RuntimeException('That code is already in use.');
        }

        $prefix = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', (string) $collaboration->title) ?: 'CH', 0, 3));

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = $prefix.'-'.$this->randomBlock(6);

            if (! $this->codeTaken($code)) {
                return $code;
            }
        }

        throw new RuntimeException('A unique tracking code could not be generated.');
    }

    private function codeTaken(string $code): bool
    {
        return InfluencerTrackingAsset::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('code', $code)
            ->exists();
    }

    private function randomBlock(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
