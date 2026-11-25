<x-layouts.guest title="انتقال به درگاه">
  <h1 class="mb-8">انتقال به درگاه {{ $p->methodLabel() }}</h1>
  <p class="muted">مبلغ {{ fa_money($p->amount_rial) }}. اگر خودکار منتقل نشدید، دکمه زیر را بزنید.</p>
  <form method="post" action="{{ $r->url }}" data-autosubmit>
    @foreach($r->fields as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <button class="btn w-full" type="submit">ادامه به درگاه</button>
  </form>
</x-layouts.guest>
