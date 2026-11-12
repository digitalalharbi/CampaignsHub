{{--
  One thing to say, and one way to act on it — MAIL-006, in the approved digest grammar since DIGESTS-CADENCE-BUDGETS-001.

  Every operational message renders through this: an alert, a report that is ready, an invoice, an approval, a new
  conversation. The card is the same one every alert uses (`mail.partials.alert-card`): a status chip and a coloured edge,
  the message drawn as its stages, one short line, the steps in order, and ONE button below it. The detail's other lines,
  when it has them, follow as a short list — never a paragraph.

  Tables, inline styles, no external images, explicit backgrounds. See `layout.blade.php`.
--}}
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">

    <div style="font-size:14px; color:#5b6b68; margin:0 0 12px;">{{ $greeting }}</div>

    @include('mail.partials.alert-card', ['c' => $card, 'big' => true])

    @if ($more)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="more" style="margin:-2px 0 14px;">
            @foreach ($more as $line)
                <tr>
                    <td width="14" valign="top" style="padding:2px 0; font-size:12px; color:#8b9a97;">•</td>
                    <td style="padding:2px 0; font-size:13px; color:#3d4742; line-height:1.6;">{{ $line }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- One button, and it says what it does. «اضغط هنا» tells a reader nothing about where they land. --}}
    @include('mail.partials.button', ['href' => $actionUrl, 'label' => $actionLabel])

    {{-- Why this arrived. An unexplained email is one a person marks as spam rather than unsubscribes from. --}}
    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:20px;">{{ $t['why'] }}</div>
</div>
