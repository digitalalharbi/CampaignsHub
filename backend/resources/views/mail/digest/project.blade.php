{{--
  The project summary «ملخص المشروع» — DIGESTS-VISUAL-V2-001, reordered by DIGESTS-YESTERDAY-BY-OBJECTIVE-001: the verdict,
  the project's total, the breakdown per objective × platform, the budget (the decision list was removed by the Owner on 2026-11-21), «ملخص الربط» last.

  DIGEST-FIGURES-ONLY-001 (Owner 2026-11-21): figures only — no verdict chip, platform strip, best/weak or content notes.
  The project's total, then each objective and its platforms, then the budget. Every figure is
  `OperationalDigestModel`'s string; no `?? 0` exists in this file, so «لا بيانات» can never turn into a zero here.
--}}
@php($ar = $dir === 'rtl')
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($weekly = $kind === 'weekly')
@php($monthly = $kind === 'monthly')
@php($st = $m['status'])
@php($lean = ($external ?? false) || ($readOnly ?? false))
@php($h = $m['headline'])
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head', ['title' => \App\Domains\Notifications\Support\DigestSchedule::title('project', $kind, $ar), 'sub' => $periodLabel])
@if (! empty($privacyLine ?? ''))
    <div data-block="privacy-line" style="font-size:12px; color:#5b6b68; margin:-6px 0 10px;">{{ $privacyLine }}</div>
