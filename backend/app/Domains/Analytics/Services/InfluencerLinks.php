<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\InfluencerProjectLink;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkDestinations;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ANALYTICS-DATA-001 §6 — mint ONE dedicated tracking link for an influencer inside a project.
 *
 * The link is a row of the existing short-link library («روابط التتبع»): same hop, same click counter, same
 * touchpoint evidence. What this adds is the FIXED UTM it is minted with — `utm_source=influencer`,
 * `utm_medium=influencer`, and a `utm_campaign` that is unique inside the project — and an optional coupon.
 * Because the campaign value is unique per project, an order that carries it can only be one influencer's; and
 * because it is written once and never edited, a link's meaning cannot drift under the orders already tied to it.
 *
 * One link per influencer per project: asking again refuses rather than minting a second value an order could
 * be split between.
 */
final class InfluencerLinks
{
    public const UTM_SOURCE = 'influencer';

    public const UTM_MEDIUM = 'influencer';

    public function __construct(
        private readonly ShortLinkDestinations $destinations,
        private readonly ShortLinkHops $hops,
    ) {}

    /**
     * @param  array{name: string, destination: string, influencer_id?: ?string, collaboration_id?: ?string, coupon_code?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function mint(string $tenantId, string $projectId, array $data, ?int $userId): InfluencerProjectLink
    {
        $name = trim($data['name']);
        $influencerId = ($data['influencer_id'] ?? null) ?: null;
        $collaborationId = ($data['collaboration_id'] ?? null) ?: null;
        $coupon = isset($data['coupon_code']) ? Str::upper(trim((string) $data['coupon_code'])) : null;
        $coupon = $coupon === '' ? null : $coupon;

        $influencer = null;
        if ($influencerId !== null) {
            // Tenant-scoped query: an influencer of another tenant is simply absent.
            $influencer = Influencer::query()->whereKey($influencerId)->first();
            if ($influencer === null) {
                throw ValidationException::withMessages(['influencer_id' => [__('Unknown influencer.')]]);
            }
            $name = $name === '' ? (string) $influencer->name : $name;
        }
        if ($collaborationId !== null) {
            $collab = InfluencerCollaboration::query()->whereKey($collaborationId)->first();
            if ($collab === null || ($influencerId !== null && (string) $collab->influencer_id !== (string) $influencerId)) {
                throw ValidationException::withMessages(['collaboration_id' => [__('Unknown collaboration.')]]);
            }
            $influencerId ??= (string) $collab->influencer_id;
        }

        $handle = $influencerId !== null ? 'roster-'.$influencerId : $this->slug($name);

        if (InfluencerProjectLink::query()->where('project_id', $projectId)->where('handle_slug', $handle)->exists()) {
            throw ValidationException::withMessages(['name' => [__('This influencer already has a link in this project.')]]);
        }
        if ($coupon !== null && InfluencerProjectLink::query()->where('project_id', $projectId)->whereRaw('upper(coupon_code) = ?', [$coupon])->exists()) {
            throw ValidationException::withMessages(['coupon_code' => [__('That coupon already belongs to another influencer here.')]]);
        }

        $campaign = $this->campaign($projectId, $name);
        $resolved = $this->destinations->resolve(ShortLink::KIND_LINK, (string) $data['destination'], null, [
            'utm_source' => self::UTM_SOURCE,
            'utm_medium' => self::UTM_MEDIUM,
            'utm_campaign' => $campaign,
        ]);

        try {
            $link = DB::transaction(function () use ($tenantId, $projectId, $resolved, $userId, $name, $handle, $influencerId, $collaborationId, $campaign, $coupon): InfluencerProjectLink {
                $short = ShortLink::create($resolved + [
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'slug' => $this->hops->mint(),
                    'custom_slug' => false,
                    'mode' => ShortLink::MODE_REDIRECT,
                    'created_by' => $userId,
                    'is_active' => true,
                ]);

                return InfluencerProjectLink::create([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'influencer_id' => $influencerId,
                    'collaboration_id' => $collaborationId,
                    'short_link_id' => (string) $short->getKey(),
                    'name' => mb_substr($name, 0, 120),
                    'handle_slug' => $handle,
                    'utm_source' => self::UTM_SOURCE,
                    'utm_medium' => self::UTM_MEDIUM,
                    'utm_campaign' => $campaign,
                    'coupon_code' => $coupon,
                    'is_active' => true,
                    'created_by' => $userId,
                ]);
            });
        } catch (QueryException) {
            // The unique indexes are the last word on «one per influencer» and «one campaign value per project».
            throw ValidationException::withMessages(['name' => [__('This influencer already has a link in this project.')]]);
        }

        app(AnalyticsSnapshots::class)->forget($tenantId, $projectId, [AnalyticsReads::INFLUENCERS, AnalyticsReads::ATTRIBUTION]);

        return $link;
    }

    /** The public address of the link, for the list. */
    public function shareUrl(ShortLink $short): string
    {
        return $this->hops->shareUrl($short);
    }

    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? mb_substr($slug, 0, 80) : 'creator-'.Str::lower(Str::random(8));
    }

    /** `inf_<ascii name>_<six characters>`, unique inside the project. */
    private function campaign(string $projectId, string $name): string
    {
        $ascii = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(24, '')->toString();
        $ascii = $ascii === '' ? 'creator' : $ascii;

        for ($i = 0; $i < 10; $i++) {
            $value = 'inf_'.$ascii.'_'.Str::lower(Str::random(6));
            if (! InfluencerProjectLink::query()->where('project_id', $projectId)->where('utm_campaign', $value)->exists()) {
                return $value;
            }
        }

        throw new \RuntimeException('Could not mint a unique influencer campaign value.');
    }
}
