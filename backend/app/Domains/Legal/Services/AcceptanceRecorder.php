<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use App\Domains\Legal\Models\PolicyAcceptance;
use App\Domains\Legal\PolicyRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * LEGAL-003 — recording an acceptance, and refusing to record one that is not real.
 *
 * ## Why the version is not taken from the client
 *
 * A browser posting `{"terms": "0.1"}` would otherwise create a record saying somebody accepted a
 * version that never existed — or, more usefully to an attacker, an old one whose wording suited
 * them. The version comes from {@see PolicyRegistry}, which is code. The client says only WHICH
 * documents it is accepting; the server decides what those documents currently say.
 *
 * ## Why an incomplete acceptance is a validation failure
 *
 * Registration cannot proceed without the binding documents, and silently recording three of four
 * would leave an account that looks compliant and is not. Refusing is the honest outcome, and the
 * message names what is missing rather than saying «invalid».
 */
final class AcceptanceRecorder
{
    /**
     * Record acceptance of every binding document, or refuse.
     *
     * @param  list<string>  $accepted  the slugs the client says were ticked
     *
     * @throws ValidationException when a binding document was not accepted
     */
    public function recordBinding(
        Request $request,
        string $context,
        ?User $user = null,
        ?string $email = null,
        array $accepted = [],
    ): void {
        $required = PolicyRegistry::binding();
        $missing = array_values(array_diff($required, $accepted));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'accepted_policies' => __('api.policies_not_accepted', ['documents' => implode(', ', $missing)]),
            ]);
        }

        foreach ($required as $slug) {
            $document = PolicyRegistry::get($slug);

            PolicyAcceptance::create([
                'user_id' => $user?->id,
                // Kept even when a user id exists: it is what ties the record to the person if the
                // account is later deleted, and the acceptance itself must outlive the account.
                'email' => $email ?? $user?->email,
                'document' => $slug,
                // From the registry, never from the request — see the class note.
                'version' => $document['version'],
                'effective' => $document['effective'],
                'context' => $context,
                'accepted_at' => now(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'locale' => str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'en') ? 'en' : 'ar',
            ]);
        }
    }

    /**
     * Attach acceptances taken before the account existed.
     *
     * Registration ticks the boxes and then creates the user, so the rows are written with an email
     * and no id. This joins them up rather than leaving the evidence detached from the account it
     * belongs to.
     */
    public function linkToUser(User $user): void
    {
        PolicyAcceptance::query()
            ->whereNull('user_id')
            ->where('email', $user->email)
            ->update(['user_id' => $user->id]);
    }

    /**
     * Which binding documents this user has NOT accepted at their current versions.
     *
     * The question a re-acceptance prompt is built on: a new terms version means everyone's record
     * points at the old one, and this names exactly who needs asking again.
     *
     * @return list<string>
     */
    public function outstandingFor(User $user): array
    {
        $outstanding = [];

        foreach (PolicyRegistry::binding() as $slug) {
            $current = PolicyRegistry::versionOf($slug);

            $has = PolicyAcceptance::query()
                ->where('user_id', $user->id)
                ->where('document', $slug)
                ->where('version', $current)
                ->exists();

            if (! $has) {
                $outstanding[] = $slug;
            }
        }

        return $outstanding;
    }
}
