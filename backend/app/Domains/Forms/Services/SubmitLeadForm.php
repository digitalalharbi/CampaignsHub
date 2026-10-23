<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\CRM\Actions\AdvanceLead;
use App\Domains\CRM\Actions\LinkDuplicateLead;
use App\Domains\CRM\Actions\RecordActivity;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Conversions\ConversionDispatcher;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormSubmission;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FORMS-HUB-001 — the one way a public submission becomes a lead.
 *
 * ## The server decides
 *
 * Nothing about success is taken from the browser: the form is the PUBLISHED version looked up here, the
 * answers are judged by {@see FormSubmissionValidator}, the project comes off the form (a body that could
 * name its own project could name somebody else's), and the lead exists, in the existing Leads Centre,
 * before anything is reported as accepted. A tag manager that fired «lead» tells you what a browser
 * claimed; this is what happened.
 *
 * ## One submission is one lead, however many times it arrives
 *
 * The browser sends its own `submission_id`. Unique on (tenant, form, key), a double click and a retried
 * request are the same submission and return the first result with `duplicate = true` — never a second
 * lead. The same PERSON arriving through a second submission is a different matter and is handled where
 * it always was: {@see LinkDuplicateLead} links, never drops.
 *
 * ## Consent is a recorded fact
 *
 * When the version asks for consent, a submission without it is refused — not accepted and flagged. The
 * state and the wording's version are stored on the submission, so «what did this person agree to» has an
 * answer that does not depend on what the form says today.
 *
 * ## What is kept as attribution, and what is not
 *
 * UTM parameters, the click ids the supported platforms use, the landing PATH (no query string — it can
 * carry a token or a name) and the referrer's HOST. No IP address and no user-agent string are stored.
 */
final class SubmitLeadForm
{
    /** The click identifiers a submission may carry, by the platform that issues each. */
    public const CLICK_IDS = ['gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'sclid', 'li_fat_id', 'msclkid', 'twclid'];

    private const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function __construct(
        private readonly FormSubmissionValidator $validator,
        private readonly AdvanceLead $advance,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly FormFunnel $funnel,
        private readonly ConversionDispatcher $conversions,
        private readonly ProjectAbilities $abilities,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  submission_id, answers, consent{accepted}, attribution{...}, session_id?
     * @return array{submission: LeadFormSubmission, lead: ?Lead, duplicate: bool}
     *
     * @throws ValidationException
     */
    public function handle(LeadForm $form, array $payload): array
    {
        $version = LeadFormVersion::withoutGlobalScopes()->find($form->published_version_id);
        if ($form->status !== LeadForm::PUBLISHED || $version === null) {
            throw new \DomainException('This form is not accepting submissions.');
        }

        $key = is_string($payload['submission_id'] ?? null) ? $payload['submission_id'] : '';
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            throw ValidationException::withMessages(['submission_id' => 'A submission carries its own id (16–64 letters, digits, - or _).']);
        }

        $previous = $this->tenant->tenantId();
        $this->tenant->setTenantId((string) $form->tenant_id);

        try {
            return $this->within($form, $version, $key, $payload);
        } finally {
            $this->tenant->setTenantId($previous);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{submission: LeadFormSubmission, lead: ?Lead, duplicate: bool}
     */
    private function within(LeadForm $form, LeadFormVersion $version, string $key, array $payload): array
    {
        $existing = LeadFormSubmission::query()->where('lead_form_id', $form->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return ['submission' => $existing, 'lead' => $existing->lead_id === null ? null : Lead::query()->find($existing->lead_id), 'duplicate' => true];
        }

        $definition = $version->definition;
        $accepted = (bool) (($payload['consent']['accepted'] ?? false) === true);
        if (($definition['consent']['required'] ?? true) && ! $accepted) {
            throw ValidationException::withMessages(['consent' => 'This form needs your consent before it can be sent.']);
        }

        $clean = $this->validator->validate($definition, is_array($payload['answers'] ?? null) ? $payload['answers'] : []);
        $attribution = $this->attribution(is_array($payload['attribution'] ?? null) ? $payload['attribution'] : []);
        $now = Carbon::now();

        try {
            $result = DB::transaction(function () use ($form, $version, $key, $payload, $clean, $attribution, $accepted, $definition, $now): array {
                $submission = LeadFormSubmission::create([
                    'tenant_id' => $form->tenant_id,
                    'project_id' => $form->project_id,
                    'lead_form_id' => $form->id,
                    'lead_form_version_id' => $version->id,
                    'idempotency_key' => $key,
                    'session_id' => is_string($payload['session_id'] ?? null) ? mb_substr($payload['session_id'], 0, 64) : null,
                    'consent_state' => $accepted ? 'granted' : 'denied',
                    'consent_version' => $definition['consent']['version'] ?? null,
                    'measurement_consent' => ! ($definition['consent']['measurement']['enabled'] ?? false) ? 'not_asked' : ((bool) (($payload['consent']['measurement'] ?? false) === true) ? 'granted' : 'denied'),
                    'attribution' => $attribution,
                    'submitted_at' => $now,
                ]);

                // A referral code is verified against THIS tenant and form; a code that does not verify is ignored, never an error.
                $referrerId = isset($payload['ref']) && is_string($payload['ref']) ? ReferralCodes::verify($payload['ref'], (string) $form->tenant_id, (string) $form->id) : null;
                $referrer = $referrerId === null ? null : Lead::query()->where('project_id', $form->project_id)->find($referrerId);

                $clickId = null;
                foreach (self::CLICK_IDS as $type) {
                    if (isset($attribution['click_ids'][$type])) {
                        $clickId = $type.':'.$attribution['click_ids'][$type];
                        break;
                    }
                }

                $lead = Lead::create([
                    'tenant_id' => $form->tenant_id,
                    'project_id' => $form->project_id,
                    'name' => $clean['name'],
                    'email' => $clean['email'],
                    'phone' => $clean['phone'],
                    'source' => 'website',
                    'status' => 'new',
                    'provider' => 'campaignshub_form',
                    // The submission key, so the existing unique (tenant, provider, provider_lead_id) is a second
                    // wall behind the submission table's own.
                    'provider_lead_id' => $key,
                    'provider_created_at' => $now,
                    'received_at' => $now,
                    'form_id' => (string) $form->id,
                    'form_name' => $form->name,
                    'landing_page' => $attribution['landing_path'] ?? null,
                    'utm_source' => $attribution['utm_source'] ?? null,
                    'utm_medium' => $attribution['utm_medium'] ?? null,
                    'utm_campaign' => $attribution['utm_campaign'] ?? null,
                    'utm_content' => $attribution['utm_content'] ?? null,
                    'utm_term' => $attribution['utm_term'] ?? null,
                    'click_id' => $clickId,
                    'form_answers' => $clean['labelled'],
                    'referred_by_lead_id' => $referrer?->id,
                    'phone_normalized' => PhoneNumber::normalise($clean['phone']),
                    'email_normalized' => $clean['email'],
                ]);

                $submission->forceFill(['lead_id' => $lead->id])->save();
                if ($referrer !== null) {
                    $this->activity->execute($referrer, 'note', 'Referred a new lead through the form «'.$form->name.'»', ['referred_lead_id' => (string) $lead->id]);
                }

                $this->activity->execute($lead, 'status_change', 'Lead created from the form «'.$form->name.'»', ['form_id' => (string) $form->id, 'version' => $version->version, 'submission_id' => (string) $submission->id]);
                $this->audit->log(
                    action: 'lead.created_from_form',
                    entityType: Lead::class,
                    entityId: (string) $lead->id,
                    after: ['form_id' => (string) $form->id, 'version' => $version->version, 'consent' => $submission->consent_state],
                );

                $owner = $this->owner($form);
                if ($owner !== null) {
                    $lead = $this->advance->assign($lead, $owner);
                }
                // The follow-up the team promised on arrival: what the follow-up workspace and its overdue alerts already read.
                if ($form->follow_up_minutes !== null && $owner !== null) {
                    $lead = $this->advance->scheduleFollowUp($lead, Carbon::now()->addMinutes((int) $form->follow_up_minutes));
                }
                app(LinkDuplicateLead::class)->handle($lead);
                $this->funnel->leadCreated($form, $lead, $submission);
                if ($referrer !== null) {
                    $this->funnel->referred($form, $lead, $referrer);
                }

                return ['submission' => $submission, 'lead' => $lead, 'duplicate' => false];
            });

            // After the lead is committed: a destination being slow or down can never cost the lead.
            if ($result['lead'] !== null) {
                $this->conversions->forLead($form, $result['lead'], $result['submission']);
            }

            return $result;
        } catch (UniqueConstraintViolationException) {
            // Two requests with one key raced; the other won. This one is its duplicate.
            $won = LeadFormSubmission::query()->where('lead_form_id', $form->id)->where('idempotency_key', $key)->firstOrFail();

            return ['submission' => $won, 'lead' => $won->lead_id === null ? null : Lead::query()->find($won->lead_id), 'duplicate' => true];
        }
    }

    /**
     * Who gets this lead. One fixed person, or — in round-robin — whoever of the pool holds the fewest of THIS form's leads,
     * the earlier-listed person on a tie. A pool member who can no longer work the project's leads is skipped at the moment of
     * the draw, not at configuration: people are removed from projects after a form is built.
     */
    private function owner(LeadForm $form): ?int
    {
        if ($form->assignment_mode !== 'round_robin') {
            return $form->default_owner_id === null ? null : (int) $form->default_owner_id;
        }

        $pool = array_values(array_unique(array_map('intval', (array) $form->assignment_owner_ids)));
        $eligible = array_values(array_filter($pool, fn (int $id): bool => ($user = User::query()->find($id)) !== null
            && $this->abilities->allows($user, (string) $form->project_id, ProjectCapability::LEADS_UPDATE)));
        if ($eligible === []) {
            // Nobody in the pool can take it: fall back to the single owner, else leave it unassigned for the manager's queue.
            return $form->default_owner_id === null ? null : (int) $form->default_owner_id;
        }

        $held = Lead::query()->where('form_id', (string) $form->id)->whereIn('owner_id', $eligible)->selectRaw('owner_id, count(*) as n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $best = $eligible[0];
        foreach ($eligible as $id) {
            if ((int) ($held[$id] ?? 0) < (int) ($held[$best] ?? 0)) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function attribution(array $raw): array
    {
        $out = [];
        foreach (self::UTM as $k) {
            if (isset($raw[$k]) && is_string($raw[$k]) && trim($raw[$k]) !== '') {
                $out[$k] = mb_substr(trim($raw[$k]), 0, 190);
            }
        }
        $ids = [];
        foreach (self::CLICK_IDS as $k) {
            if (isset($raw[$k]) && is_string($raw[$k]) && preg_match('/^[A-Za-z0-9._\-]{4,200}$/', $raw[$k]) === 1) {
                $ids[$k] = $raw[$k];
            }
        }
        if ($ids !== []) {
            $out['click_ids'] = $ids;
        }
        // GA4 joins a server-side event to the visit by the client id of its own cookie; both are digits and dots, never a person's name.
        foreach (['ga_client_id' => '/^\d{4,12}\.\d{4,12}$/', 'ga_session_id' => '/^\d{6,14}$/'] as $k => $pattern) {
            if (isset($raw[$k]) && is_string($raw[$k]) && preg_match($pattern, $raw[$k]) === 1) {
                $out[$k] = $raw[$k];
            }
        }
        if (isset($raw['landing_path']) && is_string($raw['landing_path'])) {
            // The path only: a query string can carry a token, a phone number or a name.
            $path = parse_url($raw['landing_path'], PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $out['landing_path'] = mb_substr($path, 0, 190);
            }
        }
        if (isset($raw['referrer']) && is_string($raw['referrer'])) {
            $host = parse_url($raw['referrer'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $out['referrer_host'] = mb_substr(mb_strtolower($host), 0, 190);
            }
        }

        return $out;
    }
}
