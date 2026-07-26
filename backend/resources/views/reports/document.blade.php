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
  @page { margin: 40px 34px 60px; }
  .doc-footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 9px; color: #8a94a6;
    border-top: 1px solid #e6eaef; padding-top: 5px; text-align: justify; line-height: 1.5; }
  .doc-footer .pnum:after { content: counter(page); }
  .methodology { page-break-before: always; }
  .methodology p { line-height: 1.7; text-align: justify; color: #33405a; }
  .note-box { background: #f3f6f9; border: 1px solid #e6eaef; border-radius: 8px; padding: 10px 12px;
    margin-top: 10px; line-height: 1.7; text-align: justify; color: #33405a; }
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

  @php
    $disc = $data['disclaimer'] ?? [];
    $loc = $disc['locale_default'] ?? 'ar';
    $sec = $disc['sections'] ?? [];
    $en = fn($k) => ($disc['enabled'][$k] ?? true) === true;
    $txt = fn($k) => data_get($sec, "$k.$loc") ?? data_get($sec, "$k.ar");
    $objText = $en('objectives') && !empty($data['objective']) ? data_get($sec, "objectives.{$data['objective']}.$loc", data_get($sec, "objectives.{$data['objective']}.ar")) : null;
  @endphp

  {{-- Short note repeated as a small page footer on every page. --}}
  @if($en('short') && $txt('short'))
  <div class="doc-footer">
    {{ $txt('short') }}
    <span style="float:{{ $loc === 'ar' ? 'left' : 'right' }}">CampaignsHub · <span class="pnum"></span></span>
  </div>
  @endif

  {{-- Full methodology & data notes on a dedicated final page. --}}
  @if($en('full') || $en('methodology'))
  <div class="methodology">
    <h1>منهجية التقرير وملاحظات البيانات</h1>
    <div class="muted">Report Methodology &amp; Data Notes</div>
    @if($en('full') && $txt('full'))<p class="note-box">{{ $txt('full') }}</p>@endif
    @if($en('methodology') && $txt('methodology'))
      <h2>منهجية الحملات القائمة على الأداء</h2>
      <p>{{ $txt('methodology') }}</p>
    @endif
    @if($objText)
      <h2>ملاحظة حسب هدف الحملة</h2>
      <p>{{ $objText }}</p>
    @endif
    @if($en('freshness') && $txt('freshness'))
      <h2>تحديث البيانات والإسناد</h2>
      <p>{{ $txt('freshness') }}</p>
    @endif
    <table class="data" style="margin-top:14px">
      <tr><th>مصدر البيانات</th><td>{{ $report->data_source }}</td></tr>
      <tr><th>نموذج/نافذة الإسناد</th><td>{{ $report->attribution_window ?? '—' }}</td></tr>
      <tr><th>العملة</th><td>{{ $report->currency }}</td></tr>
      <tr><th>المنطقة الزمنية</th><td>{{ $report->timezone }}</td></tr>
      <tr><th>وضع التقرير</th><td>{{ ($report->config['mode'] ?? 'snapshot') === 'live' ? 'Live' : 'Snapshot' }}</td></tr>
      <tr><th>تاريخ الإنشاء</th><td>{{ optional($report->generated_at)->toDateTimeString() ?? now()->toDateTimeString() }}</td></tr>
    </table>
  </div>
  @endif
</body>
</html>
