<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color: #0d1526; font-size: 12px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 18px 0 6px; color: #0d8a6f; }
  .muted { color: #667; }
  .kpis { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .kpis td { border: 1px solid #e6eaef; padding: 8px 10px; }
  .kpis .label { color: #667; font-size: 11px; }
  .kpis .val { font-size: 18px; font-weight: bold; }
  table.data { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 11px; }
  table.data th, table.data td { border: 1px solid #e6eaef; padding: 5px 7px; text-align: left; }
  table.data th { background: #f3f6f9; }
  .badge { background: #fffbeb; color: #d97706; padding: 2px 6px; border-radius: 6px; font-size: 10px; }
  ul.summary li { margin: 3px 0; }
</style>
</head>
<body>
  <h1>{{ $report->name }}</h1>
  <div class="muted">
    {{ $report->type }} ·
    {{ data_get($data, 'period.from') }} → {{ data_get($data, 'period.to') }} ·
    {{ $report->currency }}
    @if($report->is_demo) <span class="badge">Demo</span> @endif
  </div>

  @php $k = $data['kpis'] ?? []; @endphp
  <h2>مؤشرات الأداء الرئيسية</h2>
  <table class="kpis">
    <tr>
      <td><div class="label">الإنفاق</div><div class="val">{{ number_format($k['spend'] ?? 0) }} {{ $report->currency }}</div></td>
      <td><div class="label">الإيرادات</div><div class="val">{{ number_format($k['revenue'] ?? 0) }} {{ $report->currency }}</div></td>
      <td><div class="label">ROAS</div><div class="val">{{ isset($k['roas']) ? number_format($k['roas'],2).'×' : '—' }}</div></td>
      <td><div class="label">النتائج</div><div class="val">{{ number_format($k['conversions'] ?? 0) }}</div></td>
      <td><div class="label">CPA</div><div class="val">{{ isset($k['cpa']) ? number_format($k['cpa']).' '.$report->currency : '—' }}</div></td>
    </tr>
  </table>

  @if(!empty($data['summary']))
  <h2>الملخص التنفيذي</h2>
  <ul class="summary">
    @foreach($data['summary'] as $line)<li>{{ $line }}</li>@endforeach
  </ul>
  @endif

  <h2>أداء المنصات</h2>
  <table class="data">
    <thead><tr><th>المنصة</th><th>الإنفاق</th><th>الإيرادات</th><th>النتائج</th><th>ROAS</th><th>CPA</th></tr></thead>
    <tbody>
      @foreach(($data['platforms'] ?? []) as $p)
      <tr><td>{{ $p['provider'] }}</td><td>{{ number_format($p['spend']) }}</td><td>{{ number_format($p['revenue']) }}</td><td>{{ number_format($p['conversions']) }}</td><td>{{ isset($p['roas']) ? number_format($p['roas'],2).'×':'—' }}</td><td>{{ isset($p['cpa']) ? number_format($p['cpa']):'—' }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <h2>أفضل الحملات</h2>
  <table class="data">
    <thead><tr><th>الحملة</th><th>المنصة</th><th>الإنفاق</th><th>الإيرادات</th><th>ROAS</th></tr></thead>
    <tbody>
      @foreach(array_slice($data['campaigns'] ?? [], 0, 10) as $c)
      <tr><td>{{ $c['campaign_name'] ?? '—' }}</td><td>{{ $c['provider'] ?? '' }}</td><td>{{ number_format($c['spend']) }}</td><td>{{ number_format($c['revenue']) }}</td><td>{{ isset($c['roas']) ? number_format($c['roas'],2).'×':'—' }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <p class="muted" style="margin-top:20px">مصدر البيانات: {{ $report->data_source }} · CampaignsHub</p>
</body>
</html>
