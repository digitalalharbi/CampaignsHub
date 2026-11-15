<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Notifications\Engine\Content\CatalogMessageComposer;
use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Mail\SpendSummaryMail;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Services\ProjectSpendSummary;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /** Events whose approved template is the spend summary (budget, runway, limits); every other message is the project summary. */
    private const SPEND_EVENTS = ['continuity.planning', 'budget.renewed', 'digest.month_start'];

    private function external(Delivery $delivery, string $theme, ?Carbon $now = null): ComposedMessage
    {
        $c = ExternalContext::load($delivery, $this->settings, $this->types->profileFor($delivery->tenantId), $now)->withTheme($theme);
        $data = $this->dataFor($c);
        $updating = ($data['complete'] ?? true) !== true && isset($delivery->payload['_data_retry']);
        $footprintRemoved = Footprint::removed($c->tenantId);
        $model = ReportView::build($c, $data, $footprintRemoved, $updating);

        // The approved template for this role and type, in its external mode (no route back to the platform).
        $html = $updating ? null : ($c->roleKey === 'finance' || in_array($c->eventKey, self::SPEND_EVENTS, true) ? $this->spendSummary($c, $model) : $this->projectSummary($c, $model));
        $html ??= $this->updatingNotice($c, $model);
        $text = ExternalMode::text($html, (string) $model['manage_url'], (string) $model['manage_label']).'
'.$model['reference'];
        $sender = $footprintRemoved ? $c->workspaceName : $c->t('sender_via', ['workspace' => $c->workspaceName]);
        $replyTo = $c->settings['sender']['reply_to'] ?? null;

        return new ComposedMessage(
            subject: (string) $model['subject'],
            title: (string) $model['subject'],
            body: (string) ($model['highlight'] ?? ''),
            html: $html,
            text: $text,
            url: null,
            externalReady: true,
            senderName: $sender,
            replyTo: is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : null,
            inline: [],
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

    private function kind(ExternalContext $c): string
    {
        $days = (int) max(1, Carbon::parse($c->from)->startOfDay()->diffInDays(Carbon::parse($c->to)->startOfDay()) + 1);

        return $days <= 1 ? 'daily' : ($days >= 28 ? 'monthly' : 'weekly');
    }

    /** @param array<string, mixed> $model */
    private function spendSummary(ExternalContext $c, array $model): string
    {
        $tenants = app(TenantContext::class);
        $previous = $tenants->hasTenant() ? (string) $tenants->tenantId() : null;
        $tenants->setTenantId($c->tenantId);
        try {
            $summary = app(ProjectSpendSummary::class)->build($c->tenantId, [$c->projectId], Carbon::parse($c->from), Carbon::parse($c->to), $c->createdAt->copy()->setTimezone('Asia/Riyadh')->startOfDay());
        } finally {
            $previous === null ? $tenants->forget() : $tenants->setTenantId($previous);
        }
        $mail = (new SpendSummaryMail($summary, $c->locale, $this->kind($c), external: true, recipientName: (string) $c->salutation, scopeNote: $this->scopeNote($c), privacyLine: $this->privacyLine($c), disclaimer: (string) $model['disclaimer'], footprintUrl: $model['footprint']['link'] ?? null, hideFootprint: $model['footprint'] === null))
            ->offerUnsubscribe((string) $model['manage_url']);

        return $this->mark($c, $mail)->render();
    }

    /** @param array<string, mixed> $model */
    private function projectSummary(ExternalContext $c, array $model): ?string
    {
        $tenants = app(TenantContext::class);
        $previous = $tenants->hasTenant() ? (string) $tenants->tenantId() : null;
        $tenants->setTenantId($c->tenantId);
        try {
            // The workspace's own principal reads the figures; ONLY this project's id is asked for, and the pre-send privacy check still guards the result.
            $principal = $this->principal($c->tenantId);
            $digest = $principal === null ? null : app(DailyDigest::class)->buildRange($principal, $c->tenantId, [$c->projectId], Carbon::parse($c->from)->startOfDay(), Carbon::parse($c->to)->endOfDay(), $this->kind($c));
        } finally {
            $previous === null ? $tenants->forget() : $tenants->setTenantId($previous);
        }
        $row = $digest['projects'][0] ?? null;
        if (! is_array($row)) {
            return null;
        }
        $kind = $this->kind($c);
        $m = ExternalMode::digestModel(OperationalDigestModel::project($row, $c->locale, (int) ($digest['days'] ?? 1), $kind), $c->locale === 'ar');
        $mail = (new OperationalDigestMail($m, $c->locale, (string) $c->salutation, $kind, 'project', DigestDispatcher::periodLabel($digest, $kind, $c->locale), external: true, scopeNote: $this->scopeNote($c), privacyLine: $this->privacyLine($c), disclaimer: (string) $model['disclaimer'], footprintUrl: $model['footprint']['link'] ?? null, hideFootprint: $model['footprint'] === null))
            ->offerUnsubscribe((string) $model['manage_url']);

        return $this->mark($c, $mail)->render();
    }

    /** «قيد التحديث — يصلك في الملخص القادم»: the same shell, one line (spec §7: missing data is never shown as zero). @param array<string, mixed> $model */
    private function updatingNotice(ExternalContext $c, array $model): string
    {
        $ar = $c->locale === 'ar';
        $dir = $ar ? 'rtl' : 'ltr';
        $shell = MailShell::build(lang: $c->locale, subject: (string) $model['subject'], preheader: $c->t('updating'), preferences: false, footerNote: (string) $model['disclaimer']);
        $slot = view('mail.digest._head', ['title' => $c->t('title_updating', ['term' => $c->term()]), 'sub' => ReportView::periodLabel($c), 'dir' => $dir, 'startSide' => $ar ? 'right' : 'left', 'endSide' => $ar ? 'left' : 'right'])->render()
            .'<div dir="'.$dir.'" data-block="updating" style="font-family:'.MailDesign::font($ar).'; font-size:14px; font-weight:700; color:#5b6b68; background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; padding:12px 14px; text-align:'.($ar ? 'right' : 'left').';">'.e($c->t('updating')).'</div>';
        $html = view('mail.layout', $shell + ['standalone' => true, 'unsubscribeUrl' => $model['manage_url'], 'scopeNote' => $this->scopeNote($c), 'footprintUrl' => $model['footprint']['link'] ?? null, 'hideFootprint' => $model['footprint'] === null, 'testSendNotice' => $this->testNotice($c), 'slot' => $slot])->render();

        return $html;
    }

    private function scopeNote(ExternalContext $c): string
    {
        return $c->t('scope_note', ['workspace' => $c->workspaceName, 'term' => $c->term(), 'project' => $c->projectName]);
    }

    private function privacyLine(ExternalContext $c): string
    {
        return $c->t('privacy_short', ['role' => $c->roleLabel, 'project' => $c->projectName]);
    }

    /** A test send carries the shell's own «test» strip (EMAIL-TEST-SEND-001); the reference says TEST only for «أرسل نسخة لي». @return array{title: string, detail: string}|null */
    private function testNotice(ExternalContext $c): ?array
    {
        if ($c->copyKind !== null) {
            return ['title' => $c->t('copy_band'), 'detail' => ''];            // «نسخة للاطلاع» on a CC/BCC copy (spec §9)
        }

        return str_starts_with($c->delivery->reference, 'TEST') ? ['title' => $c->t('test_banner'), 'detail' => ''] : null;
    }

    private function mark(ExternalContext $c, Mailable $mail): Mailable
    {
        $notice = $this->testNotice($c);

        return $notice === null ? $mail : $mail->with('testSendNotice', $notice);
    }

    private function principal(string $tenantId): ?User
    {
        $id = DB::table('memberships')->where('tenant_id', $tenantId)->where('status', 'active')->orderBy('created_at')->value('user_id');

        return $id === null ? null : User::query()->find($id);
    }
}
