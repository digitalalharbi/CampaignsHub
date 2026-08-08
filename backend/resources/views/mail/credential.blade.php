{{--
  A code to type, or a link to open — MAIL-009.

  ## Why this is not `operational.blade.php`

  An operational message says «here is what happened, here is where to look». This one hands the
  reader the thing that grants access to their account, and that difference changes what the template
  has to do rather than only how it looks:

  - The code or the button is the SUBJECT, not the footer. It is placed above the explanation,
    because somebody who requested it is not reading — they are copying six digits and going back to
    the tab they came from.
  - It states how long it lasts. «This has expired» with no idea how long it ever lived is the most
    common support ticket any product with a code receives.
  - It says what to do if you did NOT ask for it, which is the only line that matters in the case
    where this email is evidence of somebody else trying to get in.
  - It carries no unsubscribe. See `layout.blade.php`.

  ## What is deliberately absent

  No name, no workspace figures, no «you have 3 unread messages», nothing about the account beyond
  what the recipient must know to act. A verification message is the one most likely to be read on a
  shared screen or forwarded to somebody trying to help — every extra fact in it is a fact disclosed.

  The code never appears in a URL. It is text in the body, so it cannot end up in a referrer header,
  a proxy log, or a browser history entry.
--}}
<div style="font-family:{!! $font !!}; color:#0f172a;">

    <div style="font-size:18px; font-weight:800; color:#0f172a; line-height:1.3;">{{ $title }}</div>
    <div style="font-size:14px; color:#334155; line-height:1.8; padding-top:8px;">{{ $intro }}</div>

    @if ($code !== null)
        {{--
          The code, set as large as the scale goes, spaced, and in the tabular face.

          `dir="ltr"` because a right-to-left paragraph will reorder a run of digits — and this is
          the one string in the product where a reordered character is a failed sign-in rather than
          an awkward line. `letter-spacing` so a person reading it aloud, or copying it in pairs,
          does not lose their place.
        --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:#f8fafa; border:1px solid #e2e8e6; border-radius:12px; margin:18px 0;">
            <tr>
                <td align="center" style="padding:20px 16px;">
                    <div dir="ltr" style="font-family:{!! $numericFont !!}; font-size:24px; font-weight:800; color:#0f172a; letter-spacing:6px; line-height:1.3;">{{ $code }}</div>
                    <div style="font-size:12px; color:#5b6b68; padding-top:10px; line-height:1.7;">{{ $validity }}</div>
                </td>
            </tr>
        </table>

        {{-- The one warning worth its own panel: a code is only a secret while it stays one. --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="background-color:#fffbeb; border-radius:12px; margin-bottom:18px;">
            <tr>
                <td style="padding:14px 16px; border-{{ $startSide }}:4px solid #b45309; border-radius:12px;">
                    <div style="font-size:13px; color:#334155; line-height:1.8;">{{ $t['never_share'] }}</div>
                </td>
            </tr>
        </table>
    @else
        <div style="margin:20px 0;">
            <a href="{{ $actionUrl }}"
               style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:14px; font-weight:700;
                      text-decoration:none; padding:11px 20px; border-radius:8px;">{{ $actionLabel }}</a>
        </div>

        <div style="font-size:12px; color:#5b6b68; line-height:1.7; margin-bottom:18px;">{{ $validity }}</div>

        {{--
          The address in full, under the button.

          Every client that blocks or rewrites links breaks the button, and a reader who cannot see
          where a link goes is a reader being trained to click things they cannot check. `word-break`
          because a 90-character token in a 600px table will otherwise push the whole layout wider
          than the card.
        --}}
        <div style="font-size:12px; color:#8b9a97; line-height:1.7; word-break:break-all;">
            {{ $t['or_paste'] }}<br />
            <span dir="ltr">{{ $actionUrl }}</span>
        </div>
    @endif

    {{--
      «If this was not you.»

      Not a footnote and not optional. For most readers it is noise; for the one whose password is
      being reset by somebody else, it is the only sentence in the message that matters, and it needs
      to say what to DO rather than «contact support».
    --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-top:1px solid #eef2f1; margin-top:22px;">
        <tr>
            <td style="padding-top:14px;">
                <div style="font-size:13px; font-weight:700; color:#0f172a; line-height:1.3;">{{ $t['not_you_title'] }}</div>
                <div style="font-size:13px; color:#5b6b68; line-height:1.8; padding-top:6px;">{{ $notYou }}</div>
            </td>
        </tr>
    </table>
</div>
