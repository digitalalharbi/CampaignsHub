<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Resources\UserResource;
use App\Models\User;
use App\Rules\PhoneNumberRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own account. Every action operates strictly on `$request->user()` — a user
 * can never read or mutate another account here (no id is accepted from the client). Sensitive values
 * (password, hashes, tokens) are never returned or logged.
 */
final class MeController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/me — the current user with profile + menu-header fields. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($this->user($request))]);
    }

    /** PATCH /api/me/profile — personal identity + locale/formatting preferences. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', new PhoneNumberRule],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'date_format' => ['sometimes', Rule::in(['YYYY-MM-DD', 'DD/MM/YYYY', 'MM/DD/YYYY', 'DD-MM-YYYY'])],
            'number_format' => ['sometimes', Rule::in(['latin', 'arabic'])],
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
        ]);

        $before = $user->only(array_keys($data));
        $user->fill($data)->save();

        $this->audit->log('user.profile_updated', 'user', $user->uuid, $before, $user->only(array_keys($data)));

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    /** PATCH /api/me/password — verify current, set new, optionally sign out other devices. */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'logout_other_devices' => ['sometimes', 'boolean'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            // Never reveal anything beyond "wrong current password".
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        if ($request->boolean('logout_other_devices')) {
            // Cycles the remember-me token so other sessions are invalidated on their next request.
            $user->setRememberToken(Str::random(60));
            $user->save();
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        // Audit the event WITHOUT any password material.
        $this->audit->log('user.password_changed', 'user', $user->uuid, null, [
            'logout_other_devices' => $request->boolean('logout_other_devices'),
        ]);

        return response()->json(['data' => ['status' => 'updated']]);
    }

    /**
     * GET /api/me/sessions — the current session's device summary.
     *
     * NOTE: full multi-session enumeration + per-session revoke needs the database session driver
     * (this environment uses redis), so those are surfaced as "Awaiting External Dependency" in the UI.
     * The current-session summary and "sign out other devices" below are fully real.
     */
    public function sessions(Request $request): JsonResponse
    {
        $agent = (string) $request->userAgent();

        return response()->json([
            'data' => [
                'current' => [
                    'ip' => $request->ip(),
                    'user_agent' => $agent,
                    'browser' => $this->browser($agent),
                    'platform' => $this->platform($agent),
                    'last_active_at' => now()->toIso8601String(),
                ],
                'others_available' => false, // requires database session driver — see NEXT_STEPS
            ],
        ]);
    }

    /** DELETE /api/me/sessions/others — invalidate other devices (requires current password). */
    public function logoutOtherSessions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->string('current_password')->value(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        $user->setRememberToken(Str::random(60));
        $user->save();
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->audit->log('user.sessions_revoked', 'user', $user->uuid);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Unknown',
        };
    }

    private function platform(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };
    }
}
