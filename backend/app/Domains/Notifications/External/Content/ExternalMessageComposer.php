<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Notifications\Engine\Content\CatalogMessageComposer;
use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\External\Charts\ChartImages;
use App\Domains\Notifications\External\Charts\ChartSpecs;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use Illuminate\Support\Carbon;

/**
 * NOTIF-V2-EXTERNAL-001 — the engine's `MessageComposer` for the external scope (spec §8–§13); internal deliveries keep
 * the engine's own `CatalogMessageComposer`.
 *
 * External-ready by construction: identity (sender name, subject structure, header, privacy line, why-received), the
 * unsubscribe link, the footprint (unless the admin-only plan key removes it), the disclaimer with creation time, sources
 * and the reference, and the FX footer. The figures come from `DigestDataSource` (the screens' reads, FxEngine
 * conversion); charts from `ChartImages` (inline CID PNGs) unless `notifications.external.charts` is off, in which case
 * the tables stand in. The result carries `facts` for the pre-send checks.
 */
final class ExternalMessageComposer implements MessageComposer
{
    /** @var array<string, ComposedMessage> */
    private array $memo = [];

    /** @var array<string, array<string, mixed>> */
    private array $dataMemo = [];

    public function __construct(
        private readonly CatalogMessageComposer $internal,
        private readonly ExternalSettings $settings,
        private readonly WorkspaceTypes $types,
        private readonly DigestDataSource $data,
        private readonly ChartImages $charts,
        private readonly EntitlementGate $gate,
    ) {}

    public function compose(Delivery $delivery): ComposedMessage
    {
        if ($delivery->scope !== Scope::External) {
            return $this->internal->compose($delivery);
        }

        return $this->memo[$delivery->id.'|'.$delivery->attempts.'|'.md5((string) json_encode($delivery->payload))] ??= $this->external($delivery, 'auto');
    }

    /** The same message in a forced theme (previews: «dark»), without memo. */
    public function preview(Delivery $delivery, string $theme = 'auto', ?Carbon $now = null): ComposedMessage
    {
        return $this->external($delivery, $theme, $now);
    }

    /** @return array<string, mixed> */
    public function dataFor(ExternalContext $c): array
    {
        return $this->dataMemo[implode('|', [$c->tenantId, $c->projectId, $c->from, $c->to, $c->currency])]
            ??= $this->data->project($c->tenantId, $c->projectId, $c->from, $c->to, $c->currency);
    }

    private function external(Delivery $delivery, string $theme, ?Carbon $now = null): ComposedMessage
    {
        $c = ExternalContext::load($delivery, $this->settings, $this->types->profileFor($delivery->tenantId), $now)->withTheme($theme);
        $data = $this->dataFor($c);
        $updating = ($data['complete'] ?? true) !== true && isset($delivery->payload['_data_retry']);
        $footprintRemoved = Footprint::removed($c->tenantId);
        $model = ReportView::build($c, $data, $footprintRemoved, $updating);

        $images = [];
        $model['charts'] = [];
        if (! $updating && $this->gate->allows($c->tenantId, EntitlementKey::ExternalCharts)) {
            $specs = ChartSpecs::for($model, $data);
            $scope = implode(':', [$c->projectId, $c->from, $c->to, $c->eventKey, $c->currency, $c->locale]);
            foreach ($this->charts->render($c->tenantId, $scope, $specs) as $key => $png) {
                if ($png === null) {
                    continue;
                }
                $model['charts'][$key] = ['width' => $png['width'], 'height' => $png['height']];
                if ($theme !== 'dark') {
                    $images[$key] = $png['light'];
                }
                $images[$key.'-dark'] = $png['dark'];
            }
        }

        $html = view('mail.external.report', ['m' => $model])->render();
        $text = TextVersion::of($model);
        $sender = $footprintRemoved ? $c->workspaceName : $c->t('sender_via', ['workspace' => $c->workspaceName]);
        $replyTo = $c->settings['sender']['reply_to'] ?? null;

        return new ComposedMessage(
            subject: (string) $model['subject'],
            title: (string) $model['subject'],
            body: (string) ($model['event_line'] ?? $model['tone']),
            html: $html,
            text: $text,
            url: null,
            externalReady: true,
            senderName: $sender,
            replyTo: is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : null,
            inline: $images,
            facts: [
                'project_id' => $c->projectId,
                'subscription_project_ids' => $c->subscriptionProjectIds,
                'data_project_ids' => array_values(array_map('strval', (array) ($data['data_project_ids'] ?? []))),
                'complete' => ($data['complete'] ?? true) === true,
                'updating' => $updating,
                'fx' => (array) ($data['fx'] ?? []),
                'data' => $data,
                'currency' => $c->currency,
                'period' => ['from' => $c->from, 'to' => $c->to],
                'is_client' => $c->isClient(),
            ],
        );
    }
}
