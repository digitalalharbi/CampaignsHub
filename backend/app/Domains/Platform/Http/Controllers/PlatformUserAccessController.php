<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Identity\Support\UserSessions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SUPERADMIN-ACCESS-001 — restore a person's access from the console, without seeing or setting their password.
 *
 * - `revoke_sessions`: every session and API token ends now (a stolen session, a lost laptop).
 * - `password_link`: a single-use, expiring set-password link, emailed AND handed to the operator once (mail can be down); the old
 *   password keeps working until the person uses it. The link is never logged or audited.
 * - `reset_mfa`: turns two-factor off so a person who lost their device can sign in and enrol again; their sessions end too.
 *
 * Every action needs a reason, which goes to the audit trail with who did it. The two that hand back a way in (`password_link`,
 * `reset_mfa`) also need the operator's own current password — a re-check at the moment of the sensitive act, not only at sign-in. No
 * action can be taken on another platform admin, and nothing here changes memberships, projects, integrations or subscriptions.
 */
final class PlatformUserAccessController extends Controller
{
    private const ACTIONS = ['revoke_sessions', 'password_link', 'reset_mfa'];

    private const NEEDS_PASSWORD = ['password_link', 'reset_mfa'];

    public function __construct(
        private readonly UserSessions $sessions,
        private readonly PasswordResetService $resets,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /api/v1/admin/users/{user}/access */
    public function act(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'current_password' => ['nullable', 'string'],
        ]);

        /** @var User $operator */
        $operator = $request->user();

        abort_if($user->is_platform_admin && $user->getKey() !== $operator->getKey(), 403, 'Another platform admin is not managed from here.');

        if (in_array($data['action'], self::NEEDS_PASSWORD, true)
            && ! Hash::check((string) ($data['current_password'] ?? ''), (string) $operator->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        $result = match ((string) $data['action']) {
            'revoke_sessions' => $this->revokeSessions($user),
            'password_link' => $this->passwordLink($user),
            default => $this->resetMfa($user),
        };

        $this->audit->log(
            action: 'platform.user.'.$data['action'],
            entityType: User::class,
            entityId: (string) $user->getKey(),
            // The reason and the outcome; a link is a credential and is never written here.
            after: ['email' => $user->email, 'delivery' => $result['delivery'] ?? null],
            reason: $data['reason'],
            userId: (int) $operator->getKey(),
        );

        return ApiResponse::success($result, 'Done.');
    }

    /** @return array<string, mixed> */
    private function revokeSessions(User $user): array
    {
        $this->sessions->revokeAll($user);

        return ['action' => 'revoke_sessions', 'sessions_revoked' => true];
    }

    /** @return array<string, mixed> */
    private function passwordLink(User $user): array
    {
        $issued = $this->resets->issueForOperator((string) $user->email);

        return ['action' => 'password_link', 'link' => $issued['link'] ?? null, 'delivery' => $issued['delivery'] ?? null];
    }

    /** @return array<string, mixed> */
    private function resetMfa(User $user): array
    {
        $user->forceFill(['two_factor_enabled' => false, 'two_factor_secret' => null])->save();
        $this->sessions->revokeAll($user);

        return ['action' => 'reset_mfa', 'mfa_enabled' => false, 'sessions_revoked' => true];
    }
}
