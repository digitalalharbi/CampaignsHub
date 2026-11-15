{{--
  «ملخص الإنفاق» — SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001.

  The period's spend, then each project's spend limit (the Budgets section's): what is left, as an amount and a share, a bar,
  and the days of spend left at the period's pace when that can be measured. The same mail serves the workspace (one button
  into the budgets) and a recipient outside the platform (`$external`: no button, no link, no campaign — the project's budget
  follow-up only). Every figure is `ProjectSpendSummary`'s; a missing one is said, never drawn as 0.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($ar = $dir === 'rtl')
@php($tot = $s['total'])
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head', ['title' => $title, 'sub' => $periodLabel])
    @if ($recipientLine !== '')
        <div style="font-size:12px; color:#5b6b68; margin:-6px 0 12px;">{{ $recipientLine }}</div>
    @endif

    @include('mail.blocks.spend-total')

    {{-- One card per project: name, state chip, the bar of the limit used, remaining amount and share, days left. --}}
    @foreach ($s['projects'] as $p)
        @include('mail.blocks.spend-card')
    @endforeach

    @if ($s['projects'] === [])
        <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $ar ? 'ما فيه مشاريع في هذا الملخص.' : 'No projects in this summary.' }}</div>
    @endif

    @if (! $external)
        @include('mail.partials.button', ['href' => $budgetsUrl, 'label' => $ar ? 'افتح الميزانيات' : 'Open budgets'])
    @else
        <div style="font-size:12px; color:#5b6b68; line-height:1.7; margin-top:14px;">{{ $ar ? 'وصلتك هذه الرسالة لمتابعة ميزانية المشاريع المذكورة وتجديدها مع العميل.' : 'You receive this to follow the budgets of the projects above and renew them with the client.' }}</div>
    @endif
    {{-- BUDGET-DISCLAIMER-001 — the one financial disclaimer, wherever money figures appear (external recipients included). --}}
    <div data-block="financial-disclaimer" style="font-size:11px; color:#5b6b68; line-height:1.7; margin-top:12px; border-top:1px solid #e4e0d6; padding-top:8px;">{{ \App\Domains\Funding\Support\FundingDisclaimer::text($ar ? 'ar' : 'en') }}</div>
</div>
