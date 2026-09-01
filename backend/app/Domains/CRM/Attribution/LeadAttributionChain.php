<?php

declare(strict_types=1);

namespace App\Domains\CRM\Attribution;

use App\Domains\CRM\Models\Lead;

/**
 * Where one lead came from, rung by rung — LEAD-SOURCE-ATTRIBUTION-001.
 *
 * «Every lead can name the content, ad set, campaign and platform it came from» has a second half
 * that matters more than the first: **or say why it cannot**. A dash in a table is the answer that
 * loses the client's trust, because it cannot be told apart from a bug. So every rung here comes
 * back in one of four states, and the state is the product:
 *
 *   - `named`        the provider sent it, and here it is;
 *   - `not_offered`  this provider does not return this rung on a lead, and here is why;
 *   - `missing`      the provider DOES return it and this lead has not got it — a real defect,
 *                    surfaced as one rather than hidden behind the same dash as the others;
 *   - `no_platform`  no platform is claiming this lead at all: it was typed in, imported, or came
 *                    off a website form, and the chain is whatever that route carried.
 *
 * ## What this refuses to do
 *
 * It never reads a metrics table. The temptation is real — an insights row for the same day carries
 * a campaign id and an ad id, and joining to it would fill every dash on the screen. It would also
 * be a lie: those rows count clicks, and **a click is not a person**. A lead attributed by proximity
 * in time, or by dividing a count, is a fabricated fact wearing a real id, and it would be
 * indistinguishable on screen from one the provider actually sent. Nothing in this class opens a
 * metrics model, and a test holds that shut.
 *
 * ## Why the names are the ones stored on the lead
 *
 * A campaign gets renamed. The lead keeps the name as it read at ingestion, and this reports that
 * name, because a report about last quarter must say what the campaign was called when the lead
 * arrived. The id is reported alongside precisely so a reader who needs today's name can follow it.
 */
final class LeadAttributionChain
{
    /**
     * @return array{
     *     route: string,
     *     route_label: string,
     *     route_label_en: string,
     *     platform: array{state: string, provider: string|null, label: string|null, label_en: string|null},
     *     rungs: list<array{rung: string, state: string, id: string|null, name: string|null, reason: string|null, reason_en: string|null}>,
     *     complete: bool,
     *     web: array<string, string>,
     * }
     */
    public function for(Lead $lead): array
    {
        $provider = $lead->provider === null || $lead->provider === '' ? null : (string) $lead->provider;
        $origin = $this->origin($lead, $provider);

        return [
            'route' => $origin->value,
            'route_label' => $origin->label(),
            'route_label_en' => $origin->labelEn(),
            'platform' => $this->platform($provider),
            'rungs' => array_map(
                fn (string $rung): array => $this->rung($lead, $provider, $rung),
                ProviderAttribution::RUNGS,
            ),
            /*
             * Complete means «nothing is missing that could have been here», NOT «all four rungs are
             * named». A LinkedIn lead with no ad set is complete: LinkedIn has no ad sets. Judging it
             * against a four-rung ideal would mark every LinkedIn lead defective forever, and an
             * alarm that is always on is one nobody reads.
             */
            'complete' => $this->complete($lead, $provider),
            'web' => $this->web($lead),
        ];
    }

    /**
     * How this lead reached us.
     *
     * A provider id is the evidence for a native form: something on the platform's side generated it.
     * Without one, a landing page or a UTM says a website form, and everything else is somebody's
     * hands — which is an honest origin, not a lesser one.
     */
    private function origin(Lead $lead, ?string $provider): LeadOrigin
    {
        if ($provider !== null && $lead->provider_lead_id !== null) {
            return LeadOrigin::NativeForm;
        }

        if ($lead->landing_page !== null || $lead->utm_source !== null || $lead->click_id !== null) {
            return LeadOrigin::WebsiteForm;
        }

        return $lead->source === 'import' ? LeadOrigin::Imported : LeadOrigin::Manual;
    }

