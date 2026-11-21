{{--
  MAIL-NOTES-001 — Owner 2026-11-21: every summary that carries money closes with the same short notes, and names its sources.
  Needs $ar and $sources (platform display names that reported figures in the period; may be empty).
--}}
<div data-block="mail-notes" style="font-size:11px; color:#5b6b68; line-height:1.7; margin-top:12px; border-top:1px solid #e4e0d6; padding-top:8px;">
    <div style="font-weight:800; color:#3d4742;">{{ $ar ? 'ملاحظات' : 'Notes' }}</div>
    <div>— {{ $ar ? 'الأرقام من بيانات المنصات الإعلانية وقت إعداد هذه الرسالة، للمتابعة التشغيلية فقط' : 'Figures are the ad platforms’ data at the time this message was prepared, for operational follow-up only' }}@if (! empty($sources)) ({{ $ar ? 'المصادر' : 'sources' }}: {{ implode($ar ? '، ' : ', ', $sources) }})@endif.</div>
    <div>— {{ $ar ? 'فواتير المنصات الرسمية هي المرجع النهائي للتدقيق المالي.' : 'The platforms’ official invoices are the final reference for financial audit.' }}</div>
    <div>— {{ $ar ? 'قد تظهر فروقات بسيطة بسبب أسعار الصرف.' : 'Small differences may appear because of exchange rates.' }}</div>
</div>
