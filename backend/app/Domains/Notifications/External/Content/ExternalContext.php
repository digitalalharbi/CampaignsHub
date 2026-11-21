<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Metrics\Fx\FxPolicy;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\WorkspaceTypeProfile;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use App\Domains\Notifications\External\Recipients\ExternalRecipients;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything one external message is about, read once (spec §8–§13): the workspace and its type profile, the project,
 * the recipient (as stored — «اسم المخاطبة» literally), the subscription, the role and its tone, the display currency,
 * the complete-days period, and whether this is the primary copy or a CC/BCC copy.
 */
final class ExternalContext
{
    /**
     * @param  array<string, mixed>  $settings  ExternalSettings::for()
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $subscriptionProjectIds
     * @param  list<string>  $digestTypes
     */
    public function __construct(
        public readonly Delivery $delivery,
        public readonly string $tenantId,
        public readonly string $workspaceName,
        public readonly WorkspaceTypeProfile $profile,
        public readonly string $projectId,
        public readonly string $projectName,
        public readonly string $locale,
        public readonly string $roleKey,
        public readonly string $roleLabel,
        public readonly ?string $salutation,
        public readonly string $currency,
        public readonly string $from,
        public readonly string $to,
        public readonly string $eventKey,
        public readonly array $payload,
        public readonly array $settings,
        public readonly array $subscriptionProjectIds,
        public readonly array $digestTypes,
        public readonly ?string $subjectTemplate,
        public readonly bool $showBudget,
        public readonly ?string $subscribedSince,
        public readonly ?string $copyKind,
        public readonly ?string $recipientId,
        public readonly ?string $subscriptionId,
        public readonly Carbon $createdAt,
        public readonly string $theme = 'auto',
    ) {}

    public function isClient(): bool
    {
        return $this->profile->isClientRole($this->roleKey);
    }

    /** finance = clear numbers; responsible role = big picture; client = simple; everyone else = progress (spec §11). */
    public function tone(): string
    {
        return match (true) {
            $this->isClient() => 'simple',
            $this->roleKey === 'finance' => 'numbers',
            $this->roleKey === $this->profile->responsibleRole => 'big_picture',
            default => 'progress',
        };
    }

    public function term(): string
    {
        return (string) __((string) ($this->profile->terminology['project'] ?? 'notifications_v2.terms.agency.project'), [], $this->locale);
    }

    public function t(string $key, array $replace = []): string
    {
        return (string) __('notifications_external.'.$key, $replace, $this->locale);
    }

    public function withTheme(string $theme): self
    {
        $a = get_object_vars($this);
        $a['theme'] = $theme;

        return new self(...$a);
    }

    public static function load(Delivery $d, ExternalSettings $settings, WorkspaceTypeProfile $profile, ?Carbon $now = null): self
    {
        $now ??= Carbon::now();
        $ws = $settings->workspace($d->tenantId);
        $locale = $d->recipient->locale === 'en' ? 'en' : 'ar';
        $recipient = $d->recipient->externalId === null ? null
            : DB::table('notification_external_recipients')->where('tenant_id', $d->tenantId)->where('id', $d->recipient->externalId)->first();
        $sub = $d->subscriptionId === null ? null
            : DB::table('notification_subscriptions')->where('tenant_id', $d->tenantId)->where('id', $d->subscriptionId)->first();
        $projectName = (string) ($d->payload['project_name'] ?? DB::table('projects')->where('tenant_id', $d->tenantId)->where('id', $d->projectId)->value('name') ?? '');
        $role = $profile->hasRole((string) ($recipient->role_key ?? '')) ? (string) $recipient->role_key : $d->recipient->roleKey;
        $roleLabel = (string) __("notifications_v2.roles.{$role}", [], $locale);
        $cfg = $settings->for($d->tenantId);
        $currency = (string) ($recipient->display_currency ?? null ?: ($cfg['external']['display_currency'] ?? null) ?: app(FxPolicy::class)->displayCurrencyFor($d->tenantId));

        $period = (array) ($d->payload['period'] ?? []);
        if (! isset($period['from'], $period['to'])) {
            $local = $now->copy()->setTimezone($ws->timezone)->toDateString();
            $key = in_array($d->eventKey, ['digest.month_end'], true) ? 'last_month' : 'last_7';
            $period = DigestPeriods::resolve($key, $local) ?? ['from' => $local, 'to' => $local];
        }
        $json = static fn ($v): array => $v === null ? [] : array_values((array) (is_string($v) ? json_decode($v, true) : $v));
        $projects = $sub === null ? array_values(array_filter([(string) $d->projectId]))
            : DB::table('notification_subscription_projects')->where('subscription_id', $sub->id)->where('tenant_id', $d->tenantId)->pluck('project_id')->map(static fn ($p): string => (string) $p)->all();

        return new self(
            delivery: $d,
            tenantId: $d->tenantId,
            workspaceName: $ws->workspaceName,
            profile: $profile,
            projectId: (string) $d->projectId,
            projectName: $projectName,
            locale: $locale,
            roleKey: $role,
            roleLabel: $roleLabel,
            salutation: $recipient?->salutation !== null && trim((string) $recipient->salutation) !== '' ? trim((string) $recipient->salutation) : null,
            currency: strtoupper($currency),
            from: (string) $period['from'],
            to: (string) $period['to'],
            eventKey: $d->eventKey,
            payload: $d->payload,
            settings: $cfg,
            subscriptionProjectIds: $projects,
            digestTypes: $sub === null ? ExternalRecipients::DIGEST_TYPES : $json($sub->digest_types),
            subjectTemplate: $sub?->subject_template,
            showBudget: $sub === null || (bool) (ExternalRecipients::overrides($sub)['show_budget'] ?? true),
            subscribedSince: $sub?->created_at === null ? null : Carbon::parse((string) $sub->created_at)->setTimezone($ws->timezone)->toDateString(),
            copyKind: isset($d->payload['_copy']) ? (string) $d->payload['_copy'] : null,
            recipientId: $d->recipient->externalId,
            subscriptionId: $d->subscriptionId,
            createdAt: $now,
        );
    }
}
