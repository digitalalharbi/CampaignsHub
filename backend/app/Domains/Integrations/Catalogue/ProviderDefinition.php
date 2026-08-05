<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * PROVCFG-001 — everything that is TRUE ABOUT A PROVIDER regardless of who has configured it.
 *
 * This is the half of an integration that belongs to the provider: which values their console issues,
 * which extra approval they demand before a production key works, whether their OAuth is standard,
 * whether they can push events, and how their API paginates and throttles. None of it is a secret and
 * none of it is per-tenant, so it lives in code where it can be reviewed rather than in a table where
 * it would be typed in nine times slightly differently.
 *
 * ## Why this is not one shared shape with a `fields` array and nothing else
 *
 * The providers are not variations of each other. Google Ads refuses every API call without a
 * developer token that is approved on a different track from the OAuth client. Snapchat hangs ad
 * accounts off an organisation, so a perfectly valid token lists nothing without one. TikTok does not
 * use the OAuth parameter names. Salla and Zid are not advertising platforms at all and have no ad
 * accounts to discover. A generic model would let each of those fail as "connected, and no data",
 * which is the single worst state this product can be in.
 *
 * ## `prerequisites` is not documentation, it is the reason a correct key still fails
 *
 * Every entry here is something a real operator must obtain OUTSIDE this product, and until they have
 * it the integration cannot work no matter what is typed into the form. Showing them on the setup
 * screen is what makes `Awaiting Credentials` an instruction rather than a complaint.
 */
final class ProviderDefinition
{
    /**
     * @param  list<ProviderField>  $fields
     * @param  list<string>  $scopes
     * @param  list<string>  $prerequisites
     * @param  list<string>  $prerequisitesAr
     */
    public function __construct(
        public readonly string $key,
        public readonly ProviderKind $kind,
        public readonly string $label,
        public readonly string $labelAr,
        public readonly array $fields,
        public readonly array $scopes,
        public readonly bool $usesPkce,
        public readonly bool $supportsRefresh,
        public readonly string $tokenNote,
        public readonly string $tokenNoteAr,
        public readonly WebhookSupport $webhooks,
        public readonly ?string $webhookSignatureHeader,
        public readonly array $prerequisites,
        public readonly array $prerequisitesAr,
        public readonly string $docsUrl,
        public readonly string $rateLimitNote,
        public readonly string $paginationNote,
    ) {}

    /** @return list<string> the keys of every field that must be present before a call is worth making */
    public function requiredKeys(): array
    {
        return array_values(array_map(
            static fn (ProviderField $f) => $f->key,
            array_filter($this->fields, static fn (ProviderField $f) => $f->required),
        ));
    }

    /** @return list<string> */
    public function secretKeys(): array
    {
        return array_values(array_map(
            static fn (ProviderField $f) => $f->key,
            array_filter($this->fields, static fn (ProviderField $f) => $f->secret),
        ));
    }

    public function field(string $key): ?ProviderField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function isAdvertising(): bool
    {
        return $this->kind === ProviderKind::Advertising;
    }

    /**
     * Where this provider sends the browser back, and where it would POST an event.
     *
     * Derived, never stored. A redirect URI that lived in the database could be edited to point the
     * authorisation code at somewhere else entirely, and the console operator typing it would have no
     * way to tell. It is shown so it can be COPIED into the provider's console — which is the only
     * place it actually has to match.
     */
    public function redirectUri(): string
    {
        return $this->callbackBase().'/api/v1/oauth/'.$this->kind->routeSegment().'/'.$this->key.'/callback';
    }

    public function webhookUrl(): ?string
    {
        if (! $this->webhooks->hasEndpoint()) {
            return null;
        }

        return $this->callbackBase().'/api/v1/webhooks/'.$this->kind->routeSegment().'/'.$this->key;
    }

    private function callbackBase(): string
    {
        return rtrim((string) config('ad_platforms.redirect_base', config('app.url')), '/');
    }

    /** @return array<string,mixed> the provider half of the setup screen — no secret ever appears here */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'label_ar' => $this->labelAr,
            'fields' => array_map(static fn (ProviderField $f) => $f->toArray(), $this->fields),
            'scopes' => $this->scopes,
            'uses_pkce' => $this->usesPkce,
            'supports_refresh' => $this->supportsRefresh,
            'token_note' => $this->tokenNote,
            'token_note_ar' => $this->tokenNoteAr,
            'webhooks' => $this->webhooks->value,
            'webhook_signature_header' => $this->webhookSignatureHeader,
            'webhook_url' => $this->webhookUrl(),
            'redirect_uri' => $this->redirectUri(),
            'prerequisites' => $this->prerequisites,
            'prerequisites_ar' => $this->prerequisitesAr,
            'docs_url' => $this->docsUrl,
            'rate_limit_note' => $this->rateLimitNote,
            'pagination_note' => $this->paginationNote,
        ];
    }
}
