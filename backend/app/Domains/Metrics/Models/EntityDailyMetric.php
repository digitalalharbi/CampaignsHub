<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * METRICS-BACKBONE-001 — one day of one ad squad or one ad.
 *
 * `is_demo` is deliberately absent from `$fillable`, exactly as it is on {@see DailyMetric}: a demo
 * flag that could be mass-assigned is one an untrusted payload could clear, disguising seeded rows
 * as real. The ingest sets it explicitly.
 */
final class EntityDailyMetric extends Model
{
    use HasUuids;

    protected $table = 'entity_daily_metrics';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider',
        'entity_type', 'entity_id', 'external_entity_id',
        'external_campaign_id', 'external_ad_set_id',
        'metric_date', 'attribution_window',
        'impressions', 'reach', 'frequency',
        'spend', 'spend_original', 'revenue', 'revenue_original',
        'original_currency', 'project_currency',
        'clicks', 'landing_page_views', 'engagements',
        'video_views', 'video_views_2s', 'video_views_5s', 'video_views_15s',
        'video_p25', 'video_p50', 'video_p75', 'video_p100', 'video_watch_seconds',
        'conversions', 'purchases', 'add_to_cart', 'checkout',
        'leads', 'sign_ups', 'installs', 'app_opens', 'page_views',
        'sync_run_id',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'metric_date' => 'date',
        'is_demo' => 'boolean',
    ];

    /** The two rungs this table measures. */
    public const AD_SET = 'ad_set';

    public const AD = 'ad';
}
