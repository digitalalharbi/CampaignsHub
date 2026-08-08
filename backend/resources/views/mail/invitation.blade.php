{{--
  Somebody has asked you to join their workspace — MAIL-009.

  ## Why an invitation is its own shape

  It is the only message this product sends to a person who has no account, which means every
  assumption the other templates make is wrong here. The reader does not know what CampaignsHub is,
  did not ask for this, and has no way to tell a real invitation from a phishing attempt except by
  what the message itself says. So it answers, in this order:

  1. WHO invited them, by name and by workspace. An invitation from nobody in particular is
     indistinguishable from spam, and it is the first thing a suspicious reader looks for.
  2. WHAT they are being given — the role, spelled out in words rather than as a slug. «manager» is a
     database value; «مدير» with a sentence about what a manager can see is the actual answer.
  3. ONE button, and how long it lasts.
  4. What to do if they were not expecting it — which, for an invitation, is «ignore this», because
     doing nothing is genuinely safe and saying so stops somebody clicking to «check».

  No figures, no campaign data, no client names. The reader has no access yet, and an invitation that
  previews the workspace's numbers has disclosed them to an address that may have been mistyped.
--}}
<div style="font-family:{!! $font !!}; color:#0f172a;">

    <div style="font-size:18px; font-weight:800; color:#0f172a; line-height:1.3;">{{ $title }}</div>
    <div style="font-size:14px; color:#334155; line-height:1.8; padding-top:8px;">{{ $intro }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f8fafa; border:1px solid #e2e8e6; border-radius:12px; margin:18px 0;">
        <tr>
            <td style="padding:16px;">
                <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $t['workspace'] }}</div>
                <div style="font-size:16px; font-weight:800; color:#0f172a; line-height:1.3; padding-top:4px;">{{ $workspace }}</div>

                <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; padding-top:14px;">{{ $t['role'] }}</div>
                <div style="font-size:16px; font-weight:800; color:#0f172a; line-height:1.3; padding-top:4px;">{{ $roleName }}</div>
                {{--
                  What the role actually means, when the product can say.

                  Omitted rather than guessed: a description invented for an unknown role would be a
                  statement about somebody's access that nothing checks, and access is the one thing
                  in this message a reader will take literally.
                --}}
                @if ($roleNote !== '')
                    <div style="font-size:13px; color:#5b6b68; line-height:1.8; padding-top:6px;">{{ $roleNote }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div>
        <a href="{{ $actionUrl }}"
           style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:14px; font-weight:700;
                  text-decoration:none; padding:11px 20px; border-radius:8px;">{{ $actionLabel }}</a>
    </div>

    <div style="font-size:12px; color:#5b6b68; line-height:1.7; margin-top:14px;">{{ $validity }}</div>

    {{-- The full address, for every client that rewrites or strips the button. --}}
    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:10px; word-break:break-all;">
        {{ $t['or_paste'] }}<br />
        <span dir="ltr">{{ $actionUrl }}</span>
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-top:1px solid #eef2f1; margin-top:22px;">
        <tr>
            <td style="padding-top:14px;">
                <div style="font-size:13px; color:#5b6b68; line-height:1.8;">{{ $t['not_expecting'] }}</div>
            </td>
        </tr>
    </table>
</div>
