{{--
  PLATFORM-INBOX-ATTENTION-001 — an administrator's reply to a website visitor.

  The reply is the whole message. Each paragraph the administrator wrote is one block, each line inside it is
  kept with `<br>`, and every value is escaped: the body is plain text somebody typed, never HTML.
--}}
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">

    <div style="font-size:15px; color:#5b6b68; margin:0 0 16px;">{{ $greeting }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:{{ $toneBg }}; border-radius:12px; margin-bottom:18px;">
        <tr>
            <td style="padding:16px 18px; border-{{ $startSide }}:4px solid {{ $toneInk }}; border-radius:12px;">
                <div style="font-size:18px; font-weight:800; color:#0b1a16; line-height:1.3;">{{ $title }}</div>
                <div dir="auto" style="font-size:13px; color:#5b6b68; line-height:1.7; padding-top:6px;">{{ $about }}</div>
                @if ($reference)
                    <div style="font-size:12px; color:#5b6b68; line-height:1.7; padding-top:2px;">{{ $referenceLabel }}: <span dir="ltr" style="font-family:{!! $numericFont !!};">{{ $reference }}</span></div>
                @endif
            </td>
        </tr>
    </table>

    @foreach ($paragraphs as $lines)
        <div dir="auto" data-block="reply" style="font-size:14px; color:#0b1a16; line-height:1.9; margin:0 0 12px;">
            @foreach ($lines as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach
        </div>
    @endforeach

    <div style="font-size:14px; color:#3d4742; line-height:1.8; margin-top:8px;">{{ $signature }}</div>

    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:20px;">{{ $replyHint }}</div>
    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:6px;">{{ $t['why'] }}</div>
</div>
