@php
  $ar = $lang === 'ar';
  $t = $ar ? [
    'invoice' => 'فاتورة ضريبية', 'draft' => 'مسودة', 'void' => 'ملغاة', 'number' => 'رقم الفاتورة', 'issued' => 'تاريخ الإصدار',
    'due' => 'تاريخ الاستحقاق', 'period' => 'فترة الخدمة', 'bill_to' => 'فاتورة إلى', 'from' => 'من', 'vat_no' => 'الرقم الضريبي',
    'description' => 'الوصف', 'qty' => 'الكمية', 'unit' => 'سعر الوحدة', 'amount' => 'المبلغ', 'subtotal' => 'المجموع قبل الضريبة',
    'discount' => 'الخصم', 'vat' => 'ضريبة القيمة المضافة', 'total' => 'الإجمالي', 'paid' => 'المدفوع', 'balance' => 'المتبقي',
    'payment' => 'بيانات الدفع', 'bank' => 'البنك', 'beneficiary' => 'اسم المستفيد', 'iban' => 'الآيبان', 'account' => 'رقم الحساب',
    'notes' => 'ملاحظات', 'thanks' => 'شكراً لتعاملكم معنا.', 'status' => 'الحالة',
    'statuses' => ['draft' => 'مسودة', 'reviewed' => 'تمت المراجعة', 'issued' => 'مُصدرة', 'sent' => 'مُرسلة', 'due' => 'مستحقة', 'overdue' => 'متأخرة', 'partially_paid' => 'مدفوعة جزئياً', 'paid' => 'مدفوعة', 'void' => 'ملغاة'],
  ] : [
    'invoice' => 'Tax invoice', 'draft' => 'DRAFT', 'void' => 'VOID', 'number' => 'Invoice no.', 'issued' => 'Issue date',
    'due' => 'Due date', 'period' => 'Service period', 'bill_to' => 'Bill to', 'from' => 'From', 'vat_no' => 'VAT no.',
    'description' => 'Description', 'qty' => 'Qty', 'unit' => 'Unit price', 'amount' => 'Amount', 'subtotal' => 'Subtotal',
    'discount' => 'Discount', 'vat' => 'VAT', 'total' => 'Total', 'paid' => 'Paid', 'balance' => 'Balance due',
    'payment' => 'Payment details', 'bank' => 'Bank', 'beneficiary' => 'Beneficiary', 'iban' => 'IBAN', 'account' => 'Account no.',
    'notes' => 'Notes', 'thanks' => 'Thank you for your business.', 'status' => 'Status',
    'statuses' => ['draft' => 'Draft', 'reviewed' => 'Reviewed', 'issued' => 'Issued', 'sent' => 'Sent', 'due' => 'Due', 'overdue' => 'Overdue', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void'],
  ];
  $money = fn ($v) => number_format((float) $v, 2, '.', ',');
  $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '—';
  $balance = max(0, (float) $invoice->total - (float) $invoice->amount_paid);
  $lines = collect((array) $invoice->line_items);
  $vatRate = \App\Domains\Billing\Support\TaxTreatment::rate($invoice->tax_treatment);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  :root { --ink:#0b1a16; --muted:#5b6b68; --line:#e4e0d6; --brand:#0d8a6f; --soft:#f7f5f0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans Arabic','Noto Sans Arabic','Noto Naskh Arabic','Segoe UI',system-ui,sans-serif; color: var(--ink); font-size: 11pt; line-height: 1.5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .page { width: 210mm; min-height: 297mm; padding: 16mm 16mm 18mm; position: relative; display: flex; flex-direction: column; }
  .band { height: 4mm; background: var(--brand); position: absolute; top: 0; left: 0; right: 0; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12mm; margin-top: 4mm; }
  .issuer-name { font-size: 17pt; font-weight: 800; }
  .muted { color: var(--muted); }
  .small { font-size: 9.5pt; }
  .title { text-align: {{ $ar ? 'left' : 'right' }}; }
  .title h1 { margin: 0; font-size: 20pt; color: var(--brand); font-weight: 800; }
  .title .no { font-size: 12pt; font-weight: 700; margin-top: 1mm; direction: ltr; }
  .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5mm; margin: 9mm 0 7mm; padding: 4mm 5mm; background: var(--soft); border: 0.3mm solid var(--line); border-radius: 2.5mm; }
  .meta .k { font-size: 8.5pt; color: var(--muted); }
  .meta .v { font-weight: 700; direction: ltr; unicode-bidi: plaintext; text-align: start; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 8mm; margin-bottom: 7mm; }
  .parties h3 { margin: 0 0 1mm; font-size: 9pt; color: var(--muted); font-weight: 700; letter-spacing: .03em; }
  .parties .n { font-weight: 800; font-size: 12pt; }
  table.lines { width: 100%; border-collapse: collapse; }
  table.lines th { background: var(--ink); color: #fff; font-weight: 700; font-size: 9.5pt; padding: 2.4mm 3mm; text-align: start; }
  table.lines td { padding: 3mm; border-bottom: 0.3mm solid var(--line); vertical-align: top; }
  .num { text-align: {{ $ar ? 'left' : 'right' }}; direction: ltr; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .totals { margin-top: 5mm; margin-{{ $ar ? 'right' : 'left' }}: auto; width: 82mm; }
  .totals .row { display: flex; justify-content: space-between; padding: 1.6mm 0; border-bottom: 0.3mm solid var(--line); }
  .totals .row.grand { border-bottom: 0; background: var(--brand); color: #fff; padding: 3mm 4mm; border-radius: 2mm; font-weight: 800; font-size: 12.5pt; margin-top: 2mm; }
  .totals .row.balance { font-weight: 800; }
  .payment { margin-top: 8mm; padding: 4mm 5mm; border: 0.3mm solid var(--line); border-radius: 2.5mm; }
  .payment h3 { margin: 0 0 2mm; font-size: 10pt; }
  .payment .g { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5mm 8mm; }
  .payment .v { font-weight: 700; direction: ltr; unicode-bidi: plaintext; text-align: start; }
  .notes { margin-top: 6mm; }
  .foot { margin-top: auto; padding-top: 8mm; text-align: center; color: var(--muted); font-size: 9pt; }
  .mark { position: absolute; top: 105mm; left: 0; right: 0; text-align: center; font-size: 78pt; font-weight: 900; letter-spacing: .1em; color: rgba(11, 26, 22, .07); transform: rotate(-24deg); pointer-events: none; }
  .badge { display: inline-block; padding: .6mm 3mm; border-radius: 10mm; background: var(--soft); border: 0.3mm solid var(--line); font-size: 9pt; font-weight: 700; }
</style>
</head>
<body>
<div class="page">
  <div class="band"></div>
  @if ($isDraft) <div class="mark">{{ $t['draft'] }}</div> @endif
  @if ($isVoid) <div class="mark">{{ $t['void'] }}</div> @endif

  <div class="head">
    <div>
      <div class="issuer-name"><bdi>{{ $issuer['legal_name'] ?? $issuer['name'] ?? config('brand.name') }}</bdi></div>
      @if (! empty($issuer['address'])) <div class="muted small"><bdi>{{ $issuer['address'] }}</bdi></div> @endif
      @if (! empty($issuer['vat_number'])) <div class="muted small">{{ $t['vat_no'] }}: <bdi dir="ltr">{{ $issuer['vat_number'] }}</bdi></div> @endif
    </div>
    <div class="title">
      <h1>{{ $t['invoice'] }}</h1>
      <div class="no">{{ $invoice->number }}</div>
      <div style="margin-top:2mm"><span class="badge">{{ $t['statuses'][$status] ?? $status }}</span></div>
    </div>
  </div>

  <div class="meta">
    <div><div class="k">{{ $t['issued'] }}</div><div class="v">{{ $date($invoice->issued_at) }}</div></div>
    <div><div class="k">{{ $t['due'] }}</div><div class="v">{{ $date($invoice->due_date) }}</div></div>
    <div><div class="k">{{ $t['period'] }}</div><div class="v">{{ $date($invoice->period_start) }} → {{ $date($invoice->period_end) }}</div></div>
  </div>

  <div class="parties">
    <div>
      <h3>{{ $t['bill_to'] }}</h3>
      <div class="n"><bdi>{{ $billTo['legal_name'] ?? $billTo['name'] ?? '—' }}</bdi></div>
      @if (! empty($billTo['address'])) <div class="muted small"><bdi>{{ $billTo['address'] }}</bdi></div> @endif
      @if (! empty($billTo['vat_number'])) <div class="muted small">{{ $t['vat_no'] }}: <bdi dir="ltr">{{ $billTo['vat_number'] }}</bdi></div> @endif
    </div>
  </div>

  <table class="lines">
    <thead><tr>
      <th>{{ $t['description'] }}</th><th class="num">{{ $t['qty'] }}</th><th class="num">{{ $t['unit'] }}</th><th class="num">{{ $t['amount'] }}</th>
    </tr></thead>
    <tbody>
    @foreach ($lines as $line)
      <tr>
        <td>{{ $ar ? ($line['description_ar'] ?? $line['description'] ?? '') : ($line['description'] ?? $line['description_ar'] ?? '') }}
          @if (! empty($line['period'])) <div class="muted small" dir="ltr" style="text-align:start">{{ $line['period'] }}</div> @endif</td>
        <td class="num">{{ $line['quantity'] ?? 1 }}</td>
        <td class="num">{{ $money($line['unit_price'] ?? $line['total'] ?? 0) }}</td>
        <td class="num">{{ $money($line['total'] ?? $line['unit_price'] ?? 0) }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div class="row"><span>{{ $t['subtotal'] }}</span><span class="num">{{ $money($invoice->subtotal) }} {{ $invoice->currency }}</span></div>
    @if ((float) $invoice->discount > 0)
      <div class="row"><span>{{ $t['discount'] }}</span><span class="num">−{{ $money($invoice->discount) }} {{ $invoice->currency }}</span></div>
    @endif
    <div class="row"><span>{{ $t['vat'] }} @if ($vatRate > 0) <bdi dir="ltr">({{ rtrim(rtrim(number_format($vatRate * 100, 2), '0'), '.') }}%)</bdi> @endif</span><span class="num">{{ $money($invoice->tax) }} {{ $invoice->currency }}</span></div>
    <div class="row grand"><span>{{ $t['total'] }}</span><span class="num">{{ $money($invoice->total) }} {{ $invoice->currency }}</span></div>
    @if ((float) $invoice->amount_paid > 0)
      <div class="row"><span>{{ $t['paid'] }}</span><span class="num">{{ $money($invoice->amount_paid) }} {{ $invoice->currency }}</span></div>
      <div class="row balance"><span>{{ $t['balance'] }}</span><span class="num">{{ $money($balance) }} {{ $invoice->currency }}</span></div>
    @endif
  </div>

  @if (! empty($payment))
    <div class="payment">
      <h3>{{ $t['payment'] }}</h3>
      <div class="g">
        @foreach (['bank' => 'bank', 'beneficiary' => 'beneficiary', 'iban' => 'iban', 'account' => 'account_number'] as $label => $key)
          @if (! empty($payment[$key]))
            <div><span class="muted small">{{ $t[$label] }}</span><div class="v">{{ $payment[$key] }}</div></div>
          @endif
        @endforeach
      </div>
    </div>
  @endif

  @if (! empty($notes))
    <div class="notes"><div class="muted small">{{ $t['notes'] }}</div><div><bdi>{{ $notes }}</bdi></div></div>
  @endif

  <div class="foot">{{ $t['thanks'] }}</div>
</div>
</body>
</html>
