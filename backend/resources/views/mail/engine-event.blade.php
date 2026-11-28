{{--
  ENGINE-MAIL-VISUAL-001 — one engine notification as a card (rules §9: every notice carries a graphic, never a text-only card).

  The ring in the event's severity with the window the fact was read over, the sentence, the project and the workspace as chips,
  the next step with WHO takes it, and one button to the exact page the event is about, in the reader's own portal and workspace.
  No reference number: it lives in the headers (`X-CH-Reference`) and the delivery log, not in front of the reader.

  Needs: $title, $body, $project, $workspace, $step, $url (nullable), $action, $state (bad|watch|ok), $ar, $dir, $startSide, $font,
  $window (nullable ['from','to']).
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="engine-card" data-state="{{ $state }}" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-{{ $startSide }}:4px solid {{ $S::INK[$state] }}; border-radius:12px; margin-bottom:8px;">
        <tr><td style="padding:12px 14px;">
            <div style="font-size:16px; font-weight:800; color:#0b1a16; line-height:1.5;">{{ $title }}</div>
            @include('mail.partials.visual', ['viz' => null, 'state' => $state, 'size' => 34, 'timeline' => $window, 'dir' => $dir, 'startSide' => $startSide])
            @if ($body !== '')
                <div data-block="engine-body" style="font-size:14px; color:#3d4742; line-height:1.8; padding-top:8px;">{{ $body }}</div>
            @endif
            @if ($project !== '' || $workspace !== '')
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="engine-chips" style="margin-top:8px;"><tr>
                    @if ($project !== '')<td style="padding-{{ $startSide === 'right' ? 'left' : 'right' }}:6px;"><span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $project }}</span></td>@endif
                    @if ($workspace !== '')<td><span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; background-color:#e6e2d6; color:#5b6b68;">{{ $workspace }}</span></td>@endif
                </tr></table>
            @endif
            @if ($step !== '')
                <div data-block="engine-step" style="font-size:12px; font-weight:700; color:{{ $S::INK[$state] }}; padding-top:8px;">{{ $ar ? '←' : '→' }} {{ $step }}</div>
            @endif
        </td></tr>
    </table>
    @if ($url !== null)
        @include('mail.partials.button', ['href' => $url, 'label' => $action, 'font' => $font])
    @endif
</div>
