{{--
  The entry every mailable names (`view: 'mail.layout'`) — EMAIL-CLIENT-COMPAT-001.

  The shell itself is `mail.shell`. This wrapper renders it and passes the finished HTML through `EmailSafeHtml`: CSS from
  `<style>` inlined, margins turned into spacer rows, an explicit background, colour, direction and alignment on every cell, px
  line-heights. One place, so every render path — a real send, a test send, the gallery, a preview, a test — gets the same,
  email-client-safe bytes. Nothing in the message depends on `<style>`; the Gmail app on a non-Google account drops it whole.
--}}
{!! \App\Domains\Notifications\Support\EmailSafeHtml::view('mail.shell', $__data) !!}