<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\ShareSections;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

final class ReportShare extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'report_id', 'mode', 'form', 'token_hash', 'password_hash', 'allow_download',
        'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark', 'settings', 'scope',
        'view_count', 'last_viewed_at', 'expires_at', 'revoked_at', 'created_by', 'is_demo',
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
        'is_demo' => 'boolean',
    ];

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

    public function sectionVisibility(): ShareSections
    {
        return ShareSections::fromArray((array) (($this->settings ?? [])['sections'] ?? []));
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
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
