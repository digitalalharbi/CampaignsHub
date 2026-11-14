@php
  $ar = $lang === 'ar';
  $t = $ar ? [
    'title' => 'ملخص الميزانيات', 'as_of' => 'حتى', 'budget' => 'الميزانية', 'spent' => 'الإنفاق', 'remaining' => 'المتبقي',
    'project' => 'المشروع', 'period' => 'الفترة', 'platforms' => 'المنصات', 'status' => 'الحالة', 'open' => 'مفتوحة', 'from' => 'من', 'to' => 'إلى',
    'sar' => 'ر.س', 'history' => 'السجل', 'date' => 'التاريخ', 'entry' => 'الحركة', 'amount' => 'المبلغ', 'who' => 'بواسطة', 'why' => 'السبب',
    'no_rate' => 'ناقص سعر صرف', 'empty' => 'ما فيه ميزانيات',
    'statuses' => ['ok' => 'ضمن الميزانية', 'low' => 'قرّبت تخلص', 'over' => 'تجاوزت الميزانية', 'incomplete' => 'ناقص سعر صرف', 'no_budget' => 'بدون ميزانية', 'direct' => 'دفع مباشر', 'unset' => 'لم يُحدد', 'void' => 'محذوفة'],
    'kinds' => ['initial' => 'ميزانية', 'top_up' => 'تجديد', 'adjustment' => 'تعديل', 'void' => 'حذف', 'reversal' => 'تراجع'],
    'providers' => ['snapchat' => 'سناب شات', 'meta' => 'ميتا', 'tiktok' => 'تيك توك', 'google_ads' => 'قوقل', 'x' => 'إكس', 'linkedin' => 'لينكدإن', 'chatgpt_ads' => 'شات جي بي تي'],
  ] : [
    'title' => 'Budgets summary', 'as_of' => 'As of', 'budget' => 'Budget', 'spent' => 'Spent', 'remaining' => 'Remaining',
    'project' => 'Project', 'period' => 'Period', 'platforms' => 'Platforms', 'status' => 'Status', 'open' => 'Open', 'from' => 'From', 'to' => 'To',
    'sar' => 'SAR', 'history' => 'History', 'date' => 'Date', 'entry' => 'Entry', 'amount' => 'Amount', 'who' => 'By', 'why' => 'Reason',
    'no_rate' => 'Missing FX rate', 'empty' => 'No budgets',
    'statuses' => ['ok' => 'On track', 'low' => 'Running low', 'over' => 'Over budget', 'incomplete' => 'Missing FX rate', 'no_budget' => 'No budget', 'direct' => 'Direct pay', 'unset' => 'Not set', 'void' => 'Deleted'],
    'kinds' => ['initial' => 'Budget', 'top_up' => 'Renewal', 'adjustment' => 'Edit', 'void' => 'Delete', 'reversal' => 'Undo'],
    'providers' => [],
  ];
  $money = fn ($v) => $v === null ? '—' : number_format(round((float) $v), 0, '.', ',');
  $palette = ['#0d8a6f', '#2f6fdb', '#d98a1c', '#8b5cd6', '#c8453b', '#4a5a57'];
  $tone = ['ok' => '#0d8a6f', 'low' => '#d98a1c', 'over' => '#c8453b', 'incomplete' => '#d98a1c', 'no_budget' => '#7a8683', 'direct' => '#7a8683', 'unset' => '#7a8683', 'void' => '#7a8683'];
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $t['title'] }}</title>
<style>
  @page { size: A4 landscape; margin: 0; }
  :root { --ink:#0b1a16; --muted:#5b6b68; --line:#e4e0d6; --brand:#0d8a6f; --soft:#f7f5f0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans Arabic','Noto Sans Arabic','Noto Naskh Arabic','Segoe UI',system-ui,sans-serif; color: var(--ink); font-size: 9.5pt; line-height: 1.4; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .page { width: 297mm; min-height: 210mm; padding: 10mm 12mm 12mm; position: relative; }
  .band { height: 3mm; background: var(--brand); position: absolute; top: 0; left: 0; right: 0; }
  .head { display: flex; justify-content: space-between; align-items: baseline; margin-top: 2mm; }
  .head h1 { margin: 0; font-size: 17pt; font-weight: 800; }
  .muted { color: var(--muted); }
  .num { direction: ltr; unicode-bidi: plaintext; white-space: nowrap; font-variant-numeric: tabular-nums; text-align: {{ $ar ? 'left' : 'right' }}; }
  .strip { display: flex; gap: 6mm; margin: 4mm 0; padding: 2.5mm 4mm; background: var(--soft); border: 0.3mm solid var(--line); border-radius: 2mm; }
  .strip div { flex: 1; } .strip .k { font-size: 8pt; color: var(--muted); } .strip .v { font-size: 13pt; font-weight: 800; }
  table { width: 100%; border-collapse: collapse; }
  th { background: var(--ink); color: #fff; font-size: 8.5pt; padding: 1.8mm 2.5mm; text-align: start; }
  td { padding: 1.8mm 2.5mm; border-bottom: 0.3mm solid var(--line); vertical-align: middle; }
  tr { break-inside: avoid; }
  .bar { height: 2.2mm; background: #ece8dd; border-radius: 2mm; overflow: hidden; width: 38mm; direction: ltr; }
  .bar > i { display: block; height: 100%; }
  .split { display: flex; height: 2.2mm; width: 52mm; border-radius: 2mm; overflow: hidden; background: #ece8dd; direction: ltr; }
  .split > i { display: block; height: 100%; }
  .legend { font-size: 7.5pt; color: var(--muted); margin-top: 0.6mm; }
  .legend b { display: inline-block; width: 1.8mm; height: 1.8mm; border-radius: 50%; margin-inline-end: 0.8mm; }
  .chip { display: inline-block; padding: .3mm 2.4mm; border-radius: 10mm; color: #fff; font-size: 8pt; font-weight: 700; }
  .proj { font-weight: 800; } .client { font-size: 8pt; color: var(--muted); }
  h2 { font-size: 10pt; margin: 6mm 0 1.5mm; } .hist td, .hist th { font-size: 8pt; padding: 1mm 2mm; } .hist th { background: var(--soft); color: var(--ink); }
</style>
</head>
<body>
<div class="page">
  <div class="band"></div>
  <div class="head"><h1>{{ $t['title'] }}</h1><div class="muted">{{ $t['as_of'] }} <span class="num">{{ $asOf }}</span></div></div>

  <div class="strip">
    <div><div class="k">{{ $t['budget'] }}</div><div class="v num">{{ $money($totals['approved']) }} <span class="muted">{{ $t['sar'] }}</span></div></div>
    <div><div class="k">{{ $t['spent'] }}</div><div class="v num">{{ $money($totals['spent']) }} <span class="muted">{{ $t['sar'] }}</span></div></div>
    <div><div class="k">{{ $t['remaining'] }}</div><div class="v num">{{ $money($totals['remaining']) }} <span class="muted">{{ $t['sar'] }}</span></div></div>
  </div>

  @if (count($rows) === 0)
    <p class="muted">{{ $t['empty'] }}</p>
  @else
  <table>
    <thead><tr>
      <th>{{ $t['project'] }}</th><th>{{ $t['period'] }}</th><th>{{ $t['budget'] }}</th><th>{{ $t['spent'] }}</th><th>{{ $t['remaining'] }}</th><th>%</th><th>{{ $t['platforms'] }}</th><th>{{ $t['status'] }}</th>
    </tr></thead>
    <tbody>
    @foreach ($rows as $r)
      @php
        $pct = $r['used_ratio'] === null ? null : round($r['used_ratio'] * 100);
        $total = max(0.01, array_sum(array_column($r['by_platform'], 'spent')));
        $st = $t['statuses'][$r['status']] ?? $r['status'];
      @endphp
      <tr>
        <td><div class="proj">{{ $r['project'] }}</div><div class="client">{{ $r['client'] }}</div></td>
        <td class="num">{{ $r['period_start'] ?? '—' }} → {{ $r['period_end'] ?? $t['open'] }}</td>
        <td class="num">{{ $money($r['approved']) }}</td>
        <td class="num">{{ $money($r['spent']) }}</td>
        <td class="num">{{ $r['remaining'] === null ? '—' : ($r['remaining'] < 0 ? '-' : '').$money(abs($r['remaining'])) }}</td>
        <td>
          @if ($pct !== null)
            <div class="bar"><i style="width: {{ min(100, $pct) }}%; background: {{ $tone[$r['status']] ?? '#0d8a6f' }}"></i></div>
            <div class="legend num">{{ $pct }}%</div>
          @else — @endif
        </td>
        <td>
          <div class="split">
            @foreach ($r['by_platform'] as $i => $p)
              <i style="width: {{ round($p['spent'] / $total * 100, 2) }}%; background: {{ $palette[$i % count($palette)] }}"></i>
            @endforeach
          </div>
          <div class="legend">
            @foreach ($r['by_platform'] as $i => $p)
              <span><b style="background: {{ $palette[$i % count($palette)] }}"></b>{{ $t['providers'][$p['provider']] ?? $p['provider'] }} <span class="num">{{ $money($p['spent']) }}</span>@if (($p['unconverted_days'] ?? 0) > 0) ({{ $t['no_rate'] }})@endif</span>&nbsp;
            @endforeach
          </div>
        </td>
        <td><span class="chip" style="background: {{ $tone[$r['status']] ?? '#7a8683' }}">{{ $st }}</span></td>
      </tr>
    @endforeach
    </tbody>
  </table>

  @if (count($history) > 0)
    @foreach ($rows as $r)
      @continue (empty($history[$r['project_id']] ?? []))
      <h2>{{ $t['history'] }} — {{ $r['project'] }}</h2>
      <table class="hist">
        <thead><tr><th>{{ $t['date'] }}</th><th>{{ $t['entry'] }}</th><th>{{ $t['amount'] }} ({{ $t['sar'] }})</th><th>{{ $t['who'] }}</th><th>{{ $t['why'] }}</th></tr></thead>
        <tbody>
        @foreach ($history[$r['project_id']] as $e)
          <tr>
            <td class="num">{{ $e->paid_on->toDateString() }}</td>
            <td>{{ $t['kinds'][$e->kind] ?? $e->kind }}</td>
            <td class="num">{{ ((float) $e->amount_sar) < 0 ? '-' : '' }}{{ $money(abs((float) $e->amount_sar)) }}</td>
            <td>{{ $e->author?->name }}</td>
            <td>{{ $e->reason ?? $e->note }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endforeach
  @endif
  @endif
</div>
</body>
</html>
