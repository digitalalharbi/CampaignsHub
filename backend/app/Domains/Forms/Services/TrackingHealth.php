<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\ConversionDestination;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Domains\Tracking\Services\TrackingRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TRACKING-HEALTH-001 — whether a project's lead tracking can be trusted, and exactly what to do where it cannot.
 *
 * Every check is a statement about something that was MEASURED, with the number behind it and the action it asks for. A check
 * that cannot be made says so (`unavailable`, with the reason) instead of passing: «nothing wrong» and «could not look» are
 * different answers, and a health page that conflates them is how a broken pixel stays broken for a quarter.
 *
 * What this does NOT do, deliberately: it never states an accuracy figure. Browser tracking, consent, ad blockers and platform
 * attribution windows all lose events legitimately, so «98% tracked» would be a number the data cannot support. It reports
 * COVERAGE (how many leads carry a campaign tag, a click id, a measurement consent) and DIFFERENCES (what a platform reports
 * against what the Leads Centre holds), each with its denominator, and leaves the reading to the person.
 *
 * Severities: `critical` something is broken now; `warning` something is losing data; `info` worth knowing; `ok` looked and fine;
 * `unavailable` could not be checked.
 */
final class TrackingHealth
{
    /** A form that has been viewed this often with no submission at all is treated as broken, not unpopular. */
    private const BROKEN_FORM_VIEWS = 20;

    /** Fewer leads than this and a coverage percentage is noise. */
    private const MIN_FOR_COVERAGE = 5;

    public function __construct(private readonly ReportingTimezone $timezones, private readonly TrackingRecorder $recorder) {}

    /** @return array<string,mixed> */
    public function forProject(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $tz = $this->timezones->forProject($projectId);
        $start = $from->copy()->setTimezone($tz)->startOfDay()->utc();
        $end = $to->copy()->setTimezone($tz)->startOfDay()->addDay()->utc();
        $trackingOn = $this->recorder->enabled($tenantId, $projectId);

        $forms = LeadForm::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('status', LeadForm::PUBLISHED)->get();
        $destinations = ConversionDestination::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->get();

        $checks = [];
        $this->formChecks($checks, $tenantId, $projectId, $forms, $start, $end, $trackingOn);
        $coverage = $this->coverage($checks, $tenantId, $projectId, $start, $end);
        $deliveries = $this->deliveryChecks($checks, $tenantId, $projectId, $forms, $destinations, $start, $end);
        $reconciliation = $this->reconcile($checks, $tenantId, $projectId, $start, $end, $tz);

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2, 'unavailable' => 3, 'ok' => 4];
        usort($checks, static fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);

