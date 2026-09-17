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
        'tenant_id', 'project_id', 'campaign_id', 'name', 'type', 'form', 'audience', 'mode', 'version', 'campaign_objective', 'status',
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
