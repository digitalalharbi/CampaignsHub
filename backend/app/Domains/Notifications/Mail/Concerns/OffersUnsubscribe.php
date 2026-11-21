<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail\Concerns;

use Illuminate\Mail\Mailables\Headers;

/**
 * The way out of an optional message: a footer link and the RFC 8058 headers a mail client's own
 * «Unsubscribe» button uses. A message offered no address gets neither, which is how an account message
 * sent through the same mailable stays without one.
 */
trait OffersUnsubscribe
{
    public ?string $unsubscribeUrl = null;

    public function offerUnsubscribe(?string $url): static
    {
        $this->unsubscribeUrl = $url;

        return $this;
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl === null ? [] : [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
