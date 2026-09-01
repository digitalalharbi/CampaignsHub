<?php

declare(strict_types=1);

namespace App\Domains\CRM\Attribution;

/**
 * Which rungs each provider supplies ON THE LEAD — LEAD-SOURCE-ATTRIBUTION-001.
 *
 * The single table the whole attribution story is read from. Everything downstream — the chain, the
 * API, the screen — asks this rather than guessing from what happens to be populated, so that a rung
 * nobody can supply reads as a stated limit and a rung that should be there and is not reads as a
 * defect.
 *
 * ## What the entries mean, and what they deliberately do not
 *
 * These describe the LEAD DELIVERY surface, not the reporting surface. Every one of these providers
 * will happily tell you the campaign and ad behind an aggregate insights row; none of that is
 * relevant here, because a lead is a person and an insights row is a count. Joining the two by time
 * or by volume would produce exactly the fabricated identity this requirement exists to forbid.
 *
 * ## Why several providers are empty
 *
 * An empty list is a real, honest answer: the provider does not hand us leads at all, so a lead
 * carrying its name arrived by some other route — a website form, an import, an operator — and the
 * chain is whatever that route supplied. It is emphatically NOT «we have not written the code yet»;
 * where that is the case the provider is absent from this table entirely and {@see supplies()}
 * refuses to answer for it.
 */
final class ProviderAttribution
{
    /**
     * Provider → the rungs its lead deliveries carry, in chain order.
     *
     * Meta returns the full hierarchy on a lead-form submission, including which creative was on
     * screen. LinkedIn's Lead Sync returns the campaign and the creative but has no separate ad-set
     * rung to return — its own hierarchy has none, so «absent» there is a fact about LinkedIn's model
     * rather than about the lead. Snapchat returns the form and the campaign. TikTok's lead delivery
     * is gated behind an authorisation this install does not hold, so it is listed with what the API
     * documents it returns and is never treated as evidence until a real delivery proves it.
     *
     * @var array<string, list<string>>
     */
    private const SUPPLIES = [
        'meta' => ['campaign', 'adset', 'ad', 'creative', 'form'],
        'linkedin' => ['campaign', 'creative', 'form'],
        'snapchat' => ['campaign', 'adset', 'ad', 'form'],
        'tiktok' => ['campaign', 'adset', 'ad', 'form'],
        'google' => ['campaign', 'form'],
        // X has no lead-form product on the advertising API this connector speaks to.
        'x' => [],
    ];

    /**
     * The same sentences in English — LOCALE-PARITY.
     *
     * The product is Arabic-first and has an English mode, and a reason that only exists in Arabic
     * turns into an empty cell for an English reader — which is the unexplained dash this whole table
     * exists to prevent, reintroduced by the back door. The test pins both languages together so a
     * new provider cannot be added in one of them.
     *
     * @var array<string, array<string, string>>
     */
    private const LIMITS_EN = [
        'linkedin' => [
            'adset' => 'LinkedIn has no separate ad-set level; the campaign is the lowest one.',
            'ad' => 'In LinkedIn the creative IS the ad; there is no separate ad id.',
        ],
        'snapchat' => [
            'creative' => 'Snapchat does not return the creative id with a lead, only the ad that carried it.',
        ],
        'tiktok' => [
            'creative' => 'TikTok does not return the creative id with a lead, only the ad that carried it.',
        ],
        'google' => [
            'adset' => 'Google lead forms arrive at campaign level; no ad group is returned.',
            'ad' => 'Google lead forms arrive at campaign level; no ad is returned.',
            'creative' => 'Google lead forms arrive at campaign level; no creative is returned.',
        ],
        'x' => [
            'campaign' => 'X does not offer lead forms through the advertising API used here.',
            'adset' => 'X does not offer lead forms through the advertising API used here.',
            'ad' => 'X does not offer lead forms through the advertising API used here.',
            'creative' => 'X does not offer lead forms through the advertising API used here.',
            'form' => 'X does not offer lead forms through the advertising API used here.',
        ],
    ];

    /**
     * Why a provider cannot supply a rung, in the provider's own terms.
     *
     * A reason is required for every rung a provider is missing, and the test that pins this table
     * refuses an unexplained gap — an operator reading «—» deserves to know whether to go looking or
     * to stop looking, and that is exactly the difference these sentences carry.
     *
     * @var array<string, array<string, string>>
     */
    private const LIMITS = [
        'linkedin' => [
            'adset' => 'لا تملك لينكدإن مستوى مجموعات إعلانية منفصلًا؛ الحملة هي المستوى الأدنى.',
            // In LinkedIn's model the creative IS the ad; there is no second id to report.
            'ad' => 'المحتوى نفسه هو الإعلان في لينكدإن؛ لا يوجد معرّف إعلان منفصل.',
        ],
        'snapchat' => [
            'creative' => 'لا تُرجع سناب شات معرّف المحتوى مع بيانات النموذج، وإنما الإعلان الذي حمله.',
        ],
        'tiktok' => [
            'creative' => 'لا تُرجع تيك توك معرّف المحتوى مع بيانات النموذج، وإنما الإعلان الذي حمله.',
        ],
        'google' => [
            'adset' => 'نماذج جوجل تصل على مستوى الحملة؛ لا تُرجع المجموعة الإعلانية.',
            'ad' => 'نماذج جوجل تصل على مستوى الحملة؛ لا تُرجع الإعلان.',
            'creative' => 'نماذج جوجل تصل على مستوى الحملة؛ لا تُرجع المحتوى.',
        ],
        'x' => [
            'campaign' => 'لا تقدّم منصة إكس نماذج عملاء عبر واجهة الإعلانات المستخدمة هنا.',
            'adset' => 'لا تقدّم منصة إكس نماذج عملاء عبر واجهة الإعلانات المستخدمة هنا.',
            'ad' => 'لا تقدّم منصة إكس نماذج عملاء عبر واجهة الإعلانات المستخدمة هنا.',
            'creative' => 'لا تقدّم منصة إكس نماذج عملاء عبر واجهة الإعلانات المستخدمة هنا.',
            'form' => 'لا تقدّم منصة إكس نماذج عملاء عبر واجهة الإعلانات المستخدمة هنا.',
        ],
    ];

    /** The rungs, in the order a reader climbs them. */
    public const RUNGS = ['creative', 'ad', 'adset', 'campaign'];

    /** Whether this product knows what the named provider's lead deliveries carry. */
    public static function known(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, self::SUPPLIES);
    }

    /**
     * The rungs this provider supplies on a lead.
     *
     * @return list<string>
     */
    public static function supplies(?string $provider): array
    {
        return self::known($provider) ? self::SUPPLIES[$provider] : [];
    }

    public static function offers(?string $provider, string $rung): bool
    {
        return in_array($rung, self::supplies($provider), true);
    }

    /**
     * Why this provider has nothing to say about this rung, or null if it does supply it.
     *
     * An unknown provider gets null rather than an invented sentence: we do not know what it offers,
     * and saying «the platform does not support this» about a platform we have not modelled would be
     * a claim we cannot stand behind.
     */
    public static function limit(?string $provider, string $rung): ?string
    {
        if ($provider === null || self::offers($provider, $rung)) {
            return null;
        }

        return self::LIMITS[$provider][$rung] ?? null;
    }

    /** The same answer as {@see limit()}, in English. */
    public static function limitEn(?string $provider, string $rung): ?string
    {
        if ($provider === null || self::offers($provider, $rung)) {
            return null;
        }

        return self::LIMITS_EN[$provider][$rung] ?? null;
    }
}
