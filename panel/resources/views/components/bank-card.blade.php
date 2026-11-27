{{-- Bank card for card-to-card top-up; copy buttons copy English digits (sheba without IR). --}}
@props(['card'])
@php($num = (string) ($card['number'] ?? ''))
@php($sheba = (string) ($card['sheba'] ?? ''))
<div class="bank-card">
  <div class="bc-top"><span class="bc-bank">{{ $card['bank'] ?? '' ?: 'کارت بانکی' }}</span><span class="bc-chip" aria-hidden="true"></span></div>
  @if($num !== '')
    <div class="bc-row">
      <bdi class="bc-num ltr">{{ trim(chunk_split($num, 4, '  ')) }}</bdi>
      <button type="button" class="bc-copy" data-copy-text="{{ $num }}">کپی</button>
    </div>
  @endif
  @if($sheba !== '')
    <div class="bc-row bc-sheba-row">
      <span class="bc-label">شبا</span><bdi class="bc-sheba ltr">IR{{ $sheba }}</bdi>
      <button type="button" class="bc-copy" data-copy-text="{{ $sheba }}">کپی</button>
    </div>
  @endif
  <div class="bc-holder">{{ $card['holder'] ?? '' }}</div>
</div>
