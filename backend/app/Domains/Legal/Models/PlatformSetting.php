<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGAL-001 — the one record of who operates this platform.
 *
 * Deliberately NOT tenant-scoped: there is one operator, and the policies published at `/privacy` and
 * `/terms` are published by them, not by whichever customer's context happened to be active when the
 * page was rendered.
 *
 * Most fields are null until somebody fills them in, and that is the point — see the migration. The
 * public pages ask {@see isPublished()} before printing a legal identity, so an unset installation
 * says «the operator has not published these details» rather than showing a blank where a company
 * name should be, or worse, a plausible invention.
 */
final class PlatformSetting extends Model
{
    use HasUuidKey;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = [
        'legal_name_ar', 'legal_name_en', 'trading_name', 'registration_number', 'tax_number',
        'jurisdiction', 'address_ar', 'address_en', 'contact_email', 'support_email',
        'security_email', 'privacy_email', 'phone', 'dpo_name', 'dpo_email', 'updated_by',
    ];

    /**
     * The singleton, created empty on first read.
     *
     * Created rather than returned-as-null so every caller has an object to read, and so the
     * «unset» state is a row full of nulls the operator can fill in from `/admin` — not the absence
     * of a row, which every caller would have to remember to handle.
     */
    public static function current(): self
    {
        /*
         * The contact address is stated HERE as well as in the schema, deliberately.
         *
         * A column default is applied by the database on insert and is not reflected back into the
         * model Eloquent already built, so the first read after a fresh install saw `contact_email`
         * as null and the accessors below — which promise a string — blew up. Naming it in both
         * places keeps the in-memory object and the row agreeing from the first request, and it is
         * the one value that is genuinely ours to state rather than a guess about the operator.
         */
        return self::query()->firstOrCreate(
            ['is_singleton' => true],
            ['contact_email' => 'info@CampaignsHub.io'],
        );
    }

    /**
     * Whether there is enough here to name an operator on a published policy.
     *
     * A legal name in at least one language is the minimum: a privacy policy that cannot say who is
     * processing the data is not a privacy policy. Everything else refines it.
     */
    public function isPublished(): bool
    {
        return filled($this->legal_name_ar) || filled($this->legal_name_en);
    }

    /** The address for privacy matters, falling back through the contacts that are actually set. */
    public function privacyContact(): string
    {
        return $this->dpo_email ?: ($this->privacy_email ?: $this->contact_email);
    }

    public function securityContact(): string
    {
        return $this->security_email ?: $this->contact_email;
    }

    public function supportContact(): string
    {
        return $this->support_email ?: $this->contact_email;
    }

    /**
     * The shape the public pages read.
     *
     * `published` travels with it so a page never has to re-derive the rule, and every unset field
     * stays null rather than becoming an empty string — the two mean different things to a renderer
     * deciding whether to show a row at all.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'published' => $this->isPublished(),
            'legal_name_ar' => $this->legal_name_ar,
            'legal_name_en' => $this->legal_name_en,
            'trading_name' => $this->trading_name,
            'registration_number' => $this->registration_number,
            'tax_number' => $this->tax_number,
            'jurisdiction' => $this->jurisdiction,
            'address_ar' => $this->address_ar,
            'address_en' => $this->address_en,
            'contact_email' => $this->contact_email,
            'support_email' => $this->supportContact(),
            'security_email' => $this->securityContact(),
            'privacy_email' => $this->privacyContact(),
            'phone' => $this->phone,
            'dpo_name' => $this->dpo_name,
        ];
    }
}
