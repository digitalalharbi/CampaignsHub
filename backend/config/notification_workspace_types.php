<?php

declare(strict_types=1);

/*
 * NOTIF-V2-ENGINE-001 — the workspace-type abstraction (Owner spec §0). DATA, not logic: code reads a profile and never
 * branches on a concrete type. A future type is a new entry here plus its translation keys.
 *
 * resolve: how a workspace (and a member's view of it) maps to a profile key.
 *   - portals:        membership portal => profile (a client-portal login sees the workspace through its own profile)
 *   - account_types:  tenants.account_type => profile
 *   - default:        everything else
 */
return [
    'resolve' => [
        'portals' => ['portal' => 'client_portal'],
        'account_types' => ['brand' => 'business', 'self_serve_company' => 'business'],
        'default' => 'agency',
    ],

    'profiles' => [
        'agency' => [
            'roles' => ['finance', 'project_manager', 'admin', 'client', 'other'],
            'responsible_role' => 'admin',
            'client_role' => 'client',
            'terminology' => [
                'client' => 'notifications_v2.terms.agency.client',
                'project' => 'notifications_v2.terms.agency.project',
            ],
            'work_days' => [0, 1, 2, 3, 4],
            'member_roles' => [
                'tenant-owner' => 'admin', 'project-manager' => 'project_manager', 'finance' => 'finance',
                'media-buyer' => 'other', 'analyst' => 'other', 'reporter' => 'other', 'viewer' => 'other',
            ],
            'sees_sensitive_events' => true,
        ],
        'business' => [
            'roles' => ['owner', 'marketing', 'finance', 'other'],
            'responsible_role' => 'owner',
            'client_role' => null,
            'terminology' => [
                'client' => 'notifications_v2.terms.business.client',
                'project' => 'notifications_v2.terms.business.project',
            ],
            'work_days' => [0, 1, 2, 3, 4],
            'member_roles' => [
                'tenant-owner' => 'owner', 'project-manager' => 'marketing', 'media-buyer' => 'marketing', 'finance' => 'finance',
                'analyst' => 'other', 'reporter' => 'other', 'viewer' => 'other',
            ],
            'sees_sensitive_events' => true,
        ],
        'client_portal' => [
            'roles' => ['account_owner', 'team'],
            'responsible_role' => 'account_owner',
            'client_role' => 'account_owner',
            'terminology' => [
                'client' => 'notifications_v2.terms.client_portal.client',
                'project' => 'notifications_v2.terms.client_portal.project',
            ],
            'work_days' => [0, 1, 2, 3, 4],
            'member_roles' => ['tenant-owner' => 'account_owner'],
            // Client-portal users get their own projects only, never the sensitive operational events (spec §5).
            'sees_sensitive_events' => false,
        ],
    ],
];
