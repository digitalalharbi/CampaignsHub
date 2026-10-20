<?php

declare(strict_types=1);

/*
 * WIDGET-REGISTRY-001 — the visual widgets the product can place on its three surfaces, in ONE list.
 *
 * `surfaces`: where a widget may appear — `dashboard` (executive overview), `analytics` (exploration) and `report`
 * (the client report, preview and shared link).
 *
 * `module`: the capability module that must be on for the project (see config/capabilities.php), or null for a
 * widget that belongs to the core product. A widget whose module is off is not drawn, and on the client-facing
 * surface it is not even listed.
 *
 * `defaults`: whether the widget is visible, in what order and how wide, per surface, before anyone configured
 * anything. A widget that sits behind an explicit module therefore appears on no existing dashboard until that
 * module is switched on.
 *
 * `kind` names the drawing, not the data: trend | bars | donut | funnel | kpis | table | gallery.
 */
return [
    'sizes' => ['third', 'half', 'full'],

    'widgets' => [
        'link_opens' => [
            'kind' => 'trend',
            'module' => 'advanced_tracking',
            'surfaces' => ['dashboard', 'analytics', 'report'],
            'title' => ['en' => 'Link activity', 'ar' => 'نشاط الروابط'],
            'defaults' => [
                'dashboard' => ['visible' => true, 'order' => 40, 'size' => 'half'],
                'analytics' => ['visible' => true, 'order' => 10, 'size' => 'full'],
                'report' => ['visible' => false, 'order' => 40, 'size' => 'full'],
            ],
        ],
        'attribution_credit' => [
            'kind' => 'bars',
            'module' => 'attribution_intelligence',
            'surfaces' => ['dashboard', 'analytics', 'report'],
            'title' => ['en' => 'Attribution by channel', 'ar' => 'الإسناد حسب القناة'],
            'defaults' => [
                'dashboard' => ['visible' => true, 'order' => 50, 'size' => 'half'],
                'analytics' => ['visible' => true, 'order' => 20, 'size' => 'full'],
                'report' => ['visible' => false, 'order' => 50, 'size' => 'full'],
            ],
        ],
    ],
];
