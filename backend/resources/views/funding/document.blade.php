@php
  /*
   * BUDGET-STATEMENT-001 / BUDGET-PDF-LAYOUT-001 — «البيان المالي».
   * Every table here is ONE grid: a <colgroup> of fixed widths, the same cells in the head, the rows and the totals row.
   * A numeric cell is a <bdi dir="ltr"> inside a cell aligned to the column's edge, Latin digits, thousands separators and
   * the same decimals down the column; the currency is said once, in the head. The structure is guarded by FundingPdfLayoutTest.
   */
  $ar = $lang === 'ar';
  $st = $statement;
  $t = $ar ? [
    'as_of' => 'حتى', 'budget' => 'الميزانية / السقف', 'spent' => 'الإنفاق', 'remaining' => 'المتبقي', 'to_invoice' => 'هذا الشهر (للفوترة)',
    'project' => 'المشروع', 'client' => 'العميل', 'period' => 'الفترة', 'status' => 'الحالة', 'open' => 'مفتوحة', 'mode' => 'الطريقة',
    'sar' => 'ر.س', 'history' => 'السجل', 'date' => 'التاريخ', 'entry' => 'الحركة', 'amount' => 'المبلغ', 'who' => 'بواسطة', 'why' => 'السبب',
    'empty' => 'ما فيه ميزانيات', 'total' => 'المجموع', 'org' => 'الجهة', 'subject' => 'العميل والمشروع', 'details' => 'بيانات البيان',
    'stmt_period' => 'فترة البيان', 'currency' => 'العملة', 'generated' => 'أُصدر', 'by' => 'بواسطة', 'riyadh' => 'بتوقيت الرياض',
    'clients' => 'عملاء', 'projects' => 'مشاريع', 'platforms' => 'المنصات', 'platform' => 'المنصة', 'planned' => 'المخطط', 'share' => 'الحصة', 'pace' => 'الصرف/يوم', 'projected' => 'المتوقع',
    'month' => 'الشهر', 'invoice_months' => 'المستحق للفوترة بالشهر', 'closed' => 'مكتمل', 'running' => 'جارٍ', 'plan_actual' => 'الخطة مقابل الفعلي', 'plan_diff' => 'فرق عن الخطة', 'no_rate' => 'بدون سعر صرف', 'page' => '',
    'modes' => ['direct' => 'مباشر', 'credit' => 'بالأجل'], 'planned_chip' => 'مخطّطة',
    'flags' => ['over' => 'تجاوزت', 'near' => 'قريبة', 'ahead' => 'أسرع', 'behind' => 'أبطأ', 'unplanned' => 'خارج الخطة'],
    'statuses' => ['ok' => 'ضمن الميزانية', 'low' => 'قرّبت تخلص', 'over' => 'تجاوزت الميزانية', 'incomplete' => 'ناقص سعر صرف', 'no_budget' => 'بدون ميزانية', 'direct' => 'دفع مباشر', 'unset' => 'لم يُحدد', 'void' => 'محذوفة', 'credit' => 'بالأجل', 'credit_near' => 'قرّبت من السقف', 'credit_over' => 'تجاوزت السقف'],
    'kinds' => ['initial' => 'ميزانية', 'top_up' => 'تجديد', 'adjustment' => 'تعديل', 'void' => 'حذف', 'reversal' => 'تراجع', 'reallocation' => 'إعادة توزيع'],
    'providers' => ['snapchat' => 'سناب شات', 'meta' => 'ميتا', 'tiktok' => 'تيك توك', 'google_ads' => 'قوقل', 'x' => 'إكس', 'linkedin' => 'لينكدإن', 'chatgpt_ads' => 'شات جي بي تي', 'openai_ads' => 'شات جي بي تي'],
  ] : [
    'as_of' => 'As of', 'budget' => 'Budget / ceiling', 'spent' => 'Spent', 'remaining' => 'Remaining', 'to_invoice' => 'This month (to invoice)',
    'project' => 'Project', 'client' => 'Client', 'period' => 'Period', 'status' => 'Status', 'open' => 'Open', 'mode' => 'Mode',
    'sar' => 'SAR', 'history' => 'History', 'date' => 'Date', 'entry' => 'Entry', 'amount' => 'Amount', 'who' => 'By', 'why' => 'Reason',
    'empty' => 'No budgets', 'total' => 'Total', 'org' => 'Organisation', 'subject' => 'Client and project', 'details' => 'Statement details',
    'stmt_period' => 'Statement period', 'currency' => 'Currency', 'generated' => 'Generated', 'by' => 'By', 'riyadh' => 'Riyadh time',
    'clients' => 'clients', 'projects' => 'projects', 'platforms' => 'Platforms', 'platform' => 'Platform', 'planned' => 'Planned', 'share' => 'Share', 'pace' => 'Pace/day', 'projected' => 'Projected',
    'month' => 'Month', 'invoice_months' => 'To invoice by month', 'closed' => 'Closed', 'running' => 'Running', 'plan_actual' => 'Plan vs actual', 'plan_diff' => 'Off plan', 'no_rate' => 'No FX rate', 'page' => '',
    'modes' => ['direct' => 'Direct', 'credit' => 'Credit'], 'planned_chip' => 'Planned',
    'flags' => ['over' => 'Over', 'near' => 'Near', 'ahead' => 'Ahead', 'behind' => 'Behind', 'unplanned' => 'Unplanned'],
    'statuses' => ['ok' => 'On track', 'low' => 'Running low', 'over' => 'Over budget', 'incomplete' => 'Missing FX rate', 'no_budget' => 'No budget', 'direct' => 'Direct pay', 'unset' => 'Not set', 'void' => 'Deleted', 'credit' => 'On credit', 'credit_near' => 'Near ceiling', 'credit_over' => 'Over ceiling'],
    'kinds' => ['initial' => 'Budget', 'top_up' => 'Renewal', 'adjustment' => 'Edit', 'void' => 'Delete', 'reversal' => 'Undo', 'reallocation' => 'Reallocation'],
    'providers' => [],
  ];
  // One format per kind of cell, so a column never mixes decimals: money 2 decimals + thousands, share whole percent.
  // BUDGET-CURRENCY-001 — every figure is the number plus its short currency mark, formatted by FundingMoney and nowhere else.
  $money = fn ($v, string $cur = 'SAR') => $v === null ? '—' : new \Illuminate\Support\HtmlString('<bdi>'.e(\App\Domains\Funding\Support\FundingMoney::number($v)).'</bdi><span class="cur">'.e(\App\Domains\Funding\Support\FundingMoney::mark($cur)).'</span>');
  $pctf = fn ($v) => $v === null ? '—' : round((float) $v).'%';
  $pname = fn (string $p) => $t['providers'][$p] ?? $p;
  $palette = ['#0d8a6f', '#2f6fdb', '#d98a1c', '#8b5cd6', '#c8453b', '#4a5a57'];
  $tone = ['ok' => '#0d8a6f', 'low' => '#d98a1c', 'over' => '#c8453b', 'incomplete' => '#d98a1c', 'no_budget' => '#7a8683', 'direct' => '#7a8683', 'unset' => '#7a8683', 'void' => '#7a8683', 'credit' => '#2f6fdb', 'credit_near' => '#d98a1c', 'credit_over' => '#c8453b'];
  $subject = $st['client'] !== null ? $st['client'] : ($st['clients'].' '.$t['clients']);
  $subject .= ' · '.($st['project'] !== null ? $st['project'] : ($st['projects'].' '.$t['projects']));
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $st['title'] }}</title>
<style>
  @page { size: A4 landscape; margin: 0; }
  :root { --ink:#0b1a16; --muted:#5b6b68; --line:#e4e0d6; --brand:#0d8a6f; --soft:#f7f5f0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans Arabic','Noto Sans Arabic','Noto Naskh Arabic','Segoe UI',system-ui,sans-serif; color: var(--ink); font-size: 9.5pt; line-height: 1.4; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .page { width: 297mm; padding: 10mm 12mm 0; position: relative; }
  /* The outer table's repeating footer reserves the room of the fixed page footer on EVERY page, so content never runs under it. */
  table.outer { width: 297mm; border-collapse: collapse; } table.outer > tfoot > tr > td { height: 16mm; padding: 0; }
  .band { height: 3mm; background: var(--brand); position: absolute; top: 0; left: 0; right: 0; }
  .muted { color: var(--muted); }
  /* identity block */
  .id { display: flex; gap: 6mm; align-items: stretch; margin-top: 2mm; }
  .id .box { flex: 1; border: 0.3mm solid var(--line); border-radius: 2mm; padding: 2.5mm 4mm; background: var(--soft); }
  .id .box .k { font-size: 7.5pt; color: var(--muted); } .id .box .v { font-size: 10pt; font-weight: 800; }
  .id .org { display: flex; gap: 3mm; align-items: center; } .id .org img { max-height: 12mm; max-width: 28mm; object-fit: contain; }
  .id h1 { margin: 0 0 1mm; font-size: 17pt; font-weight: 800; }
  .meta { display: flex; flex-wrap: wrap; gap: 1mm 6mm; margin-top: 1.5mm; font-size: 8pt; color: var(--muted); }
  .meta b { color: var(--ink); font-weight: 700; }
  bdi.n, .n bdi { direction: ltr; unicode-bidi: isolate; white-space: nowrap; font-variant-numeric: tabular-nums; }
  /* ONE grid per table */
  table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4mm; }
  .grid th { background: var(--ink); color: #fff; font-size: 8.5pt; padding: 1.8mm 2.5mm; text-align: start; font-weight: 700; }
  .grid td { padding: 1.8mm 2.5mm; border-bottom: 0.2mm solid var(--line); vertical-align: middle; }
  .grid tbody tr:nth-child(even) td { background: #faf8f3; }
  .grid th.n, .grid td.n { text-align: right; }
  .grid tfoot td { border-top: 0.5mm solid var(--ink); border-bottom: 0; font-weight: 800; background: var(--soft); }
  tr { break-inside: avoid; }
  .cur { display: inline-block; min-width: 7mm; margin-inline-end: 1mm; text-align: start; font-size: 0.85em; color: var(--muted); font-weight: 600; }
  .chip { display: inline-block; padding: .3mm 2.2mm; border-radius: 10mm; color: #fff; font-size: 7.5pt; font-weight: 700; white-space: nowrap; }
  .chip.soft { background: #ece8dd; color: var(--ink); }
  .proj { font-weight: 800; } .client { font-size: 8pt; color: var(--muted); }
  .bar { height: 1.8mm; background: #ece8dd; border-radius: 2mm; overflow: hidden; direction: ltr; margin-top: 0.8mm; }
  .bar > i { display: block; height: 100%; }
  h2 { font-size: 10pt; margin: 6mm 0 0; }
  .foot { position: fixed; bottom: 5mm; left: 12mm; right: 12mm; font-size: 7.5pt; color: var(--muted); border-top: 0.2mm solid var(--line); padding-top: 1.5mm; display: flex; justify-content: space-between; gap: 6mm; }
</style>
</head>
<body>
<div class="foot"><span>{{ $st['disclaimer'] }}</span><span>{{ $st['title'] }} · <bdi class="n">{{ $st['generated_at'] }}</bdi></span></div>
<table class="outer"><tbody><tr><td style="padding:0;vertical-align:top">
<div class="page">
  <div class="band"></div>

  {{-- The identity of «البيان المالي»: who issued it, about whom, for which period, when and by whom. Only what exists. --}}
  <div class="id" data-block="statement-identity">
    <div class="box" style="flex: 1.2">
      <h1>{{ $st['title'] }}</h1>
      <div class="org">
        @if (! empty($st['agency']['logo']))<img src="{{ $st['agency']['logo'] }}" alt="">@endif
        <div><div class="k">{{ $t['org'] }}</div><div class="v">{{ $st['agency']['name'] }}</div></div>
      </div>
    </div>
    <div class="box">
      <div class="k">{{ $t['subject'] }}</div><div class="v">{{ $subject }}</div>
      @if (! empty($st['mode']))<div class="meta"><span>{{ $t['mode'] }}: <b>{{ $t['modes'][$st['mode']] ?? $st['mode'] }}</b></span>
        @if (! empty($st['budget_period']))<span>{{ $t['period'] }}: <b><bdi class="n">{{ $st['budget_period'][0] ?? '—' }} → {{ $st['budget_period'][1] ?? $t['open'] }}</bdi></b></span>@endif</div>@endif
    </div>
    <div class="box">
      <div class="k">{{ $t['stmt_period'] }}</div><div class="v"><bdi class="n">{{ $st['period'] }}</bdi></div>
      <div class="meta"><span>{{ $t['currency'] }}: <b>{{ $t['sar'] }}</b></span></div>
      <div class="meta">{{ $st['fx_note'] }}</div>
    </div>
  </div>
  <div class="meta"><span>{{ $t['generated'] }}: <b><bdi class="n">{{ $st['generated_at'] }}</bdi></b> {{ $t['riyadh'] }}</span>
    @if ($st['generated_by'] !== '')<span>{{ $t['by'] }}: <b>{{ $st['generated_by'] }}</b></span>@endif
    <span>{{ $t['as_of'] }}: <b><bdi class="n">{{ $asOf }}</bdi></b></span></div>

  @if (count($rows) === 0)
    <p class="muted">{{ $t['empty'] }}</p>
  @else
  {{-- 1 · the budgets: 9 columns, one grid --}}
  <table class="grid" data-grid="summary">
    <colgroup><col style="width:21%"><col style="width:8%"><col style="width:15%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:5%"><col style="width:7%"></colgroup>
    <thead><tr>
      <th>{{ $t['project'] }}</th><th>{{ $t['mode'] }}</th><th>{{ $t['period'] }}</th>
      <th class="n">{{ $t['budget'] }}</th><th class="n">{{ $t['spent'] }}</th><th class="n">{{ $t['remaining'] }}</th><th class="n">{{ $t['to_invoice'] }}</th>
      <th class="n">%</th><th>{{ $t['status'] }}</th>
    </tr></thead>
    <tbody>
    @foreach ($rows as $r)
      @php
        $credit = ($r['payment_mode'] ?? 'direct') === 'credit';
        $amount = $credit ? ($r['ceiling'] ?? null) : $r['approved'];
        $pct = $r['used_ratio'] === null ? null : $r['used_ratio'] * 100;
        $stText = $t['statuses'][$r['status']] ?? $r['status'];
      @endphp
      <tr>
        <td><div class="proj">{{ $r['project'] }}</div><div class="client">{{ $r['client'] }}</div></td>
        <td><span class="chip soft">{{ $t['modes'][$r['payment_mode'] ?? 'direct'] ?? '' }}</span>@if (! empty($r['planned']))<br><span class="chip soft" style="margin-top:.6mm">{{ $t['planned_chip'] }}</span>@endif</td>
        <td><bdi class="n">{{ $r['period_start'] ?? '—' }} → {{ $r['period_end'] ?? $t['open'] }}</bdi></td>
        <td class="n">{{ $money($amount) }}</td>
        <td class="n">{{ $money($r['spent']) }}</td>
        <td class="n">{{ $money($r['remaining']) }}</td>
        <td class="n">{{ $money($r['this_month'] ?? null) }}</td>
        <td class="n"><bdi>{{ $pctf($pct) }}</bdi>@if ($pct !== null)<div class="bar"><i style="width: {{ min(100, round($pct)) }}%; background: {{ $tone[$r['status']] ?? '#0d8a6f' }}"></i></div>@endif</td>
        <td><span class="chip" style="background: {{ $tone[$r['status']] ?? '#7a8683' }}">{{ $stText }}</span></td>
      </tr>
    @endforeach
    </tbody>
    <tfoot><tr>
      <td>{{ $t['total'] }}</td><td></td><td></td>
      <td class="n">{{ $money($totals['approved']) }}</td><td class="n">{{ $money($totals['spent']) }}</td>
      <td class="n">{{ $money($totals['remaining']) }}</td><td class="n">{{ $money($totals['to_invoice']) }}</td>
      <td class="n"></td><td></td>
    </tr></tfoot>
  </table>

  {{-- 2 · each project's platforms, in USD (the platforms' one unit): planned against spent when it has a plan --}}
  @php
    $platformRows = [];
    foreach ($rows as $r) {
      $usdOrSar = fn ($p) => ($p['spent_usd'] ?? null) !== null;
      if (! empty($r['plan'])) {
        foreach ($r['plan'] as $p) { $platformRows[] = ['project' => $r['project'], 'provider' => $p['provider'], 'planned' => $p['planned_usd'], 'spent' => $p['spent_usd'], 'share' => $p['share_used'] === null ? null : $p['share_used'] * 100, 'flag' => $p['flag'] ? 'diff' : null, 'raw' => $p['unconverted_days'] > 0]; }
      } else {
        foreach ($r['by_platform'] as $p) { $platformRows[] = ['project' => $r['project'], 'provider' => $p['provider'], 'planned' => null, 'spent' => $p['spent_usd'], 'share' => null, 'flag' => null, 'raw' => ($p['unconverted_days'] ?? 0) > 0]; }
      }
    }
  @endphp
  @if (count($platformRows) > 0)
  <h2>{{ $t['platforms'] }}</h2>
  <table class="grid" data-grid="platforms">
    <colgroup><col style="width:30%"><col style="width:16%"><col style="width:16%"><col style="width:16%"><col style="width:10%"><col style="width:12%"></colgroup>
    <thead><tr>
      <th>{{ $t['project'] }}</th><th>{{ $t['platform'] }}</th>
      <th class="n">{{ $t['planned'] }}</th><th class="n">{{ $t['spent'] }}</th><th class="n">{{ $t['share'] }}</th><th></th>
    </tr></thead>
    <tbody>
    @foreach ($platformRows as $p)
      <tr>
        <td>{{ $p['project'] }}</td><td>{{ $pname($p['provider']) }}</td>
        <td class="n">{{ $money($p['planned'], 'USD') }}</td><td class="n">{{ $money($p['spent'], 'USD') }}</td><td class="n"><bdi>{{ $pctf($p['share']) }}</bdi></td>
        <td>@if ($p['raw'])<span class="chip" style="background:#d98a1c">{{ $t['no_rate'] }}</span>@elseif ($p['flag'])<span class="chip" style="background:#d98a1c">{{ $t['plan_diff'] }}</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
    <tfoot><tr>
      <td>{{ $t['total'] }}</td><td></td>
      <td class="n">{{ $money(array_sum(array_map(fn ($p) => (float) $p['planned'], $platformRows)), 'USD') }}</td>
      <td class="n">{{ $money(array_sum(array_map(fn ($p) => (float) $p['spent'], $platformRows)), 'USD') }}</td>
      <td class="n"></td><td></td>
    </tr></tfoot>
  </table>
  @endif

  {{-- 3 · a credit budget's spend by month: what is invoiced from --}}
  @php
    $invoiceRows = [];
    foreach ($rows as $r) { foreach (($r['invoice_months'] ?? []) as $m) { $invoiceRows[] = ['project' => $r['project']] + $m; } }
  @endphp
  @if (count($invoiceRows) > 0)
  <h2>{{ $t['invoice_months'] }}</h2>
  <table class="grid" data-grid="invoice">
    <colgroup><col style="width:30%"><col style="width:14%"><col style="width:26%"><col style="width:16%"><col style="width:14%"></colgroup>
    <thead><tr><th>{{ $t['project'] }}</th><th>{{ $t['month'] }}</th><th>{{ $t['period'] }}</th><th>{{ $t['status'] }}</th><th class="n">{{ $t['spent'] }}</th></tr></thead>
    <tbody>
    @foreach ($invoiceRows as $m)
      <tr>
        <td>{{ $m['project'] }}</td><td><bdi class="n">{{ $m['month'] }}</bdi></td><td><bdi class="n">{{ $m['from'] }} → {{ $m['to'] }}</bdi></td>
        <td><span class="chip {{ $m['closed'] ? '' : 'soft' }}" style="{{ $m['closed'] ? 'background:#0d8a6f' : '' }}">{{ $m['closed'] ? $t['closed'] : $t['running'] }}</span></td>
        <td class="n">{{ $money($m['spent']) }}</td>
      </tr>
    @endforeach
    </tbody>
    <tfoot><tr><td>{{ $t['total'] }}</td><td></td><td></td><td></td><td class="n">{{ $money(array_sum(array_column($invoiceRows, 'spent'))) }}</td></tr></tfoot>
  </table>
  @endif

  @if (count($history) > 0)
    @foreach ($rows as $r)
      @continue (empty($history[$r['project_id']] ?? []))
      <h2>{{ $t['history'] }} — {{ $r['project'] }}</h2>
      <table class="grid" data-grid="history">
        <colgroup><col style="width:12%"><col style="width:16%"><col style="width:16%"><col style="width:20%"><col style="width:36%"></colgroup>
        <thead><tr><th>{{ $t['date'] }}</th><th>{{ $t['entry'] }}</th><th class="n">{{ $t['amount'] }}</th><th>{{ $t['who'] }}</th><th>{{ $t['why'] }}</th></tr></thead>
        <tbody>
        @foreach ($history[$r['project_id']] as $e)
          <tr>
            <td><bdi class="n">{{ $e->paid_on->toDateString() }}</bdi></td>
            <td>{{ $t['kinds'][$e->kind] ?? $e->kind }}</td>
            <td class="n">{{ $money((float) $e->amount_sar) }}</td>
            <td>{{ $e->author?->name }}</td>
            <td>{{ $e->reason ?? $e->note }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endforeach
  @endif
  @endif

  {{-- BUDGET-DISCLAIMER-001 — also at the end of the statement, in full (the footer repeats it on every page). --}}
  <p class="muted" data-block="financial-disclaimer" style="margin-top:6mm; font-size:8.5pt;">{{ $st['disclaimer'] }}</p>
</div>
</td></tr></tbody><tfoot><tr><td></td></tr></tfoot></table>
</body>
</html>
