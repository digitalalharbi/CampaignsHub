{{--
  «ملخص الإنفاق» — SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001.

  The period's spend, then each project's spend limit (the Budgets section's): what is left, as an amount and a share, a bar,
  and the days of spend left at the period's pace when that can be measured. The same mail serves the workspace (one button
  into the budgets) and a recipient outside the platform (`$external`: no button, no link, no campaign — the project's budget
  follow-up only). Every figure is `ProjectSpendSummary`'s; a missing one is said, never drawn as 0.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($ar = $dir === 'rtl')
@php($tot = $s['total'])
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head', ['title' => $title, 'sub' => $periodLabel])
    @if ($recipientLine !== '')
        <div style="font-size:12px; color:#5b6b68; margin:-6px 0 12px;">{{ $recipientLine }}</div>
    @endif

    {{-- The overall line first: the period's spend and what is left of every limit, as two tiles side by side. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spend-total" style="margin-bottom:12px; table-layout:fixed;">
        <tr>
            <td width="50%" valign="top" style="padding-{{ $endSide }}:4px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#faf8f3" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; padding:10px 12px;">
                    <div style="font-size:11px; font-weight:700; color:#5b6b68;">{{ $ar ? 'الإنفاق في الفترة' : 'Spend in the period' }}</div>
                    <div data-tile="spend" style="font-size:22px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $fmt['total_spend'] }}</div>
                    @if ($tot['spend_left_out'] > 0)<div style="font-size:11px; color:{{ $S::INK['watch'] }};">{{ $ar ? 'بعض الإنفاق غير محوّل، خارج المجموع' : 'Some spend is not converted, outside the total' }}</div>@endif
                </td></tr></table>
            </td>
            <td width="50%" valign="top" style="padding-{{ $startSide }}:4px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#faf8f3" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; padding:10px 12px;">
                    <div style="font-size:11px; font-weight:700; color:#5b6b68;">{{ $ar ? 'المتبقي من حدود الإنفاق' : 'Left of the spend limits' }}</div>
                    <div data-tile="remaining" style="font-size:22px; font-weight:800; color:#0b1a16;" dir="ltr">{{ $fmt['total_remaining'] }}</div>
                    @if (($tot['cap_left_out'] ?? 0) > 0)<div style="font-size:11px; color:{{ $S::INK['watch'] }};">{{ $ar ? 'بعض الحدود غير محوّلة، خارج المجموع' : 'Some limits are not converted, outside the total' }}</div>@endif
                    @if ($tot['near'] > 0)<div style="font-size:11px; font-weight:700; color:{{ $S::INK['bad'] }};">{{ $ar ? $tot['near'].' قرّبت تخلص' : $tot['near'].' close to the limit' }}</div>@endif
                </td></tr></table>
            </td>
        </tr>
    </table>

    {{-- One card per project: name, state chip, the bar of the limit used, remaining amount and share, days left. --}}
    @foreach ($s['projects'] as $p)
        @php($tone = ['over' => 'bad', 'near' => 'watch', 'ok' => 'ok', 'unconverted' => 'none', 'none' => 'none'][$p['state']])
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
    @endforeach

    @if ($s['projects'] === [])
        <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $ar ? 'ما فيه مشاريع في هذا الملخص.' : 'No projects in this summary.' }}</div>
    @endif

    @if (! $external)
        @include('mail.partials.button', ['href' => $budgetsUrl, 'label' => $ar ? 'افتح الميزانيات' : 'Open budgets'])
    @else
        <div style="font-size:12px; color:#5b6b68; line-height:1.7; margin-top:14px;">{{ $ar ? 'وصلتك هذه الرسالة لمتابعة ميزانية المشاريع المذكورة وتجديدها مع العميل.' : 'You receive this to follow the budgets of the projects above and renew them with the client.' }}</div>
    @endif
    {{-- BUDGET-DISCLAIMER-001 — the one financial disclaimer, wherever money figures appear (external recipients included). --}}
    <div data-block="financial-disclaimer" style="font-size:11px; color:#5b6b68; line-height:1.7; margin-top:12px; border-top:1px solid #e4e0d6; padding-top:8px;">{{ \App\Domains\Funding\Support\FundingDisclaimer::text($ar ? 'ar' : 'en') }}</div>
</div>
