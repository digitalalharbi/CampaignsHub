{{-- SPEND-SUMMARY blocks, shared: one project/limit card — name, state chip, the bar of the limit used, the trio «الحد · الإنفاق · المتبقي», the runway line. Needs $p (state, state_label, name, spend_text, cap_text, consumed_text, remaining_text, bar, days_text), $dir, $startSide, $endSide; optional $calm (external: an over-limit card is drawn in the calm amber, never the alarm ink). --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($ar = $dir === 'rtl')
        @php($tone = ['over' => (($calm ?? false) ? 'watch' : 'bad'), 'near' => 'watch', 'ok' => 'ok', 'unconverted' => 'none', 'none' => 'none'][$p['state']])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spend-project" data-state="{{ $p['state'] }}" style="border:1px solid #e4e0d6; border-{{ $startSide }}:4px solid {{ $S::INK[$tone] }}; border-radius:12px; margin-bottom:8px; background-color:{{ $S::SOFT[$tone] }};">
            <tr><td style="padding:10px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="font-size:14px; font-weight:800; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($p['name'], 32, '…') }}</td>
                    <td style="text-align:{{ $endSide }}; white-space:nowrap;"><span style="display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:800; background-color:{{ $S::CELL[$tone] }}; color:{{ $S::INK[$tone] }};">{{ $p['state_label'] }}</span></td>
                </tr></table>
                <div style="font-size:12px; color:#3d4742; padding-top:4px;">{{ $ar ? 'الإنفاق في الفترة' : 'Spend in the period' }}: <span dir="ltr">{{ $p['spend_text'] }}</span></div>
                @if ($p['state'] === 'none')
                    <div style="font-size:12px; color:#5b6b68; padding-top:4px;">{{ $ar ? 'ما فيه حد إنفاق مضبوط لهذا المشروع' : 'No spend limit is set for this project' }}</div>
                @elseif ($p['state'] === 'unconverted')
                    <div style="font-size:12px; color:{{ $S::INK['watch'] }}; padding-top:4px;">{{ $ar ? 'الحد' : 'Limit' }} <span dir="ltr">{{ $p['cap_text'] }}</span> · {{ $ar ? 'الإنفاق غير محوّل لعملة الحد، فما نحسب المتبقي' : 'Spend is not converted to the limit’s currency, so the remainder is not computed' }}</div>
                @else
                    {{-- The bar: used share of the limit, capped at 100. --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="ltr" style="margin:8px 0 4px; background-color:#e6e2d6; border-radius:4px;"><tr>
                        <td width="{{ $p['bar'] }}%" height="8" bgcolor="{{ $S::INK[$tone] }}" style="background-color:{{ $S::INK[$tone] }}; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td>
                        @if ($p['bar'] < 100)<td height="8" style="font-size:1px; line-height:8px;">&nbsp;</td>@endif
                    </tr></table>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>
                        <td valign="top" style="font-size:11px; color:#5b6b68;">{{ $ar ? 'الحد' : 'Limit' }}<div style="font-size:13px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $p['cap_text'] }}</div></td>
                        <td valign="top" style="font-size:11px; color:#5b6b68;">{{ $ar ? 'الإنفاق' : 'Spent' }}<div style="font-size:13px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $p['consumed_text'] }}</div></td>
                        <td valign="top" style="font-size:11px; color:#5b6b68;">{{ $ar ? 'المتبقي' : 'Remaining' }}<div data-remaining="1" style="font-size:13px; font-weight:800; color:{{ $S::INK[$tone] }};" dir="ltr">{{ $p['remaining_text'] }}</div></td>
                    </tr></table>
                    @if ($p['days_text'] !== '')<div data-days-left="1" style="font-size:12px; font-weight:700; color:#3d4742; padding-top:4px;">{{ $p['days_text'] }}</div>@endif
                @endif
            </td></tr>
        </table>
