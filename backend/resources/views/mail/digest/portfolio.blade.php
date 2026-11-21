{{--
  The projects summary «ملخص المشاريع» — DIGESTS-VISUAL-V2-001, reordered by DIGESTS-YESTERDAY-BY-OBJECTIVE-001: performance
  first (spend, each objective from its own campaigns), then «ملخص كل مشروع» (DIGEST-FIGURES-ONLY-001, Owner 2026-11-21:
  figures only, no verdicts or notes), the budgets, and «ملخص الربط» last and only when a link really failed.

  Built for up to thirty projects with five to seven platforms each, read in the time it takes to drink a coffee:
  the counts first (✕ ! ✓ –), then WHO needs a decision in ONE ranked list, then the project × platform heatmap that
  shows EVERY project once, then who was not checked at all. No paragraph anywhere: a chip, a bar, a delta and a button.
  The status of every cell is `DigestStatus`; the figures are the report link's own.
--}}
@php($ar = $dir === 'rtl')
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($weekly = $kind === 'weekly')
@php($monthly = $kind === 'monthly')
@php($lean = ($external ?? false) || ($readOnly ?? false))
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    {{-- DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — «ملخص المشاريع : أمس» and the period with its dates. --}}
    @include('mail.digest._head', ['title' => \App\Domains\Notifications\Support\DigestSchedule::title('portfolio', $kind, $ar), 'sub' => $periodLabel])
    <div data-block="compared" style="font-size:11px; color:#8b9a97; margin:-8px 0 10px;">{{ $t['kind_note'] }}</div>

    {{-- 1. Performance first: the spend (the one figure every objective shares) and its change, then ONE tile row per objective from that objective's campaigns only. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="margin-bottom:8px; table-layout:fixed;">
        <tr>
            <td width="34%" valign="top">@include('mail.digest._figures', ['figures' => $m['kpis'], 'per' => 1, 'big' => true])</td>
            <td width="66%" valign="bottom" style="padding-{{ $startSide === 'right' ? 'right' : 'left' }}:8px;">@include('mail.digest._spark', ['bars' => $monthly && $m['weeks'] ? $m['weeks'] : $m['spark'], 'colour' => '#0d8a6f', 'height' => $weekly || $monthly ? 40 : 30, 'labels' => $weekly || $monthly])</td>
        </tr>
    </table>
    @foreach ($m['groups'] as $g)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="group" data-objective="{{ $g['objective'] }}" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:8px;">
            <tr><td style="padding:8px 12px 8px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td><span data-chip="objective" style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $g['label'] }}</span>
                        <span style="font-size:11px; color:#8b9a97;" dir="ltr">· {{ $g['projects'] }}</span></td>
                    <td style="text-align:{{ $endSide }}; font-size:12px; font-weight:800; white-space:nowrap;" dir="ltr">{{ $g['spend']['value'] }} <span style="font-size:11px; color:{{ $g['spend']['colour'] }};">{{ $g['spend']['change'] }}</span></td>
                </tr></table>
                @include('mail.digest._figures', ['figures' => $g['kpis'], 'per' => max(1, count($g['kpis'])), 'big' => false])
            </td></tr>
        </table>
    @endforeach

    {{--
      3. DIGEST-FIGURES-ONLY-001 — Owner 2026-11-21: «ملخص كل مشروع». Every project once, largest spend first: its spend and
      change, its share of the total as a bar, each objective's own figures on its own line, and where its money went by
      platform. No state chip, verdict, best/weakest, heatmap or count: figures only, so the same mail reads right for the
      owner, a project manager, finance or a client.
    --}}
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:10px 0 6px;">{{ $ar ? 'ملخص كل مشروع' : 'Every project' }}</div>
    @foreach ($m['cards'] as $c)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="portfolio-row" data-project="{{ $c['id'] }}" data-state="{{ $c['state'] }}" style="border:1px solid #e4e0d6; border-radius:10px; margin-bottom:6px;">
            <tr><td style="padding:8px 10px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="font-size:13px; font-weight:800; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($c['name'], 30, '…') }}</td>
                    @if ($c['spend'])
                        <td style="text-align:{{ $endSide }}; font-size:13px; font-weight:800; white-space:nowrap; color:{{ $c['spend']['missing'] ? '#8b9a97' : '#0b1a16' }};" dir="ltr">{{ $c['spend']['value'] }} <span style="font-size:11px; font-weight:700; color:{{ $c['spend']['colour'] }};">{{ $c['spend']['change'] }}</span></td>
                    @endif
                </tr></table>
                @if ($c['share'] > 0)
                    {{-- The project's share of the summary's spend: one thin bar. --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="share" style="margin:4px 0 2px; background-color:#e6e2d6; border-radius:3px;"><tr>
                        <td width="{{ max(1, $c['share']) }}%" height="5" bgcolor="#0d8a6f" style="background-color:#0d8a6f; height:5px; line-height:5px; font-size:1px; border-radius:3px;">&nbsp;</td>
                        @if ($c['share'] < 100)<td height="5" style="font-size:1px; line-height:5px;">&nbsp;</td>@endif
                    </tr></table>
                @endif
                @if ($c['no_data'])
                    <div style="font-size:12px; color:#8b9a97; padding-top:2px;">{{ $ar ? 'لا بيانات' : 'No data' }}@if ($c['reason'] !== '') — {{ $c['reason'] }}@endif</div>
                @endif
                @foreach ($c['lines'] as $line)
                    <div data-line style="font-size:12px; color:#3d4742; line-height:1.7; padding-top:2px;">
                        @if ($line['label'] !== '')<span style="display:inline-block; padding:0 7px; border-radius:999px; font-size:10px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $line['label'] }}</span>@endif
                        @foreach ($line['figures'] as $f)
                            <span style="white-space:nowrap; padding-{{ $endSide }}:8px;">{{ $f['label'] }} <b dir="ltr" style="color:{{ $f['missing'] ? '#8b9a97' : '#0b1a16' }};">{{ $f['value'] }}</b>@if ($f['change']) <span dir="ltr" style="font-size:11px; font-weight:700; color:{{ $f['colour'] }};">{{ $f['change'] }}</span>@endif</span>
                        @endforeach
                    </div>
                @endforeach
                @if ($c['platforms'])
                    <div style="font-size:11px; color:#8b9a97; padding-top:2px;">@foreach ($c['platforms'] as $pf){{ $pf['name'] }} <span dir="ltr">{{ $pf['share'] }}%</span>@if (! $loop->last) — @endif @endforeach</div>
                @endif
            </td></tr>
        </table>
    @endforeach

    {{-- 4. DIGESTS-CADENCE-BUDGETS-001 — the month's budget, spend, remaining and pace per project, and the total. --}}
    @include('mail.digest._budgets', ['bud' => $m['budgets']])

    @unless ($lean)
    {{-- 5. «ملخص الربط» — only links whose yesterday did not arrive (LINK-HEALTH-YESTERDAY-001, rules §7أ). The coverage count
         «X من Y أرقامها محدّثة» read a generic freshness state and flagged projects whose figures had arrived: removed
         (DIGEST-FIGURES-ONLY-001). Nothing here when every link delivered. --}}
    @if ($m['linking'])
    <div data-block="linking" style="border-top:1px solid #e4e0d6; margin-top:10px; padding-top:8px;">
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:0 0 4px;">{{ $ar ? 'ملخص الربط' : 'Connections summary' }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="linking-platforms" style="margin-bottom:8px;">
            @foreach ($m['linking'] as $lp)
                @foreach ($lp['items'] as $li)
                    <tr data-state="{{ $li['state'] }}">
                        {{-- ALERT-VISUAL-EVERY-CARD-001 — a small ring in the link's state beside each row. --}}
                        <td width="30" style="padding:2px 0;">@include('mail.partials.visual', ['viz' => null, 'state' => $li['state'] === 'failed' ? 'bad' : 'watch', 'size' => 16])</td>
                        <td style="padding:2px 0; font-size:11px; color:#3d4742;">{{ \Illuminate\Support\Str::limit($lp['name'], 22, '…') }}@if ($li['platform'] !== '') — {{ $li['platform'] }}@endif</td>
                        <td style="padding:2px 0; text-align:{{ $endSide }}; font-size:11px; font-weight:700; color:{{ $li['state'] === 'failed' ? $S::INK['bad'] : $S::INK['watch'] }};">{{ $li['label'] }}@if ($li['since'] !== '') <span style="font-weight:400; color:#8b9a97;">— {{ $ar ? 'آخر تحديث' : 'last refreshed' }} {{ $li['since'] }}</span>@endif</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    </div>
    @endif

    @include('mail.partials.button', ['href' => $m['links']['dashboard'], 'label' => $ar ? 'افتح لوحة المتابعة' : 'Open the dashboard'])
    @endunless
</div>
