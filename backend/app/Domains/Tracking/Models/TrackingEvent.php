<?php

declare(strict_types=1);

namespace App\Domains\Tracking\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TRACKING-EVENTS-001 — one measured interaction. See the migration for what is and is not stored.
 *
 * `is_demo` is not fillable, as on the metric models: a demo flag that could be mass-assigned is one a payload
 * could clear.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $project_id
 * @property string $event_type
 * @property string $subject_type
 * @property string $subject_id
 * @property bool $is_bot
 * @property Carbon $occurred_at
 */
final class TrackingEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const SHORT_LINK_OPEN = 'short_link_open';

    public const SHORT_LINK_REDIRECT = 'short_link_redirect';

    public const REPORT_VIEW = 'report_view';

    public const REPORT_DOWNLOAD = 'report_download';

    public const CAMPAIGN_CLICK = 'campaign_click';

    public const INFLUENCER_LINK_CLICK = 'influencer_link_click';

    public const BIO_BUTTON_CLICK = 'bio_button_click';

    public const WHATSAPP_CLICK = 'whatsapp_click';

    public const CONVERSION_EVENT = 'conversion_event';

    /*
     * FORMS-FUNNEL-EVENTS-001 — the funnel of a first-party form, in the order a person meets it.
     *
     * The first five are written from the BROWSER (the visit as it happened, consent-aware); the rest are written
     * by the Leads Centre when the thing they name happens to the lead — never by the browser, which cannot know.
     * `referred` is reserved: no referral entity exists yet, and an event nothing writes is not a claim.
     */
    public const PAGE_VIEW = 'page_view';

    public const FORM_VIEW = 'form_view';

    public const FORM_START = 'form_start';

    public const STEP_COMPLETED = 'step_completed';

    public const SUBMIT_ATTEMPT = 'submit_attempt';

    public const LEAD_CREATED = 'lead_created';

    public const LEAD_CONTACTED = 'lead_contacted';

    public const LEAD_QUALIFIED = 'lead_qualified';

    public const LEAD_APPOINTMENT = 'lead_appointment';

    public const LEAD_REFERRED = 'lead_referred';

    public const LEAD_WON = 'lead_won';

    /** What the browser may report about a form. Anything else sent to the public endpoint is refused. */
    public const BROWSER_FUNNEL = [self::PAGE_VIEW, self::FORM_VIEW, self::FORM_START, self::STEP_COMPLETED, self::SUBMIT_ATTEMPT];

    /** What only the server writes, by the lead's own transitions. */
    public const SERVER_FUNNEL = [self::LEAD_CREATED, self::LEAD_CONTACTED, self::LEAD_QUALIFIED, self::LEAD_APPOINTMENT, self::LEAD_REFERRED, self::LEAD_WON];

    public const TYPES = [
        self::SHORT_LINK_OPEN, self::SHORT_LINK_REDIRECT, self::REPORT_VIEW, self::REPORT_DOWNLOAD, self::CAMPAIGN_CLICK,
        self::INFLUENCER_LINK_CLICK, self::BIO_BUTTON_CLICK, self::WHATSAPP_CLICK, self::CONVERSION_EVENT,
        self::PAGE_VIEW, self::FORM_VIEW, self::FORM_START, self::STEP_COMPLETED, self::SUBMIT_ATTEMPT,
        self::LEAD_CREATED, self::LEAD_CONTACTED, self::LEAD_QUALIFIED, self::LEAD_APPOINTMENT, self::LEAD_REFERRED, self::LEAD_WON,
    ];

    protected $table = 'tracking_events';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'project_id', 'event_type', 'subject_type', 'subject_id', 'source',
        'destination_type', 'destination_host', 'occurred_at', 'recorded_at',
        'consent_state', 'visitor_key', 'visitor_hash', 'visitor_confidence',
        'is_bot', 'bot_reason', 'ua_family',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'dedupe_key',
        'session_id', 'lead_id', 'step', 'click_id_type',
    ];

    protected $casts = ['is_bot' => 'boolean', 'is_demo' => 'boolean', 'occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
}