@endif

    {{-- Verdict + the ONE figure the project is judged on. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="project-head" data-state="{{ $m['state'] }}" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:8px;">
        <tr>
            <td style="padding:12px 14px; vertical-align:middle;">
                {{-- DIGEST-FIGURES-ONLY-001 — Owner 2026-11-21: no state chip («! مراقبة») — figures only. --}}
                <div style="font-size:18px; font-weight:800; color:#0b1a16;">{{ $m['name'] }}</div>
                <div style="font-size:12px; color:#5b6b68; padding-top:2px;">{{ $m['mixed'] ? ($ar ? 'كل الأهداف' : 'All objectives') : $m['objective_label'] }} · {{ $t['kind_note'] }}</div>
                @if ($m['state'] === 'none')<div data-block="no-data-reason" style="font-size:12px; color:#3d4742; padding-top:4px;">{{ $m['status']['reason'] }}</div>@endif
            </td>
            {{-- DIGEST-NO-EMPTY-001 — no box when the headline figure is missing; «لا بيانات» is never written. --}}
            @unless ($h['missing'])
            <td width="116" style="padding:12px 10px; vertical-align:middle; text-align:center; background-color:#e6e2d6; border-radius:{{ $startSide === 'right' ? '12px 0 0 12px' : '0 12px 12px 0' }};">
                <div class="ch-kpi-lead" style="font-size:24px; line-height:1.15; font-weight:800; color:{{ $h['missing'] ? '#8b9a97' : '#0b1a16' }};" dir="ltr">{{ $h['value'] }}</div>
                <div style="font-size:11px; color:#5b6b68;">{{ $h['label'] }}</div>
                @if ($h['change'])<div style="font-size:12px; font-weight:800; color:{{ $h['colour'] }};" dir="ltr">{{ $h['change'] }}</div>@endif
            </td>
            @endunless
        </tr>
    </table>

    {{-- DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — the project's TOTAL first, across all its accounts and objectives. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin:6px 0 10px;">
        <tr><td style="padding:2px 12px 10px;">
            @include('mail.digest._figures', ['figures' => $m['kpis'], 'per' => 3, 'big' => true])
            @if ($monthly && $m['weeks'])
                {{-- The month as its weeks (1–7, 8–14, …): four or five bars, the last one strong. --}}
                <div data-block="weeks">@include('mail.digest._spark', ['bars' => $m['weeks'], 'colour' => '#0d8a6f', 'height' => 40, 'labels' => true])</div>
            @else
                @include('mail.digest._spark', ['bars' => $m['spark'], 'colour' => '#0d8a6f', 'height' => $weekly ? 40 : 22, 'labels' => $weekly])
            @endif
        </td></tr>
    </table>

    {{-- DIGEST-FIGURES-ONLY-001 — the monthly «الأفضل هذا الشهر / يحتاج مراجعة» cards are removed: notes, not figures. --}}

    {{-- OBJECTIVE-PATHS-001 — one total spend; under it each objective's share and its own result. ROAS on sales only. --}}
    @if ($m['objective_paths'] ?? null)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="objective-paths" style="border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
            <tr><td style="padding:10px 12px;">
                <div style="font-size:11px; color:#8b9a97;">{{ $ar ? 'إجمالي الإنفاق' : 'Total spend' }}</div>
                <div style="font-size:18px; font-weight:800; color:#0b1a16;" dir="ltr" data-total>{{ $m['objective_paths']['total'] }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px;">
                    @foreach ($m['objective_paths']['paths'] as $op)
                        <tr data-path="{{ $op['family'] }}">
                            <td width="30%" style="padding:3px 0; font-size:12px; font-weight:800; color:#0b1a16;">{{ $op['label'] }} <span style="color:#8b9a97; font-weight:700;" dir="ltr">{{ $op['share'] }}%</span></td>
                            <td width="22%" style="padding:3px 4px; font-size:12px;" dir="ltr">{{ $op['spend'] }}</td>
                            <td style="padding:3px 4px; font-size:12px;">{{ $op['result'] }}</td>
                            <td width="16%" style="padding:3px 0; font-size:12px; text-align:{{ $endSide }};" dir="ltr">{{ $op['roas'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        </table>
    @endif
    {{-- Then the breakdown PER OBJECTIVE, each with its platforms: its own tiles, its platforms with the objective's own figure, its funnel. Unlike objectives are never averaged. --}}
    @foreach ($m['groups'] as $g)
        @include('mail.digest._group', ['g' => $g])
    @endforeach
    @if (! $m['groups'])
        @forelse ($m['platforms'] as $pf)
            <div style="font-size:12px; font-weight:800; margin:4px 0;">{{ $pf['name'] }}</div>
            @include('mail.digest._figures', ['figures' => $pf['figures'], 'per' => 3, 'big' => false])
        @empty
            <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $t['no_platforms'] }}</div>
        @endforelse
    @endif

    {{-- DIGEST-PM-ACTIONS-001 — the old alert list stays removed; «يحتاج قرارك الآن» returns as budget actions for project
         management, never to a reader outside the platform. --}}
    @unless ($external ?? false)
        @include('mail.digest._actions', ['items' => $m['actions'] ?? [], 'ar' => $ar, 'startSide' => $startSide])
    @endunless
    {{-- DIGESTS-CADENCE-BUDGETS-001 — the month's budget, spend, remaining and pace: one bar, three numbers, one chip. --}}
    @if (! empty($m['budget']))
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:2px 0 6px;">{{ $ar ? 'ميزانية الشهر' : 'This month’s budget' }}@if ($m['budget']['band']) <span data-chip="pace" style="display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:800; background-color:{{ $S::CELL[$m['budget']['tone']] }}; color:{{ $S::INK[$m['budget']['tone']] }};">{{ $S::GLYPH[$m['budget']['tone']] }} {{ $m['budget']['band_label'] }}</span>@endif</div>
        @include('mail.digest._budget', ['b' => $m['budget']])
    @endif

    {{-- DIGEST-FIGURES-ONLY-001 — the platform glyph strip (✓ ! ✕ per platform) is removed: a verdict, not a figure. --}}

    {{-- DIGEST-FIGURES-ONLY-001 — the content best/weak cards are removed: they ranked on ROAS 0.00× and listed one ad as both. --}}

@unless ($lean)
    @if (! empty($m['follow_up']))
        @php($f = $m['follow_up'])
        <div data-block="follow-up" style="font-size:13px; color:#3d4742; line-height:1.8; margin:10px 0;">
            <span style="font-weight:700;">{{ $t['follow'] }}:</span>
            {{ $dir === 'rtl' ? 'وصل' : 'Received' }} {{ number_format((int) ($f['received'] ?? 0)) }} · {{ $dir === 'rtl' ? 'بلا تواصل' : 'not contacted' }} {{ number_format((int) ($f['not_contacted'] ?? 0)) }} · {{ $dir === 'rtl' ? 'متأخر' : 'overdue' }} {{ number_format((int) ($f['overdue'] ?? 0)) }}
        </div>
    @endif

@endunless
@unless ($lean)
    @if ($m['linking'])
        {{-- DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — «ملخص الربط»: linking health, apart from performance, short, at the end. --}}
        <div data-block="linking" style="border-top:1px solid #e4e0d6; margin-top:12px; padding-top:8px;">
            <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:0 0 4px;">{{ $t['linking'] }}</div>
            @foreach ($m['linking'] as $li)
                {{-- ALERT-VISUAL-EVERY-CARD-001 — each link drawn: the ring in its state, from the last update to today. --}}
                @include('mail.partials.visual', ['viz' => null, 'state' => $li['state'] === 'failed' ? 'bad' : 'watch', 'size' => 22,
                    'timeline' => ['from' => $li['since'] !== '' ? ($ar ? 'آخر تحديث ' : 'Last refreshed ').$li['since'] : '', 'to' => $ar ? 'اليوم' : 'Today']])
                <div data-state="{{ $li['state'] }}" style="font-size:12px; color:#3d4742; line-height:1.6;">@if ($li['platform'] !== ''){{ $li['platform'] }} · @endif<span style="font-weight:700; color:{{ $li['state'] === 'failed' ? $S::INK['bad'] : $S::INK['watch'] }};">{{ $li['label'] }}</span>@if ($li['since'] !== '') <span style="color:#8b9a97;">· {{ $ar ? 'آخر تحديث' : 'last refreshed' }} {{ $li['since'] }}</span>@endif · <a href="{{ $li['url'] }}" style="color:#0b7a62; text-decoration:underline;">{{ $ar ? 'الربط' : 'Connections' }}</a></div>
            @endforeach
        </div>
    @endif

    @include('mail.partials.button', ['href' => $m['links']['report'], 'label' => $t['open_report']])
@endunless

    @include('mail.digest._notes', ['ar' => $ar, 'sources' => $m['source_names'] ?? []])
</div>
