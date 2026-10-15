<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * LEAD-DESTINATION-001 — where a lead-generating campaign's result happens.
 *
 * A lead is not one thing: a form on the ad, a form on the advertiser's page, a WhatsApp thread, a
 * Messenger or Instagram thread, a phone call. Each costs differently and none is comparable with
 * another, so the report names WHICH one a campaign bought instead of printing «cost per result»
 * over a mix.
 *
 * Derived from the platform's own destination on the ad set, never from a campaign's name: a campaign
 * called «Sales WhatsApp» is a name, `destination_type = WHATSAPP` is the platform stating it.
 * `Mixed` is the honest answer when a campaign's ad sets disagree.
 *
 * The messaging destinations carry the platform's attributed messaging result. That is NOT a
 * WhatsApp Business conversation identity — which needs a WhatsApp authorisation this install does
 * not hold (WHATSAPP-CONVERSATION-SOURCE-001).
 */
enum LeadDestination: string
{
    case LeadForm = 'lead_form';
    case Website = 'website';
    case Whatsapp = 'whatsapp';
    case Messenger = 'messenger';
    case InstagramDirect = 'instagram_direct';
    case Calls = 'calls';
    case Mixed = 'mixed';

    /** The platform's spelling of a destination → ours. Null where it settles nothing about leads. */
    public static function fromMeta(?string $destinationType): ?self
    {
        return match (strtoupper(trim((string) $destinationType))) {
            'WEBSITE' => self::Website,
            'ON_AD', 'ON_POST' => self::LeadForm,
            'WHATSAPP' => self::Whatsapp,
            'MESSENGER' => self::Messenger,
            'INSTAGRAM_DIRECT' => self::InstagramDirect,
            'PHONE_CALL', 'CALL' => self::Calls,
            default => null,
        };
    }

    /** The destinations whose result is a message the platform attributed to the ad. */
    public function isMessaging(): bool
    {
        return in_array($this, [self::Whatsapp, self::Messenger, self::InstagramDirect], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }

    /** @return array{ar:string,en:string} */
    public function label(): array
    {
        return match ($this) {
            self::LeadForm => ['ar' => 'نموذج عملاء محتملين', 'en' => 'Lead form'],
            self::Website => ['ar' => 'الموقع', 'en' => 'Website'],
            self::Whatsapp => ['ar' => 'واتساب', 'en' => 'WhatsApp'],
            self::Messenger => ['ar' => 'ماسنجر', 'en' => 'Messenger'],
            self::InstagramDirect => ['ar' => 'رسائل إنستغرام', 'en' => 'Instagram Direct'],
            self::Calls => ['ar' => 'اتصالات', 'en' => 'Calls'],
            self::Mixed => ['ar' => 'وجهات متعددة', 'en' => 'Mixed destinations'],
        };
    }

    /** @return array{ar:string,en:string} what one result is called for this destination */
    public function resultLabel(): array
    {
        return $this->isMessaging()
            ? ['ar' => 'الرسائل', 'en' => 'Messages']
            : ['ar' => 'العملاء المحتملون', 'en' => 'Leads'];
    }

    /** @return array{ar:string,en:string} */
    public function costLabel(): array
    {
        return $this->isMessaging()
            ? ['ar' => 'تكلفة الرسالة', 'en' => 'Cost per message']
            : ['ar' => 'تكلفة العميل المحتمل', 'en' => 'Cost per lead'];
    }
}
