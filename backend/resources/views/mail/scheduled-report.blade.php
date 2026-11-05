{{--
  A scheduled report, as a decision report — EMAIL-GAPS.

  Equal columns, the label over its figure, and a figure that does not exist has no cell. Movement is
  printed when it is an improvement: the report's job is results and follow-up, and the weakest content
  appears as an opportunity. Nothing here is prose from the snapshot, because a sentence can carry spend.
--}}
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    <div style="font-size:15px; color:#5b6b68; margin:0 0 12px;">{{ $greeting }}</div>
    <div dir="auto" style="font-size:20px; font-weight:800; line-height:1.4; color:#0b1a16;">{{ $title }}</div>
    <div style="font-size:13px; color:#5b6b68; line-height:1.8; padding:4px 0 18px;">
        <span dir="auto">{{ $context }}</span> · <span dir="ltr" style="font-family:{!! $numericFont !!};">{{ $period }}</span>
    </div>

    @if ($cards !== [])
        <div style="font-size:13px; font-weight:700; color:#3d4742; padding-bottom:8px;">{{ $w['results'] }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed; margin-bottom:18px; border:1px solid #e4e0d6; border-radius:12px;">
            <tr>
                @foreach ($cards as $card)
                    <td class="ch-kpi" align="center" valign="top" width="{{ (int) floor(100 / count($cards)) }}%" style="padding:14px 6px; text-align:center;">
                        <div style="font-size:12px; color:#5b6b68; line-height:1.5;">{{ $card['label'] }}</div>
                        <div dir="ltr" style="font-family:{!! $numericFont !!}; font-size:20px; font-weight:800; color:#0b1a16; line-height:1.5;">{{ $card['value'] }}@if ($card['unit'] !== null && $card['unit'] !== '') <span style="font-size:11px; font-weight:600; color:#5b6b68;">{{ $card['unit'] }}</span>@endif</div>
                        @if ($card['up'] !== null)
                            <div dir="ltr" style="font-family:{!! $numericFont !!}; font-size:12px; color:{{ $positive['ink'] }}; line-height:1.5;">{{ $card['up'] }}</div>
                        @endif
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach (array_filter(['best' => $bestContent, 'improve' => $improveContent]) as $key => $name)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px; background-color:#faf8f3; border-radius:10px;">
            <tr>
                <td style="padding:10px 14px; font-size:12px; color:#5b6b68; line-height:1.6;">
                    {{ $w[$key] }}
                    <div dir="auto" style="font-size:14px; font-weight:700; color:#0b1a16; line-height:1.6;">{{ $name }}</div>
                </td>
            </tr>
        </table>
    @endforeach

    <div style="height:10px; line-height:10px; font-size:0;">&nbsp;</div>
    @include('mail.partials.button', ['href' => $actionUrl, 'label' => $actionLabel])
    <div style="font-size:12px; color:#5b6b68; line-height:1.7; margin-top:16px;">{{ $t['why'] }}</div>
</div>
