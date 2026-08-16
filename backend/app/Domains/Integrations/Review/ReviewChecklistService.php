<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Review;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Legal\Models\PlatformSetting;
use Illuminate\Support\Facades\URL;

/**
 * REVIEW-001 — one provider's checklist, with the derived half answered from the system.
 *
 * ## Why derived items cannot be ticked
 *
 * A checklist an operator can mark complete without doing anything is a checklist that lies. Whether
 * the redirect URI is HTTPS, whether the client secret is present, which scopes the connector asks
 * for — all of these are facts this application already knows, so it states them and refuses the
 * tick. What it genuinely cannot see is what happens inside Google's or Meta's console, and those
 * are the only items an operator declares.
 *
 * ## Why an HTTP redirect URI is `missing` rather than `ready`
 *
 * Every one of these platforms rejects a non-HTTPS redirect outright. Showing a working localhost URL
 * as satisfied would let somebody reach the submission with the one value guaranteed to fail.
 */
final class ReviewChecklistService
{
    public function __construct(private readonly ProviderConfigurationService $configuration) {}

    /**
     * @return array<string,mixed>
     */
    public function for(string $provider): array
    {
        $definition = ProviderCatalogue::get($provider);
        $declared = ProviderReviewItem::query()
            ->where('provider', $definition->key)
            ->get()
            ->keyBy('requirement');

        $items = [];

        foreach (ReviewCatalogue::for($definition->key) as $requirement) {
            $items[] = $requirement['source'] === ReviewCatalogue::SOURCE_DERIVED
                ? $this->derived($requirement, $definition)
                : $this->declaredItem($requirement, $declared->get($requirement['key']));
        }

        return [
            'provider' => $definition->key,
            'label' => $definition->label,
            'label_ar' => $definition->labelAr,
            'items' => $items,
            'summary' => $this->summarise($items),
        ];
    }

    /**
     * @param  array<string,string>  $requirement
     * @return array<string,mixed>
     */
    private function derived(array $requirement, object $definition): array
    {
        [$status, $value, $detailAr, $detailEn] = $this->resolveDerived($requirement['key'], $definition);

        return [
            ...$this->shape($requirement),
            'status' => $status,
            'value' => $value,
            'detail_ar' => $detailAr,
            'detail_en' => $detailEn,
            // Derived items are read-only by construction — see the class note.
            'editable' => false,
        ];
    }

    /**
     * @return array{0:string,1:?string,2:?string,3:?string}
     */
    private function resolveDerived(string $key, object $definition): array
    {
        $operator = PlatformSetting::current();
        $base = rtrim((string) config('app.url'), '/');
        $https = static fn (string $url): bool => str_starts_with($url, 'https://');

        return match ($key) {
            'homepage_url' => [$https($base) ? 'ready' : 'missing', $base, null,
                $https($base) ? null : 'The reviewer will open this over HTTPS; a local URL cannot be submitted.'],

            'privacy_url' => [$https($base) ? 'ready' : 'missing', $base.'/privacy', null, null],
            'terms_url' => [$https($base) ? 'ready' : 'missing', $base.'/terms', null, null],
            'data_deletion_url' => [$https($base) ? 'ready' : 'missing', $base.'/account-deletion', null, null],

            'redirect_uri' => (function () use ($definition, $https) {
                $uri = $definition->redirectUri();

                return [
                    $https($uri) ? 'ready' : 'missing',
                    $uri,
                    $https($uri) ? null : 'الرابط ليس HTTPS، وكل هذه المنصات ترفض رابط عودة غير مؤمَّن.',
                    $https($uri) ? null : 'This is not HTTPS, and every one of these platforms refuses an insecure redirect.',
                ];
            })(),

            'least_privilege' => [
                'ready',
                implode(' · ', $definition->scopes) ?: '—',
                'النطاقات المطلوبة قراءة فقط؛ لا يطلب المنتج إنشاء حملة ولا تعديلها ولا النشر نيابةً عنك.',
                'Every scope requested is read-only; the product asks for nothing that creates, edits or posts.',
            ],

            /*
             * Zid's manager token is DERIVED because the system can see whether it is configured, and
             * it is the reason a perfectly valid token returns nothing — precisely the failure a
             * checklist exists to pre-empt.
             *
             * Snapchat's organisation id used to share this branch. It no longer exists as a system
             * credential (SNAP-ORG-001): one id in one row could only ever name one customer's
             * organisation, and organisations are now discovered from each customer's own token.
             */
            'manager_token' => (function () use ($definition) {
                $field = 'manager_token';
                $missing = in_array($field, $this->configuration->missing($definition->key), true);

                return [
                    $missing ? 'missing' : 'ready',
                    null,
                    $missing ? 'لم يُدخل بعد — رمز صحيح بدونه لا يعرض أي بيانات.' : null,
                    $missing ? 'Not entered yet — a valid token without it returns no data.' : null,
                ];
            })(),

            'api_version_header' => ['ready', '2411', null, null],

            'cart_endpoint_absent' => [
                'approved',
                null,
                'مؤكَّد في الموصّل: يرفض بدل إعادة قائمة فارغة، وتُقرأ الجولة partial.',
                'Confirmed in the connector: it refuses rather than returning an empty list, and the run reads partial.',
            ],

            default => ['missing', null, null, null],
        };
    }

    /**
     * @param  array<string,string>  $requirement
     * @return array<string,mixed>
     */
    private function declaredItem(array $requirement, ?ProviderReviewItem $row): array
    {
        return [
            ...$this->shape($requirement),
            'status' => $row?->status ?? 'missing',
            'note' => $row?->note,
            'updated_at' => $row?->updated_at?->toIso8601String(),
            'value' => null,
            'detail_ar' => null,
            'detail_en' => null,
            'editable' => true,
        ];
    }

    /**
     * @param  array<string,string>  $requirement
     * @return array<string,string>
     */
    private function shape(array $requirement): array
    {
        return [
            'key' => $requirement['key'],
            'source' => $requirement['source'],
            'label_ar' => $requirement['ar'],
            'label_en' => $requirement['en'],
            'why_ar' => $requirement['why_ar'],
            'why_en' => $requirement['why_en'],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int|bool>
     */
    private function summarise(array $items): array
    {
        $count = static fn (string $status): int => count(array_filter($items, static fn ($i) => $i['status'] === $status));

        return [
            'total' => count($items),
            'missing' => $count('missing'),
            'ready' => $count('ready'),
            'submitted' => $count('submitted'),
            'approved' => $count('approved'),
            // The only question an operator actually has about this screen.
            'submittable' => $count('missing') === 0,
        ];
    }
}
