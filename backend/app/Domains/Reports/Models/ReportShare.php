<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Reports\Sections\ReportViews;
use App\Domains\Reports\Support\CreativeDisplay;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\ShareSections;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property bool $hide_spend the EFFECTIVE flag — see {@see hideSpend()}
 */
final class ReportShare extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'report_id', 'mode', 'form', 'token_hash', 'password_hash', 'allow_download',
        'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark', 'settings', 'scope',
        'view_count', 'last_viewed_at', 'expires_at', 'revoked_at', 'paused_at', 'created_by', 'is_demo', 'trace_mark',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    protected $casts = [
        'scope' => 'array',
        'allow_download' => 'boolean',
        'hide_spend' => 'boolean',
        'hide_revenue' => 'boolean',
        'hide_campaign_names' => 'boolean',
        'watermark' => 'boolean',
        'settings' => 'array',
        'view_count' => 'integer',
        'last_viewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'paused_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    /**
     * REPORT-SPEND-VISIBILITY-001 — the report's own KPI selection decides whether spend shows.
     *
     * `hide_spend` is the older switch, defaulted ON for every client link, and it silently overrode
     * the metrics the Owner picked in report setup: «الإنفاق» ticked, spend absent, and another card
     * in its place. Two visibility systems cannot both be the truth, so the selection wins — a live
     * link that SELECTS spend shows spend, and the legacy flag keeps its meaning only for links that
     * did not select it (snapshots, and live links built without the metric).
     */
    /**
     * IP-TRACE-001 — every link is born with an opaque reference (XXXXX-XXXXX).
     *
     * It is what an exported file or a watermarked page carries so a leaked copy can be matched to the
     * link it came from. Random and unique: it says nothing about the recipient and nothing about the
     * report, and the public never receives anything else that could be traced to a person.
     */
    protected static function booted(): void
    {
        self::creating(function (self $share): void {
            if (($share->trace_mark ?? '') === '') {
                $share->trace_mark = self::newTraceMark();
            }
        });
    }

    public static function newTraceMark(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $mark = '';
            for ($i = 0; $i < 10; $i++) {
                $mark .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $mark = substr($mark, 0, 5).'-'.substr($mark, 5);
        } while (self::withoutGlobalScopes()->where('trace_mark', $mark)->exists());

        return $mark;
    }

    protected function hideSpend(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): bool => (bool) $value && ! $this->selectsSpend(),
        );
    }

    public function selectsSpend(): bool
    {
        return is_array($this->scope) && in_array('spend', (array) ($this->scope['metrics'] ?? []), true);
    }

    /**
     * A live link recomputes its figures on every request, within the ceiling in `scope`.
     *
     * Checked as `mode === 'live' && scope !== null` rather than on the mode alone: a live link with no
     * ceiling would be a link to everything, and the one thing this feature must never do is widen.
     * A row that somehow lost its scope therefore falls back to the snapshot it was made from, which is
     * the safe direction to fail.
     */
    public function isLive(): bool
    {
        return $this->mode === 'live' && is_array($this->scope) && $this->scope !== [];
    }

    /**
     * Which form of the report this link presents — the operator's choice, or the report's own.
     *
     * A per-link choice because one report is legitimately two documents: the board gets the summary
     * and the performance manager gets the detail, from the same generation and the same figures.
     * Before this, `form` was read from the report row, so the only way to send both was to generate
     * the report twice — two rows, two snapshots, and a fortnight later two different answers to the
     * same question.
     *
     * Independent of {@see isLive()} in both directions: a summary can be live and a detailed report
     * can be a snapshot.
     */
    public function formOr(?string $reportForm): string
    {
        $chosen = is_string($this->form) ? trim($this->form) : '';

        return in_array($chosen, ['executive_summary', 'detailed'], true)
            ? $chosen
            : ($reportForm === 'executive_summary' ? 'executive_summary' : 'detailed');
    }

    /**
     * What this link may show about the creatives — fail-closed, and closed for every older link.
     *
     * Read through {@see CreativeVisibility} rather than as raw booleans so that the combinations
     * that leak by arithmetic (ROAS beside a hidden spend) are closed in one place instead of at
     * every surface that renders a figure.
     */
    public function creativeDisplay(): ?CreativeDisplay
    {
        return CreativeDisplay::fromArray((array) (($this->settings ?? [])['creative_display'] ?? []) ?: null);
    }

    public function creativeVisibility(): CreativeVisibility
    {
        return CreativeVisibility::fromArray((array) (($this->settings ?? [])['creatives'] ?? []));
    }

    /**
     * Which optional sections this link may open — fail-closed, and closed for every older link.
     *
     * ATTRIB-VIS-001. Sibling of `creativeVisibility()` rather than a second pattern, because the
     * question is the same one: what did an operator deliberately choose to publish?
     */
    /**
     * Whether this link covers only PART of the project its report is about.
     *
     * A fact about the share, held here rather than re-derived at each call site, because two places
     * need it and they must not be able to disagree: the endpoint that refuses the attribution
     * section, and the payload flag that decides whether the client's page mounts that section at
     * all. A refusal the page does not know about renders a section that appears and then fails,
     * which `SharedAttributionSection` states is worse than one that never appears — a client cannot
     * tell «not shared» from «broken».
     */
    /**
     * The section flags a READER may act on — the operator's choice, narrowed by the link's reach.
     *
     * `sectionVisibility()` answers «what did the operator publish»; this answers «what may this link
     * actually open», which is the question every caller was really asking and three of them were
     * answering separately. `show()` and `live()` each built their own copy, and the endpoint that
     * serves attribution applied a third rule — so a link could be told the section was available by
     * one payload and refused by the request that followed. The page mounts
     * `SharedAttributionSection` on this flag alone and that component carries no refusal path on
     * purpose, so the disagreement rendered a section that appears and then fails.
     *
     * A conjunction, never an override: a link that never asked for attribution does not acquire it
     * by being wide.
     *
     * @return array<string, bool>
     */
    public function visibleSections(): array
    {
        $sections = $this->sectionVisibility()->toArray();

        $sections['attribution'] = ($sections['attribution'] ?? false) && ! $this->narrowerThanItsProject();

        return $sections;
    }

    public function narrowerThanItsProject(): bool
    {
        $scope = (array) ($this->scope ?? []);

        return (array) ($scope['account_ids'] ?? []) !== [] || (array) ($scope['campaign_ids'] ?? []) !== [];
    }

    /**
     * The report sections this link hides on top of the report — off-only, in the registry's own
     * vocabulary (REPORT-SECTION-MODEL-001). A link can narrow a report; it can never widen one.
     *
     * @return list<string>
     */
    public function sectionOverrides(): array
    {
        return array_values(array_filter(
            (array) (($this->settings ?? [])['section_overrides'] ?? []),
            static fn ($key): bool => is_string($key) && $key !== '',
        ));
    }

    /**
     * REPORT-COMPOSITION-CONTROL-001 — the main sections this link offers, narrowing its report's;
     * null (every link made before this) narrows nothing.
     *
     * @return list<string>|null
     */
    public function views(): ?array
    {
        return ReportViews::normalise((($this->settings ?? [])['views'] ?? null));
    }

    public function sectionVisibility(): ShareSections
    {
        return ShareSections::fromArray((array) (($this->settings ?? [])['sections'] ?? []));
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null || $this->paused_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && Carbon::now()->greaterThan($this->expires_at)) {
            return false;
        }

        return true;
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return HasMany<ReportShareAccessLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ReportShareAccessLog::class, 'share_id');
    }
}
