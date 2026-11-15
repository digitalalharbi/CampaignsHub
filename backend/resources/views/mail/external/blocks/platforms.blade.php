@php($pl = $m['platforms'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="platforms" style="border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
    <tr><td style="padding:10px 12px;">
        <div style="font-size:11px; font-weight:800; color:#5b6b68;">{{ $pl['title'] }}</div>
        @if (isset($m['charts']['donut']))
            <table role="presentation" align="center" width="180" cellpadding="0" cellspacing="0" border="0" style="margin:4px auto 6px;"><tr><td>@include('mail.blocks.img', ['key' => 'donut', 'alt' => $pl['alt'], 'w' => 180])</td></tr></table>
        @else
            <div style="font-size:20px; font-weight:800; color:#0b1a16; padding:2px 0 4px;" dir="ltr">{{ $pl['total'] }}</div>
        @endif
        @include('mail.blocks.rows', ['rows' => array_map(static fn (array $r): array => ['provider' => $r['provider'], 'label' => $r['label'], 'value' => $r['short'], 'bar' => $r['bar'], 'delta' => $r['delta'], 'rank' => null], $pl['rows'])])
    </td></tr>
</table>
