@if($q->discount)
  <span class="muted small"><s>{{ fa_money($q->basePrice) }}</s></span>
  <strong>{{ fa_money($q->finalPrice) }}</strong>
  <span class="badge badge-success" title="{{ $q->discount['name'] }}">{{ $q->discount['kind'] === 'percent' ? fa($q->discount['value']).'٪' : 'تخفیف' }}</span>
@else
  <strong>{{ fa_money($q->finalPrice) }}</strong>
@endif