        return [
            'period' => ['from' => $start->copy()->setTimezone($tz)->toDateString(), 'to' => $end->copy()->subDay()->setTimezone($tz)->toDateString(), 'timezone' => $tz],
            'tracking_enabled' => $trackingOn,
            'summary' => [
                'critical' => count(array_filter($checks, static fn (array $c): bool => $c['severity'] === 'critical')),
                'warning' => count(array_filter($checks, static fn (array $c): bool => $c['severity'] === 'warning')),
            ],
            'checks' => $checks,
            'coverage' => $coverage,
            'deliveries' => $deliveries,
            'reconciliation' => $reconciliation,
            'confidence_note' => $this->bi('This page reports coverage and differences, each with its denominator. It never states an accuracy: consent, ad blockers, browsers and platform attribution windows lose events legitimately, so no honest percentage of «tracked» exists.', 'تعرض هذه الصفحة التغطية والفروق، كلٌّ مع مقامه. ولا تذكر دقّة أبدًا: الموافقة وحاجبات الإعلانات والمتصفحات ونوافذ الإسناد لدى المنصات تُفقد أحداثًا بشكل مشروع، فلا نسبة صادقة لـ«ما تم تتبعه».'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  Collection<int, LeadForm>  $forms
     */
    private function formChecks(array &$checks, string $tenant, string $project, $forms, Carbon $start, Carbon $end, bool $trackingOn): void
    {
        if ($forms->isEmpty()) {
            $checks[] = $this->check('no_published_forms', 'info', $this->bi('No published forms', 'لا نماذج منشورة'), $this->bi('Nothing in this project collects leads through a first-party form.', 'لا شيء في هذا المشروع يجمع عملاء محتملين عبر نموذج خاص بنا.'), null, $this->bi('Publish a form to start collecting.', 'انشر نموذجًا لتبدأ الجمع.'));

            return;
        }

        foreach ($forms as $form) {
            $submissions = (int) DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('lead_form_id', $form->id)->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)->count();
            $views = $trackingOn
                ? (int) DB::table('tracking_events')->where('tenant_id', $tenant)->where('subject_type', 'lead_form')->where('subject_id', $form->id)->where('event_type', TrackingEvent::FORM_VIEW)->where('is_bot', false)
                    ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->whereNotNull('session_id')->distinct()->count('session_id')
                : null;

            if ($views === null) {
                $checks[] = $this->check('form_visits:'.$form->id, 'unavailable', $this->bi("«{$form->name}»: visits cannot be checked", "«{$form->name}»: تعذّر فحص الزيارات"), $this->bi('Advanced tracking is off for this project, so a form that is viewed and never sent cannot be told from one nobody opens.', 'التتبع المتقدم متوقف لهذا المشروع، فلا يمكن التفريق بين نموذج يُشاهَد ولا يُرسَل ونموذج لا يفتحه أحد.'), ['submissions' => $submissions], $this->bi('Switch advanced tracking on for the project to see visits.', 'فعّل التتبع المتقدم للمشروع لترى الزيارات.'));
            } elseif ($views >= self::BROKEN_FORM_VIEWS && $submissions === 0) {
                $checks[] = $this->check('form_broken:'.$form->id, 'critical', $this->bi("«{$form->name}» is viewed and never sent", "«{$form->name}» يُشاهَد ولا يُرسَل أبدًا"), $this->bi("{$views} sessions viewed it and none produced a submission. That is a broken form far more often than an unpopular one.", "شاهده {$views} جلسة ولم تُنتج أي جلسة إرسالًا. هذا في الغالب نموذج معطّل لا نموذج غير مرغوب."), ['views' => $views, 'submissions' => 0], $this->bi('Open the form on a phone and send it. Check the consent box, a required field and the connection to the Leads Centre.', 'افتح النموذج على جوال وأرسله. افحص خانة الموافقة وحقلًا مطلوبًا والاتصال بمركز العملاء المحتملين.'));
            } elseif ($views === 0 && $submissions === 0) {
                $checks[] = $this->check('form_unseen:'.$form->id, 'info', $this->bi("«{$form->name}» had no visits", "«{$form->name}» بلا زيارات"), $this->bi('Nothing was recorded for it in this period.', 'لم يُسجَّل له شيء في هذه الفترة.'), ['views' => 0], $this->bi('Check the ad or link that should lead to it.', 'افحص الإعلان أو الرابط الذي يفترض أن يوصل إليه.'));
            } else {
                $checks[] = $this->check('form_ok:'.$form->id, 'ok', $this->bi("«{$form->name}»: visits and submissions both present", "«{$form->name}»: الزيارات والإرسالات موجودة"), null, ['views' => $views, 'submissions' => $submissions], null);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function coverage(array &$checks, string $tenant, string $project, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('lead_form_submissions as s')
            ->join('leads as l', 'l.id', '=', 's.lead_id')
            ->where('s.tenant_id', $tenant)->where('s.project_id', $project)->where('s.submitted_at', '>=', $start)->where('s.submitted_at', '<', $end)
            ->selectRaw("count(*) as n, count(*) filter (where l.utm_source is not null and l.utm_source <> '') as with_utm, count(*) filter (where l.click_id is not null) as with_click_id, count(*) filter (where s.measurement_consent = 'granted') as consented, count(*) filter (where s.measurement_consent = 'not_asked') as not_asked")
            ->first();
        $n = (int) ($rows->n ?? 0);
        $rate = static fn (int $part): ?float => $n >= self::MIN_FOR_COVERAGE ? round($part / $n, 4) : null;

        $coverage = [
            'form_leads' => $n,
            'with_campaign_tag' => $rate((int) ($rows->with_utm ?? 0)),
            'with_click_id' => $rate((int) ($rows->with_click_id ?? 0)),
            'measurement_consent_granted' => $rate((int) ($rows->consented ?? 0)),
            'minimum_for_a_rate' => self::MIN_FOR_COVERAGE,
        ];

        if ($n >= self::MIN_FOR_COVERAGE) {
            $untagged = $n - (int) ($rows->with_utm ?? 0);
            $checks[] = $untagged / $n > 0.5
                ? $this->check('missing_campaign_tag', 'warning', $this->bi('Most form leads carry no campaign tag', 'معظم عملاء النماذج بلا وسم حملة'), $this->bi("{$untagged} of {$n} leads arrived without a utm_source, so they cannot be credited to a campaign or a link.", "وصل {$untagged} من {$n} عميلًا بلا utm_source، فلا يمكن نسبتهم إلى حملة أو رابط."), ['without_tag' => $untagged, 'of' => $n], $this->bi('Add utm parameters to the ad and link addresses that lead to the form.', 'أضف معاملات utm إلى عناوين الإعلانات والروابط التي تقود إلى النموذج.'))
                : $this->check('campaign_tag_ok', 'ok', $this->bi('Most form leads carry a campaign tag', 'معظم عملاء النماذج يحملون وسم حملة'), null, ['without_tag' => $untagged, 'of' => $n], null);
        } else {
            $checks[] = $this->check('coverage_thin', 'unavailable', $this->bi('Too few form leads to judge coverage', 'عدد قليل جدًا من عملاء النماذج للحكم على التغطية'), $this->bi("Only {$n} in this period; a percentage over fewer than ".self::MIN_FOR_COVERAGE.' would be noise.', "{$n} فقط في هذه الفترة؛ نسبة على أقل من ".self::MIN_FOR_COVERAGE.' ستكون ضجيجًا.'), ['of' => $n], null);
        }

        return $coverage;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  Collection<int, LeadForm>  $forms
     * @param  Collection<int, ConversionDestination>  $destinations
     * @return list<array<string,mixed>>
     */
    private function deliveryChecks(array &$checks, string $tenant, string $project, $forms, $destinations, Carbon $start, Carbon $end): array
    {
        $out = [];
        foreach ($destinations as $d) {
            if ($d->kind === 'gtm_web') {
                continue;
            }
            $by = ConversionDelivery::withoutGlobalScopes()->where('tenant_id', $tenant)->where('conversion_destination_id', $d->id)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
            $stuck = (int) ConversionDelivery::withoutGlobalScopes()->where('tenant_id', $tenant)->where('conversion_destination_id', $d->id)->where('status', ConversionDelivery::QUEUED)->where('created_at', '<', Carbon::now()->subHour())->count();

            // Leads that WERE eligible: consent granted on a form this destination covers.
            $eligible = (int) DB::table('lead_form_submissions')->where('tenant_id', $tenant)->where('project_id', $project)->where('measurement_consent', 'granted')
                ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)
                ->when($d->lead_form_id !== null, fn ($q) => $q->where('lead_form_id', $d->lead_form_id))->count();
            $reported = ($by['sent'] ?? 0) + ($by['test'] ?? 0) + ($by['queued'] ?? 0) + ($by['failed'] ?? 0);

            $row = [
                'destination_id' => (string) $d->id, 'name' => $d->name, 'kind' => $d->kind, 'status' => $d->status,
                'sent' => $by['sent'] ?? 0, 'failed' => $by['failed'] ?? 0, 'queued' => $by['queued'] ?? 0, 'queued_stuck' => $stuck,
                'skipped_consent' => $by['skipped_consent'] ?? 0, 'test' => $by['test'] ?? 0,
                'eligible_leads' => $eligible, 'attempted' => $reported, 'gap' => $eligible - $reported,
            ];
            $out[] = $row;

            $live = $d->status === ConversionDestination::LIVE;
            if (($by['failed'] ?? 0) > 0) {
                $checks[] = $this->check('delivery_failed:'.$d->id, $live ? 'critical' : 'info', $this->bi("«{$d->name}»: {$row['failed']} failed deliveries", "«{$d->name}»: {$row['failed']} محاولة فاشلة"), $live ? $this->bi('Conversions that should have reached the platform did not.', 'تحويلات كان يجب أن تصل إلى المنصة لم تصل.') : $this->bi('This destination is not live, so nothing real was lost.', 'هذه الوجهة غير مباشرة، فلم يضع شيء حقيقي.'), ['failed' => $row['failed']], $live ? $this->bi('Open the destination, read the last error, fix the credential or address, then retry the failed rows.', 'افتح الوجهة واقرأ آخر خطأ وأصلح بيانات الاعتماد أو العنوان ثم أعد المحاولات الفاشلة.') : null);
            }
            if ($stuck > 0) {
                $checks[] = $this->check('delivery_stuck:'.$d->id, 'warning', $this->bi("«{$d->name}»: {$stuck} deliveries waiting over an hour", "«{$d->name}»: {$stuck} محاولة تنتظر أكثر من ساعة"), $this->bi('Queued deliveries that are not moving usually mean no queue worker is running, or the platform keeps answering with a retryable error.', 'التحويلات المنتظرة التي لا تتحرك تعني عادةً أن عامل الطابور لا يعمل، أو أن المنصة ترد بخطأ قابل لإعادة المحاولة.'), ['stuck' => $stuck], $this->bi('Check the queue worker and the destination\'s last error.', 'افحص عامل الطابور وآخر خطأ للوجهة.'));
            }
            if ($live && $row['gap'] > 0) {
                $checks[] = $this->check('delivery_gap:'.$d->id, 'warning', $this->bi("«{$d->name}»: {$row['gap']} consented leads were never attempted", "«{$d->name}»: {$row['gap']} عميلًا موافقًا لم تُجرَ له أي محاولة"), $this->bi("{$eligible} leads agreed to be reported and {$reported} deliveries exist.", "وافق {$eligible} عميلًا على الإبلاغ وهناك {$reported} محاولة."), ['eligible' => $eligible, 'attempted' => $reported], $this->bi('The destination may have been made live after those leads arrived; they are not sent retroactively.', 'ربما فُعّلت الوجهة بعد وصول هؤلاء العملاء؛ لا يُرسَلون بأثر رجعي.'));
            }
            if ($live) {
                $needsAsk = $forms->filter(fn (LeadForm $f): bool => ($d->lead_form_id === null || $d->lead_form_id === $f->id) && ! $this->asksMeasurement($f));
                foreach ($needsAsk as $f) {
                    $checks[] = $this->check('never_asked:'.$d->id.':'.$f->id, 'warning', $this->bi("«{$d->name}» is live but «{$f->name}» never asks for measurement consent", "«{$d->name}» مباشرة لكن «{$f->name}» لا يطلب موافقة القياس أبدًا"), $this->bi('Nothing from that form is reported, by design.', 'لا يُبلَّغ عن شيء من هذا النموذج، وهذا مقصود.'), null, $this->bi('Turn on the optional measurement consent in the form builder, or scope the destination to a form that asks.', 'فعّل موافقة القياس الاختيارية في منشئ النموذج، أو اربط الوجهة بنموذج يطلبها.'));
                }
            }
        }

        if ($destinations->where('kind', '!=', 'gtm_web')->isEmpty()) {
            $checks[] = $this->check('no_destinations', 'info', $this->bi('No conversion destinations', 'لا وجهات تحويلات'), $this->bi('Leads are stored; no platform is told about them.', 'العملاء المحتملون محفوظون ولا تُبلَّغ أي منصة عنهم.'), null, null);
        }

        return $out;
    }

    private function asksMeasurement(LeadForm $form): bool
    {
        $v = LeadFormVersion::withoutGlobalScopes()->find($form->published_version_id);

        return (bool) ($v?->definition['consent']['measurement']['enabled'] ?? false);
    }

    /**
     * What each ad platform says it produced, against what the Leads Centre holds from it.
     *
     * Not an error check: a platform counts on its own attribution window, a lead can be deleted or merged, and a lead form
     * sync can lag. A difference is a question, and each row carries both numbers so the person can ask it.
     *
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function reconcile(array &$checks, string $tenant, string $project, Carbon $start, Carbon $end, string $tz): array
    {
        // A metric day is a DATE in the project's own time zone; comparing it with the UTC date of the window's edge drops the last day.
        $firstDay = $start->copy()->setTimezone($tz)->toDateString();
        $lastDay = $end->copy()->subDay()->setTimezone($tz)->toDateString();
        // Only the accounts this project currently shows: a deselected or re-bound account's figures are not this project's to reconcile.
        $reportedQuery = DB::table('daily_metrics')->where('tenant_id', $tenant)->where('project_id', $project)->where('metric_key', 'leads')
            ->where('metric_date', '>=', $firstDay)->where('metric_date', '<=', $lastDay);
        BoundAccountVisibility::apply($reportedQuery, 'daily_metrics');
        $reported = $reportedQuery->selectRaw('provider, sum(value) as n')->groupBy('provider')->pluck('n', 'provider');
        $stored = DB::table('leads')->where('tenant_id', $tenant)->where('project_id', $project)->whereNull('deleted_at')->whereNotNull('provider')->where('provider', '<>', 'campaignshub_form')
            ->where('received_at', '>=', $start)->where('received_at', '<', $end)->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');

        $out = [];
        foreach ($reported->keys()->merge($stored->keys())->unique()->sort() as $provider) {
            $r = (float) ($reported[$provider] ?? 0);
            $s = (int) ($stored[$provider] ?? 0);
            $out[] = ['provider' => $provider, 'platform_reported' => (int) round($r), 'stored' => $s, 'difference' => (int) round($r) - $s, 'stored_to_reported' => $r > 0 ? round($s / $r, 4) : null];
            if ($r >= 10 && $s / $r < 0.8) {
                $checks[] = $this->check('reconcile:'.$provider, 'warning', $this->bi(ucfirst((string) $provider).' reports more leads than the Leads Centre holds', 'المنصة '.ucfirst((string) $provider).' تُبلغ عن عملاء أكثر مما في مركز العملاء المحتملين'), $this->bi(sprintf('%d reported, %d stored (%d%%).', (int) round($r), $s, (int) round($s / $r * 100)), sprintf('%d مُبلَّغ، %d محفوظ (%d%%).', (int) round($r), $s, (int) round($s / $r * 100))), ['reported' => (int) round($r), 'stored' => $s], $this->bi('Check that the lead form sync for this platform is running and the form is bound to this project. A platform\'s own attribution window explains part of a gap.', 'تحقق من أن مزامنة نماذج العملاء لهذه المنصة تعمل وأن النموذج مربوط بهذا المشروع. نافذة الإسناد لدى المنصة تفسّر جزءًا من الفرق.'));
            }
        }
        if ($out === []) {
            $checks[] = $this->check('reconcile_none', 'unavailable', $this->bi('Nothing to reconcile', 'لا شيء للمطابقة'), $this->bi('No ad platform reported leads for this project in the period.', 'لم تُبلغ أي منصة إعلانية عن عملاء محتملين لهذا المشروع في الفترة.'), null, null);
        }

        return $out;
    }

    /**
     * Every sentence travels in both languages: the product is Arabic-first and a health page that explains itself in English only
     * to an Arabic-reading team is a page they cannot act on.
     *
     * @param  array{en: string, ar: string}  $title
     * @param  array{en: string, ar: string}|null  $detail
     * @param  array<string,mixed>|null  $numbers
     * @param  array{en: string, ar: string}|null  $action
     * @return array<string,mixed>
     */
    private function check(string $key, string $severity, array $title, ?array $detail, ?array $numbers, ?array $action): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'numbers' => $numbers, 'action' => $action];
    }

    /** @return array{en: string, ar: string} */
    private function bi(string $en, string $ar): array
    {
        return ['en' => $en, 'ar' => $ar];
    }
}
