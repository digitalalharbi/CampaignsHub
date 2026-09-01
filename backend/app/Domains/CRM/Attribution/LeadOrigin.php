<?php

declare(strict_types=1);

namespace App\Domains\CRM\Attribution;

/**
 * What a single provider can and cannot say about where a lead came from — LEAD-SOURCE-ATTRIBUTION-001.
 *
 * The chain a client wants is lead → content → ad set → campaign → platform. No provider supplies
 * all five for every delivery route, and the useful product decision is not «fill the gap» but «say
 * which rung is missing and WHY» — because «Snapchat does not return the creative on a lead» and
 * «this particular lead lost its creative» are different facts, and only one of them is worth an
 * operator's time.
 *
 * ## Why the capability is declared here rather than inferred from the row
 *
 * Inferring it from the data cannot tell those two cases apart: an absent column looks identical
 * whether the provider never sends it or sent it and we dropped it. Declaring what each provider
 * offers turns the first case into a stated limit and leaves the second visible as a real defect.
 * When a provider starts returning a rung it did not before, this table is the one line that
 * changes, and the tests that pin it fail loudly rather than the product silently improving in a way
 * nobody can audit.
 *
 * ## The rule that governs every entry
 *
 * A rung is listed ONLY where the provider returns it **on the lead itself**. Aggregate insight rows
 * carry campaign and ad ids too, and joining a lead to them by time or by count is the exact move
 * this requirement forbids: a click is not a person, and a lead attributed by proximity is a
 * fabricated fact wearing a real id.
 */
enum LeadOrigin: string
{
    /** A native lead form hosted by the platform: the fullest chain any provider offers. */
    case NativeForm = 'native_form';

    /** The person clicked through to a page we host; the chain is whatever the link carried. */
    case WebsiteForm = 'website_form';

    /** Somebody entered the lead by hand. No platform is claiming anything about it. */
    case Manual = 'manual';

    /** Imported from a file or another system, which supplied whatever it supplied. */
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::NativeForm => 'نموذج على المنصة',
            self::WebsiteForm => 'نموذج على الموقع',
            self::Manual => 'أُدخل يدويًا',
            self::Imported => 'مستورد',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::NativeForm => 'Native form',
            self::WebsiteForm => 'Website form',
            self::Manual => 'Entered by hand',
            self::Imported => 'Imported',
        };
    }
}