    /**
     * @return array{state: string, provider: string|null, label: string|null, label_en: string|null}
     */
    private function platform(?string $provider): array
    {
        if ($provider === null) {
            return ['state' => 'no_platform', 'provider' => null, 'label' => null, 'label_en' => null];
        }

        /*
         * A provider we have never modelled is named and marked, not silently trusted. It is a real
         * state — a lead can arrive carrying a provider string from an import — and the honest report
         * is «it says this, and we cannot vouch for what that platform supplies».
         */
        return [
            'state' => ProviderAttribution::known($provider) ? 'named' : 'unrecognised',
            'provider' => $provider,
            'label' => $this->label($provider),
            'label_en' => $this->labelEn($provider),
        ];
    }

    /**
     * @return array{rung: string, state: string, id: string|null, name: string|null, reason: string|null, reason_en: string|null}
     */
    private function rung(Lead $lead, ?string $provider, string $rung): array
    {
        [$idColumn, $nameColumn] = match ($rung) {
            'creative' => ['external_creative_id', 'creative_name'],
            'ad' => ['external_ad_id', 'ad_name'],
            'adset' => ['external_adset_id', 'adset_name'],
            default => ['external_campaign_id', 'campaign_name'],
        };

        $id = $this->text($lead->{$idColumn});
        $name = $this->text($lead->{$nameColumn});

        if ($id !== null || $name !== null) {
            return ['rung' => $rung, 'state' => 'named', 'id' => $id, 'name' => $name, 'reason' => null, 'reason_en' => null];
        }

        if ($provider === null) {
            return ['rung' => $rung, 'state' => 'no_platform', 'id' => null, 'name' => null, 'reason' => null, 'reason_en' => null];
        }

        if (! ProviderAttribution::offers($provider, $rung)) {
            return [
                'rung' => $rung,
                'state' => 'not_offered',
                'id' => null,
                'name' => null,
                'reason' => ProviderAttribution::limit($provider, $rung),
                'reason_en' => ProviderAttribution::limitEn($provider, $rung),
            ];
        }

        // The provider does send this and it is not here. Say so as a gap, not as a dash.
        return ['rung' => $rung, 'state' => 'missing', 'id' => null, 'name' => null, 'reason' => null, 'reason_en' => null];
    }

    private function complete(Lead $lead, ?string $provider): bool
    {
        foreach (ProviderAttribution::RUNGS as $rung) {
            if ($this->rung($lead, $provider, $rung)['state'] === 'missing') {
                return false;
            }
        }

        return true;
    }

    /**
     * The web-side trail, only where the link actually carried one.
     *
     * Empty keys are dropped rather than reported as null: a UTM that was never set is not a gap in
     * the chain, it is a link that did not use UTMs, and listing five empty rows implies otherwise.
     *
     * @return array<string, string>
     */
    private function web(Lead $lead): array
    {
        $trail = [];

        foreach ([
            'landing_page' => $lead->landing_page,
            'utm_source' => $lead->utm_source,
            'utm_medium' => $lead->utm_medium,
            'utm_campaign' => $lead->utm_campaign,
            'utm_content' => $lead->utm_content,
            'utm_term' => $lead->utm_term,
            'click_id' => $lead->click_id,
        ] as $key => $value) {
            $value = $this->text($value);

            if ($value !== null) {
                $trail[$key] = $value;
            }
        }

        return $trail;
    }

    /** The English name, for the English mode. Unknown providers keep the string they arrived with. */
    private function labelEn(string $provider): string
    {
        return match ($provider) {
            'meta' => 'Meta',
            'snapchat' => 'Snapchat',
            'tiktok' => 'TikTok',
            'google' => 'Google Ads',
            'linkedin' => 'LinkedIn',
            'x' => 'X',
            default => $provider,
        };
    }

    private function label(string $provider): string
    {
        return match ($provider) {
            'meta' => 'ميتا',
            'snapchat' => 'سناب شات',
            'tiktok' => 'تيك توك',
            'google' => 'إعلانات جوجل',
            'linkedin' => 'لينكدإن',
            'x' => 'إكس',
            default => $provider,
        };
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
