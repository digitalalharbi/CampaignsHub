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
    {{-- DIGEST-PROJECT-CARDS-001 — Owner 2026-11-23: every project is a CARD like «ملخص المشروع»: its name, the spend in a
         shaded box with its change, its share bar, then each objective as a chip with its own tiles (label over figure).
         Never a run-on line of figures. Figures are shortened (125.9K) and the name opens the project's report. Markup is compact on purpose (EMAIL-CLIENT-COMPAT-001): thirty cards must stay
         under Gmail's clip size. --}}
    @foreach ($m['cards'] as $c)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="portfolio-row" style="border:1px solid #e4e0d6;border-radius:12px;margin-bottom:8px"><tr><td style="padding:8px 10px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed"><tr><td valign="middle" style="padding:2px 0"><div style="font-size:14px;font-weight:800;color:#0b1a16">@if ($c['url'] !== '')<a href="{{ $c['url'] }}" style="color:#0b1a16;text-decoration:none">{{ \Illuminate\Support\Str::limit($c['name'], 30, '…') }} <span style="font-size:11px;color:#0b7a62">‹</span></a>@else{{ \Illuminate\Support\Str::limit($c['name'], 30, '…') }}@endif</div></td>@if ($c['spend'])<td width="108" align="center" valign="middle" bgcolor="#e6e2d6" style="background-color:#e6e2d6;border-radius:10px;padding:6px 4px;text-align:center"><div dir="ltr" style="font-size:18px;line-height:1.2;font-weight:800;color:#0b1a16">{{ $c['spend']['value'] }}</div><div style="font-size:10px;color:#5b6b68">{{ $c['spend']['label'] }}</div>@if ($c['spend']['change'])<div dir="ltr" style="font-size:11px;font-weight:800;color:{{ $c['spend']['colour'] }}">{{ $c['spend']['change'] }}</div>@endif</td>@endif</tr></table>
@if ($c['share'] > 0)<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px"><tr><td dir="ltr" bgcolor="#eeeae0" style="background-color:#eeeae0;border-radius:3px"><table role="presentation" width="{{ max(2, $c['share']) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="4" bgcolor="#0d8a6f" style="background-color:#0d8a6f;height:4px;line-height:4px;font-size:1px;border-radius:3px">&nbsp;</td></tr></table></td></tr></table>@endif
@foreach ($c['lines'] as $line)@if ($line['label'] !== '')<div style="margin-top:8px"><span data-chip="objective" style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:800;background-color:#e8f5f1;color:#0a6b57">{{ $line['label'] }}</span></div>@endif
@php($per = min(3, max(1, count($line['figures']))))@php($w = (int) floor(100 / $per))
@foreach (array_chunk($line['figures'], $per) as $row)<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="tiles" style="margin-top:6px;table-layout:fixed"><tr>@foreach ($row as $i => $f)<td width="{{ $w }}%" valign="top" style="padding:0 {{ $i === count($row) - 1 ? 0 : 3 }}px 0 {{ $i === 0 ? 0 : 3 }}px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#faf8f3" style="background-color:#faf8f3;border:1px solid #eeeae0;border-radius:10px;padding:4px 2px;text-align:center"><div style="font-size:10px;line-height:1.3;color:#5b6b68">{{ $f['label'] }}</div><div dir="ltr" style="font-size:13px;line-height:1.25;font-weight:800;color:#0b1a16">{{ $f['value'] }}</div><div dir="ltr" style="font-size:11px;line-height:1.3;font-weight:700;color:{{ $f['colour'] }}">{{ $f['change'] ?: ' ' }}</div></td></tr></table></td>@endforeach @for ($pad = count($row); $pad < $per; $pad++)<td width="{{ $w }}%">&nbsp;</td>@endfor</tr></table>@endforeach
@endforeach
@if ($c['platforms'])<div style="font-size:11px;color:#8b9a97;margin-top:6px">@foreach ($c['platforms'] as $pf){{ $pf['name'] }} <span dir="ltr">{{ $pf['share'] }}%</span>@if (! $loop->last) — @endif @endforeach</div>@endif
</td></tr></table>
    @endforeach

    {{-- DIGEST-PM-ACTIONS-001 — «يحتاج قرارك الآن» for project management: budget actions the figures prove. --}}
    @include('mail.digest._actions', ['items' => $m['actions'] ?? [], 'ar' => $ar, 'startSide' => $startSide])

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
    @include('mail.digest._notes', ['ar' => $ar, 'sources' => array_column($m['mix'] ?? [], 'name')])
</div>
