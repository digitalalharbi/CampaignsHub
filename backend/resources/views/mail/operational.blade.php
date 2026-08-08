{{--
  One thing to say, and one way to act on it — MAIL-006.

  Every operational message renders through this: an alert, a report that is ready, an invoice, an
  approval, a new conversation. They share a shape because they share a purpose — tell somebody what
  happened, what it means for them, and what to do next — and one template is one place to fix a
  rendering bug rather than eight.

  The same email rules as the digest apply and for the same reasons: tables, inline styles, no
  external images, explicit backgrounds so a dark-mode client cannot invert a card into
  black-on-black. See `layout.blade.php`.
--}}
@php($tone = ['critical' => '#b91c1c', 'warning' => '#b45309', 'positive' => '#0f766e', 'info' => '#5b6b68'])
@php($toneBg = ['critical' => '#fef2f2', 'warning' => '#fffbeb', 'positive' => '#ecfdf5', 'info' => '#f8fafa'])
@php($colour = $tone[$severity] ?? $tone['info'])
@php($background = $toneBg[$severity] ?? $toneBg['info'])

<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#0f172a;">

    <div style="font-size:15px; color:#5b6b68; margin:0 0 16px;">{{ $greeting }}</div>

    {{--
      The headline carries its own severity as a colour and a rule, never as an icon.

      An emoji is a font the client may not have, and a coloured left border is the one visual
      signal that renders identically in Gmail, Outlook and Apple Mail.
    --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:{{ $background }}; border-radius:12px; margin-bottom:18px;">
        <tr>
            <td style="padding:16px 18px; border-{{ $startSide }}:4px solid {{ $colour }}; border-radius:12px;">
                @if ($context !== '')
                    <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $context }}</div>
                @endif
                <div style="font-size:18px; font-weight:800; color:#0f172a; padding-top:4px; line-height:1.5;">{{ $title }}</div>
                <div style="font-size:14px; color:#334155; line-height:1.8; padding-top:8px;">{{ $detail }}</div>
            </td>
        </tr>
    </table>

    {{-- One button, and it says what it does. «اضغط هنا» tells a reader nothing about where they land. --}}
    <div>
        <a href="{{ $actionUrl }}"
           style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:14px; font-weight:700;
                  text-decoration:none; padding:11px 20px; border-radius:8px;">{{ $actionLabel }}</a>
    </div>

    {{-- Why this arrived. An unexplained email is one a person marks as spam rather than unsubscribes from. --}}
    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:20px;">{{ $t['why'] }}</div>
</div>
