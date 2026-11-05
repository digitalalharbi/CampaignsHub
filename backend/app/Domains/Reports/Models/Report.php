<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\SectionSettings;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Report extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'campaign_id', 'name', 'description', 'type', 'form', 'audience', 'mode', 'version', 'campaign_objective', 'status',
        'period_start', 'period_end', 'currency', 'timezone', 'attribution_window', 'data_source',
        'config', 'scope', 'section_settings', 'data', 'error', 'created_by', 'generated_at', 'last_sent_at', 'is_demo',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'config' => 'array',
        'scope' => 'array',
        'section_settings' => 'array',
        'data' => 'array',
        'generated_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    /** The key in `config` that marks a row the editor saved as a draft ({@see isDraft()}). */
    public const DRAFT_MARK = 'editor_draft';

    /** Set only by {@see publish()}, for the one save that takes the row out of `draft`. */
    private bool $leavingDraft = false;

    /**
     * REPORT-DUPLICATE-001 — a draft leaves `draft` through {@see publish()} and nothing else.
     *
     * Several editors of a report end by marking it `processing` and queueing a rebuild (its scope, its sections, a note).
     * On a draft each of those would have been a publish nobody asked for, with none of the checks publishing makes. So the
     * rule is held here, once, rather than remembered in every controller: any other write that tries to move a draft's
     * status is saved with the status left at `draft`.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $report): void {
            $wasDraft = $report->getOriginal('status') === 'draft' && (((array) $report->getOriginal('config'))[self::DRAFT_MARK] ?? false) === true;
            if (! $wasDraft || $report->leavingDraft) {
                return;
            }
            if ($report->isDirty('status')) {
                $report->status = 'draft';
            }
            // An edit that replaces the whole config (the builder's own PATCH does) must not take the mark with it.
            $config = (array) ($report->config ?? []);
            if (($config[self::DRAFT_MARK] ?? false) !== true) {
                $report->config = $config + [self::DRAFT_MARK => true];
            }
        });
    }

    /**
     * Saved from the editor and not published: no document, no link, nothing a client can open.
     *
     * The status alone does not say so. `draft` is also the column's default, so a row made without a status (older rows,
     * fixtures) reads `draft` and is generated like any other; treating those as editor drafts would have stopped them
     * being built. An editor draft is a `draft` row that also carries the mark the editor's save puts in its config.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft' && (($this->config ?? [])[self::DRAFT_MARK] ?? false) === true;
    }

    /** The one way out of `draft`: saves the row (with whatever was filled on it) as `processing`, without the draft mark. */
    public function publish(): void
    {
        $this->leavingDraft = true;
        try {
            $config = array_diff_key((array) ($this->config ?? []), [self::DRAFT_MARK => true]);
            $this->forceFill(['status' => 'processing', 'error' => null, 'config' => $config === [] ? null : $config])->save();
        } finally {
            $this->leavingDraft = false;
        }
    }

    /** A generated document is stored, whatever the row's status says while it is being rebuilt. */
    public function hasStoredCopy(): bool
    {
        return $this->generated_at !== null && ! empty($this->data);
    }

    /**
     * REPORT-SNAPSHOT-PROTECT-001 — is there a finished document a reader may be given right now?
     *
     * A completed report, or one whose stored copy is being rebuilt: the row says `processing` and still holds the last
     * finished copy, because a rebuild replaces `data` only when it commits. A report being built for the first time has
     * nothing finished, and a failed one never did.
     */
    public function servesStoredCopy(): bool
    {
        return $this->status === 'completed' || ($this->status === 'processing' && $this->hasStoredCopy());
    }

    /** The operator's saved section choices; null in the column resolves to the audience defaults. */
    public function sectionSettings(): SectionSettings
    {
        return SectionSettings::fromArray($this->section_settings, app(ReportSectionRegistry::class));
    }

    /** @return HasMany<ReportExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    /** @return HasMany<ReportRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(ReportRecipient::class);
    }

    /**
     * The language the report is written in — `config.locale`, Arabic unless the report says English.
     *
     * One reader for it: the exporter, the shared link and the print payload all title the report in
     * its own language, and three copies of «ar unless en» is how one of them comes to answer
     * differently.
     */
    public function reportLocale(): string
    {
        $locale = ($this->config ?? [])['locale'] ?? null;

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }
}
