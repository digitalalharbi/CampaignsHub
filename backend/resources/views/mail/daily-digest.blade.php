{{--
  The daily digest — MAIL-002.

  Every block answers the same four questions in the same order: what happened, why it matters, what
  changed, what to do. The verdict line comes FIRST inside each project, before its figures, because
  a reader scanning on a phone at 8am reads one line per project and opens the one that asks for them.

  Nothing here decides whether a number exists. `DigestPresenter` has already turned every figure
  into a string — «12,400 SAR», «لم ترسله المنصة», «لا توجد بيانات» — so there is no `?? 0` left in
  this file to get wrong.
--}}
@php($tone = ['good' => '#0d8a6f', 'warn' => '#b45309', 'bad' => '#b91c1c', 'neutral' => '#5b6b68'])
@php($toneBg = ['good' => '#e8f5f1', 'warn' => '#fffbeb', 'bad' => '#fef2f2', 'neutral' => '#faf8f3'])

<div style="font-family:{!! $font !!}; color:#0b1a16;">

    <div style="font-size:20px; font-weight:800; color:#0b1a16; margin:0 0 4px;">{{ $t['greeting'] }}</div>
    @php($singleProject = $singleProject ?? false)
    @php($client = ($audience ?? 'agency') === 'client')
    @php($noMoney = in_array($audience ?? 'agency', ['client', 'restricted'], true))
    <div style="font-size:14px; color:#5b6b68; margin:0 0 20px;">{{ $client ? ($t['intro_client'] ?? $t['intro']) : ($singleProject && isset($projects[0]) ? ($t['intro_single'] ?? $t['intro']).' '.$projects[0]['name'] : $t['intro']) }}</div>

    {{--
      The account line. Spend and results sum across projects; a cost per result deliberately does not.
      EMAIL-PERF-DASHBOARDS-001 — absent for a single project: a roll-up of one repeats the project's
      own figures one card above them.
    --}}
    @if (! $singleProject)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:20px;">
        <tr>
            <td style="padding:14px 16px;">
                <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px;">{{ $t['account_total'] }}</div>
                {{--
                  EMAIL-DASHBOARD-UX-001 — a KPI card carries its MOVEMENT.

                  Three figures with no comparison told a reader what happened and nothing about
                  whether it was normal. Each card now shows the change against the previous window
                  of the same length, coloured by whether it is good news — and shows NO pill where
                  the previous window was zero, because every rise from nothing is infinite.

                  The cards come from the mailable, so their number varies: revenue is dropped
                  entirely when nothing reported any, rather than printed as a zero a lead-generation
                  account would read as a loss.
                --}}
                {{--
                  EMAIL-PERF-DASHBOARDS-001 — one large figure, then the rest small.

                  Equal cards make a reader do the ranking the email should have done. The first card is
                  the one the mailable ranked first (spend for the account line), so it leads at display
                  size on its own row; the others sit beneath it as a compact strip.
                --}}
                @php($lead = $totals[0] ?? null)
                @php($rest = array_slice($totals, 1))
                @if ($lead)
                    <div style="margin-top:10px;">
                        <div style="font-size:12px; color:#5b6b68;">{{ $lead['label'] }}</div>
                        {{-- The figure sits under its label on the same side: a dir="ltr" figure in an RTL mail would drift to the far edge (Owner 2026-10-22). --}}
                        <div class="ch-kpi-lead" style="font-size:30px; line-height:1.15; font-weight:800; color:#0b1a16; text-align:{{ $startSide }};" dir="ltr">{{ $lead['value'] }}</div>
                        @if ($lead['change'])
                            <div style="font-size:12px; font-weight:700; color:{{ $tone[$lead['tone']] }}; text-align:{{ $startSide }};" dir="ltr">{{ $lead['change'] }}</div>
                        @endif
                        @if (! empty($charts['trend']))
                            {{-- EMAIL-DECISION-REPORT-001 — the window's shape under the figure: a static PNG with its figures in the alt and a caption beneath. --}}
                            <div style="margin-top:8px;" data-block="chart-trend">
                                <img src="{{ $charts['trend']['url'] }}" width="{{ $charts['trend']['width'] }}" height="{{ $charts['trend']['height'] }}" alt="{{ $charts['trend']['alt'] }}" style="display:block; max-width:100%; height:auto; border:0;">
                                <div style="font-size:11px; color:#8b9a97; margin-top:2px;">{{ $charts['trend']['caption'] }}</div>
                            </div>
                        @endif
                    </div>
                @endif
                @if ($rest)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px; border-top:1px solid #e4e0d6;">
                        <tr>
                            @foreach ($rest as $card)
                                <td class="ch-kpi" width="{{ (int) (100 / max(1, count($rest))) }}%" style="vertical-align:top; text-align:center; padding-top:10px;">
                                    <div style="font-size:11px; color:#5b6b68;">{{ $card['label'] }}</div>
                                    <div style="font-size:15px; font-weight:700; color:#0b1a16;" dir="ltr">{{ $card['value'] }}</div>
                                    @if ($card['change'])
                                        <div style="font-size:11px; font-weight:700; color:{{ $tone[$card['tone']] }};" dir="ltr">{{ $card['change'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </table>
                @endif
                {{-- No blended cost per result across projects — and no sentence saying so (Owner 2026-10-22): what is not computed is simply not there. --}}
            </td>
        </tr>
    </table>
    @endif

    {{--
      EMAIL-DECISION-REPORT-001 — the body is a decision report (Owner 2026-10-22, the Moasher reference).

      One row per project: a status pill and the name, spend and the second figure with their
      movement, and ONE large objective figure with its movement in a tone-coloured block. Trouble
      reads first. The daily mail lists the portfolio (ten rows, then «N more»); the weekly and
      monthly lead with the biggest moves and the portfolio's health, then only the rows that need a
      decision. The paths, the funnel, the content, the recommendations and the follow-up counts that
      used to follow every project are analysis, and analysis is what the dashboard behind the button
      is for — an email is read for a decision.
    --}}
    @php($periodic = $kind !== 'daily')
    @php($decisions = array_values(array_filter($portfolio, static fn ($r) => in_array($r['tone'], ['bad', 'warn'], true))))
    @php($listed = $periodic ? $decisions : array_slice($portfolio, 0, 10))
    @php($rest = $periodic ? ($health['good'] + $health['neutral']) : max(0, count($portfolio) - 10))

    @if (($movement['best'] ?? null) || ($movement['worst'] ?? null))
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">{{ $t['highlights'] }}</div>
        @if ($movement['best'])
            <div data-block="highlight-best" style="margin-bottom:8px; padding:10px 12px; border-radius:10px; background-color:{{ $toneBg['good'] }}; border-{{ $startSide }}:3px solid {{ $tone['good'] }};">
                <div style="font-size:13px; font-weight:700; color:#0b1a16;">{{ $movement['best']['name'] }} — {{ $t['best_move'] }}</div>
                <div style="font-size:12px; color:{{ $tone['good'] }};" dir="ltr">{{ $movement['best']['text'] }}</div>
            </div>
        @endif
        @if ($movement['worst'])
            <div data-block="highlight-worst" style="margin-bottom:16px; padding:10px 12px; border-radius:10px; background-color:{{ $toneBg['bad'] }}; border-{{ $startSide }}:3px solid {{ $tone['bad'] }};">
                <div style="font-size:13px; font-weight:700; color:#0b1a16;">{{ $movement['worst']['name'] }} — {{ $t['worst_move'] }}</div>
                <div style="font-size:12px; color:{{ $tone['bad'] }};" dir="ltr">{{ $movement['worst']['text'] }}</div>
            </div>
        @endif
    @endif

    @if ($periodic && count($portfolio) > 1)
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">{{ $t['health'] }}</div>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="health" style="margin-bottom:16px;">
            <tr>
                {{-- A pill per state that HAS projects — a «0 at risk» pill is a count of nothing (Owner 2026-10-22). --}}
                @foreach ([['good', $health['good']], ['warn', $health['warn']], ['bad', $health['bad']], ['neutral', $health['neutral']]] as [$k, $n])
                    @if ($n > 0)
                        <td style="padding-{{ $endSide }}:8px;">
                            <span style="display:inline-block; padding:5px 12px; border-radius:999px; font-size:12px; font-weight:700; background-color:{{ $toneBg[$k] }}; color:{{ $tone[$k] }};" dir="ltr">{{ $n }} {{ $k === 'good' ? ($dir === 'rtl' ? 'بخير' : 'fine') : ($k === 'warn' ? ($dir === 'rtl' ? 'مراقبة' : 'watch') : ($k === 'bad' ? ($dir === 'rtl' ? 'خطر' : 'at risk') : ($dir === 'rtl' ? 'بلا حكم' : 'no verdict'))) }}</span>
                        </td>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @if ($spendShare)
        {{-- Where the money went — the one comparison a list of rows cannot show at a glance. Bars are table cells with a width: the email-safe chart. --}}
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px;">{{ $t['spend_share'] }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spend-share" style="margin-bottom:16px; font-size:12px;">
            @foreach ($spendShare as $bar)
                <tr>
                    <td width="34%" style="padding:3px 0; color:#0b1a16;">{{ $bar['name'] }}</td>
                    <td width="44%" style="padding:3px 6px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eeeae0; border-radius:4px;">
                            <tr><td width="{{ $bar['width'] }}%" style="background-color:#0d8a6f; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td><td></td></tr>
                        </table>
                    </td>
                    <td style="padding:3px 0; color:#0b1a16; text-align:{{ $endSide }}; white-space:nowrap;" dir="ltr">{{ $bar['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($charts['donut']))
        {{-- Where the spend went by platform: the donut, and its legend — which is also the fallback when images are off. --}}
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px;">{{ $t['spend_by_platform'] }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="chart-donut" style="margin-bottom:16px;">
            <tr>
                <td width="{{ $charts['donut']['size'] + 12 }}" style="vertical-align:middle;">
                    <img src="{{ $charts['donut']['url'] }}" width="{{ $charts['donut']['size'] }}" height="{{ $charts['donut']['size'] }}" alt="{{ $charts['donut']['alt'] }}" style="display:block; border:0;">
                </td>
                <td style="vertical-align:middle; font-size:12px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        @foreach ($charts['donut']['legend'] as $item)
                            <tr>
                                <td style="padding:2px 6px 2px 0;"><span style="display:inline-block; width:10px; height:10px; border-radius:5px; background-color:{{ $item['colour'] }};"></span></td>
                                <td style="padding:2px 8px 2px 0; color:#0b1a16;">{{ $item['label'] }}</td>
                                <td style="padding:2px 8px 2px 0; color:#0b1a16; font-weight:700;" dir="ltr">{{ $item['share'] }}</td>
                                <td style="padding:2px 0; color:#5b6b68;" dir="ltr">{{ $item['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if ($listed)
        <div style="font-size:12px; font-weight:700; color:#5b6b68; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">{{ $periodic ? $t['decisions'] : $t['portfolio'] }}</div>
    @endif
    @foreach ($listed as $row)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="portfolio-row"
               style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:10px;">
            <tr>
                <td style="padding:12px 14px; vertical-align:top;">
                    <div>
                        <span class="ch-status" style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; background-color:{{ $toneBg[$row['tone']] }}; color:{{ $tone[$row['tone']] }};">{{ $row['status'] }}</span>
                        <span style="font-size:15px; font-weight:800; color:#0b1a16; padding-{{ $startSide }}:6px;">{{ $row['name'] }}</span>
                    </div>
                    <div style="font-size:12px; color:#5b6b68; line-height:1.6; margin-top:4px;">{{ $row['verdict'] }}</div>
                    @if ($row['attention'] || $row['follow'])
                        <div style="font-size:12px; color:{{ $row['attention'] ? $tone['warn'] : '#5b6b68' }}; line-height:1.6; margin-top:2px;">{{ $t['follow_up'] }}: {{ $row['attention'] ?? $row['follow'] }}</div>
                    @endif
                    @php($cells = array_values(array_filter([$row['spend'], $row['second'], $row['revenue'] ?? null])))
                    @if ($cells)
                    {{-- Equal columns, labels over figures, every column a real figure: the strip stays parallel with no empty cell (Owner 2026-10-22). --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                        <tr>
                            @foreach ($cells as $card)
                                <td class="ch-kpi" width="{{ (int) (100 / count($cells)) }}%" style="vertical-align:top; text-align:center; padding:0 6px;">
                                    <div style="font-size:11px; color:#5b6b68;">{{ $card['label'] }}</div>
                                    <div style="font-size:14px; font-weight:700; color:#0b1a16;" dir="ltr">{{ $card['value'] }}@if ($card['change']) <span style="font-size:11px; font-weight:700; color:{{ $card['change_colour'] }};">{{ $card['change'] }}</span>@endif</div>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                    @endif
                </td>
                @if ($row['objective'])
                    <td width="112" style="padding:12px 14px; vertical-align:middle; text-align:center; background-color:{{ $toneBg[$row['tone']] }}; border-radius:0 12px 12px 0;">
                        <div class="ch-kpi-lead" style="font-size:{{ $singleProject ? '30' : '22' }}px; line-height:1.15; font-weight:800; color:{{ $tone[$row['tone']] }};" dir="ltr">{{ $row['objective']['value'] }}</div>
                        <div style="font-size:11px; color:#5b6b68;">{{ $row['objective']['label'] }}</div>
                        @if ($row['objective']['change'])
                            <div style="font-size:11px; font-weight:700; color:{{ $row['objective']['change_colour'] }};" dir="ltr">{{ $row['objective']['change'] }}</div>
                        @endif
                    </td>
                @endif
            </tr>
        </table>
    @endforeach
    @if ($rest > 0)
        @php($fine = $periodic ? array_values(array_filter($portfolio, static fn ($r) => ! in_array($r['tone'], ['bad', 'warn'], true))) : [])
        <div data-block="portfolio-rest" style="font-size:12px; color:#5b6b68; margin:4px 0 14px;" dir="auto">
            {{ $periodic ? "{$rest} {$t['fine_projects']}" : "+{$rest} {$t['more_projects']}" }}@if ($fine): {{ implode(' · ', array_map(static fn ($r) => $r['name'], $fine)) }}@endif
        </div>
    @endif

    {{-- One button, and it says where it lands. --}}
    <div style="margin:10px 0 18px; text-align:center;">
        <a href="{{ $portfolio[0]['url'] ?? '#' }}" data-block="cta"
           style="display:inline-block; background-color:#0d8a6f; color:#ffffff; font-size:14px; font-weight:700;
                  text-decoration:none; padding:11px 22px; border-radius:10px;">{{ $client ? ($t['open_portal'] ?? $t['open_portfolio']) : $t['open_portfolio'] }}</a>
    </div>

    <div style="font-size:12px; color:#8b9a97; line-height:1.7; margin-top:4px;">{{ $t['footer_note'] }}</div>

    {{-- REPORT-FOOTER-001 — one quiet note and one metadata line, never a card or a heading. --}}
    @if (! empty($reportFooter))
        <div style="margin-top:14px; padding-top:10px; border-top:1px solid #e4e0d6; font-size:11px; color:#8b9a97; line-height:1.7;">
            <div>{{ $reportFooter['note'] }}</div>
            <div style="margin-top:4px;">
                @foreach ($reportFooter['items'] as $item){{ $item[0] }}: {{ $item[1] }}@if (! $loop->last) | @endif @endforeach
            </div>
        </div>
    @endif
</div>
