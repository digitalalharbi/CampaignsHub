<?php

declare(strict_types=1);

namespace App\Domains\Settings\Services;

/**
 * The shipped defaults for every editable public surface. A tenant that has never edited a page falls back
 * to these, so the public site always renders. Section KEYS are stable contracts the frontend renders by —
 * the editor may retitle, reorder, enable/disable and change buttons, but never invents unknown keys.
 */
final class PublicPageDefaults
{
    /** @return array<string,mixed> */
    public static function for(string $page): array
    {
        return match ($page) {
            'home' => self::home(),
            'portal_paid' => self::portal(
                'بوابة الحملات المدفوعة',
                'أطلق حملاتك المدفوعة عبر المنصات وتابع نتائجها في مكان واحد.',
                'اطلب حملة مدفوعة',
                '/requests/new?portal=paid',
            ),
            'portal_influencer' => self::portal(
                'بوابة المؤثرين والمحتوى',
                'أدر حملات المؤثرين والمحتوى الذي ينتجه المستخدمون من طلب واحد.',
                'اطلب حملة مؤثرين',
                '/requests/new?portal=influencer',
            ),
            'portal_tracking' => self::portal(
                'متابعة الطلبات',
                'تابع حالة طلبك ومراسلاتك وملفاتك بخطوات واضحة.',
                'تابع طلبي',
                '/requests/track',
            ),
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private static function home(): array
    {
        return [
            'hero' => [
                'enabled' => true,
                'order' => 1,
                'eyebrow' => 'منصة إدارة الحملات الإعلانية',
                'title' => 'أدِر حملاتك الإعلانية وتابع نتائجك في مكان واحد',
                'desc' => 'تابع الأداء والميزانيات والنتائج عبر جميع المنصات، ونظّم عملاءك ومشاريعك، وأنشئ تقارير احترافية بسهولة.',
                'primary_cta' => ['label' => 'ابدأ الآن', 'to' => '/register'],
                'secondary_cta' => ['label' => 'اطلب خدمة', 'to' => '/requests/new'],
            ],
            'portals' => ['enabled' => true, 'order' => 2, 'title' => 'اختر ما يناسبك', 'subtitle' => 'ابدأ من البوابة المناسبة لاحتياجك.'],
            'services' => ['enabled' => true, 'order' => 3, 'title' => 'الخدمات', 'subtitle' => 'ما الذي يمكننا إدارته لك.'],
            'steps' => ['enabled' => true, 'order' => 4, 'title' => 'كيف تعمل', 'subtitle' => 'من الطلب إلى النتائج بخطوات واضحة.'],
            'features' => ['enabled' => true, 'order' => 5, 'title' => 'المزايا', 'subtitle' => 'كل ما تحتاجه لإدارة الإعلانات.'],
            'platforms' => ['enabled' => true, 'order' => 6, 'title' => 'المنصات المدعومة', 'subtitle' => 'حالة كل منصة بصدق.'],
            'reports' => ['enabled' => true, 'order' => 7, 'title' => 'التقارير والتنبيهات', 'subtitle' => 'نتائج واضحة جاهزة للمشاركة.'],
            'footer' => ['enabled' => true, 'order' => 8, 'tagline' => 'أدِر إعلاناتك من مكان واحد.'],
        ];
    }

    /** @return array<string,mixed> */
    private static function portal(string $title, string $desc, string $ctaLabel, string $ctaTo): array
    {
        return [
            'enabled' => true,
            'hero' => [
                'enabled' => true,
                'order' => 1,
                'title' => $title,
                'desc' => $desc,
                'primary_cta' => ['label' => $ctaLabel, 'to' => $ctaTo],
            ],
            'highlights' => ['enabled' => true, 'order' => 2, 'title' => 'لماذا هذه البوابة', 'subtitle' => ''],
            'steps' => ['enabled' => true, 'order' => 3, 'title' => 'الخطوات', 'subtitle' => ''],
        ];
    }
}
