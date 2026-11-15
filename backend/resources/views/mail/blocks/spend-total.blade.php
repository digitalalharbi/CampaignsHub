{{-- SPEND-SUMMARY blocks, shared (the weekly spend summary and the external finance report): the period's spend and what is left of every limit, two tiles. Needs $fmt, $tot, $dir, $startSide, $endSide, $font; optional $calm (external: no alarm ink), $leftLabel, $spendLabel. --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($ar = $dir === 'rtl')
@php($nearInk = ($calm ?? false) ? $S::INK['watch'] : $S::INK['bad'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spend-total" style="margin-bottom:12px; table-layout:fixed;">
        <tr>
            <td width="50%" valign="top" style="padding-{{ $endSide }}:4px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#faf8f3" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; padding:10px 12px;">
                    <div style="font-size:11px; font-weight:700; color:#5b6b68;">{{ $spendLabel ?? ($ar ? 'الإنفاق في الفترة' : 'Spend in the period') }}</div>
                    <div data-tile="spend" style="font-size:22px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $fmt['total_spend'] }}</div>
                    @if ($tot['spend_left_out'] > 0)<div style="font-size:11px; color:{{ $S::INK['watch'] }};">{{ $ar ? 'بعض الإنفاق غير محوّل، خارج المجموع' : 'Some spend is not converted, outside the total' }}</div>@endif
                </td></tr></table>
            </td>
            <td width="50%" valign="top" style="padding-{{ $startSide }}:4px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#faf8f3" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; padding:10px 12px;">
                    <div style="font-size:11px; font-weight:700; color:#5b6b68;">{{ $leftLabel ?? ($ar ? 'المتبقي من حدود الإنفاق' : 'Left of the spend limits') }}</div>
                    <div data-tile="remaining" style="font-size:22px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $fmt['total_remaining'] }}</div>
                    @if (($tot['cap_left_out'] ?? 0) > 0)<div style="font-size:11px; color:{{ $S::INK['watch'] }};">{{ $ar ? 'بعض الحدود غير محوّلة، خارج المجموع' : 'Some limits are not converted, outside the total' }}</div>@endif
                    @if ($tot['near'] > 0)<div style="font-size:11px; font-weight:700; color:{{ $nearInk }};">{{ $ar ? $tot['near'].' قرّبت تخلص' : $tot['near'].' close to the limit' }}</div>@endif
                </td></tr></table>
            </td>
        </tr>
    </table>

