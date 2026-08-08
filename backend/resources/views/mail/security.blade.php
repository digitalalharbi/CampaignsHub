{{--
  Something happened to this account — MAIL-009.

  A new sign-in, a changed password, a new device. Its shape is a claim plus the FACTS behind the
  claim, because a security message the reader cannot check is one they either ignore or panic at,
  and both are the wrong response.

  ## The facts table is the message

  «تم تسجيل دخول جديد» on its own tells somebody nothing they can act on. The time, the device and
  the approximate place are what let them say «that was me, on my phone, this morning» in two seconds
  — or notice that it was not. So the table comes before the button, and every row that is unknown is
  omitted rather than filled with «غير معروف»: a column of «unknown» reads as a broken feature and
  teaches the reader to skip the table entirely.

  ## What is not here

  No IP-derived street, no map, no device fingerprint, no session token. An approximate city is enough
  to recognise yourself; anything finer is data that has to be protected in an unencrypted mailbox
  without helping the reader decide anything.
--}}
<div style="font-family:{!! $font !!}; color:#0f172a;">

    <div style="font-size:15px; color:#5b6b68; margin:0 0 16px;">{{ $greeting }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:{{ $toneBg }}; border-radius:12px; margin-bottom:18px;">
        <tr>
            <td style="padding:16px 18px; border-{{ $startSide }}:4px solid {{ $toneInk }}; border-radius:12px;">
                <div style="font-size:18px; font-weight:800; color:#0f172a; line-height:1.3;">{{ $title }}</div>
                <div style="font-size:14px; color:#334155; line-height:1.8; padding-top:8px;">{{ $detail }}</div>
            </td>
        </tr>
    </table>

    @if ($facts !== [])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="border:1px solid #e2e8e6; border-radius:12px; margin-bottom:20px;">
            @foreach ($facts as $fact)
                <tr>
                    <td style="padding:11px 16px; {{ ! $loop->last ? 'border-bottom:1px solid #eef2f1;' : '' }}">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size:13px; color:#5b6b68; line-height:1.8;">{{ $fact['label'] }}</td>
                                {{--
                                  The face and the direction follow the CONTENT — see `facts()`.

                                  A figure (a timestamp, an IP) is set in the tabular face and forced
                                  `ltr`: left to inherit, an address like 192.168.1.10 is reordered by
                                  the bidi algorithm and the reader is shown a number that is not the
                                  one we recorded.

                                  A place name is not a figure. The tabular stack carries no Arabic,
                                  so «الرياض» set in it falls back per glyph and loses its joining —
                                  the word stops being a word. Body face, `dir="auto"`, and the
                                  browser reads the direction off the first strong character.
                                --}}
                                <td align="{{ $endSide }}"
                                    dir="{{ $fact['numeric'] ? 'ltr' : 'auto' }}"
                                    style="font-family:{!! $fact['numeric'] ? $numericFont : $font !!}; font-size:13px; color:#0f172a; line-height:1.8;">{{ $fact['value'] }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    {{--
      The action is «this was not me», never «view your account».

      A person who recognises the sign-in needs to do nothing, and a button inviting them to log in
      teaches exactly the habit that phishing depends on. The only button worth having is the one for
      the reader who does NOT recognise it.
    --}}
    <div>
        <a href="{{ $actionUrl }}"
           style="display:inline-block; background-color:#0f766e; color:#ffffff; font-size:14px; font-weight:700;
                  text-decoration:none; padding:11px 20px; border-radius:8px;">{{ $actionLabel }}</a>
    </div>

    <div style="font-size:13px; color:#5b6b68; line-height:1.8; margin-top:16px;">{{ $reassurance }}</div>
</div>
