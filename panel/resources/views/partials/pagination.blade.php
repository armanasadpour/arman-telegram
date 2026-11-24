@if ($paginator->hasPages())
<nav aria-label="صفحه‌بندی">
  <ul class="pagination">
    @if ($paginator->onFirstPage())
      <li><span class="disabled">قبلی</span></li>
    @else
      <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">قبلی</a></li>
    @endif
    @foreach ($elements as $element)
      @if (is_string($element))
        <li><span class="disabled">…</span></li>
      @endif
      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <li><span class="current" aria-current="page">{{ fa((string) $page) }}</span></li>
          @else
            <li><a href="{{ $url }}">{{ fa((string) $page) }}</a></li>
          @endif
        @endforeach
      @endif
    @endforeach
    @if ($paginator->hasMorePages())
      <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">بعدی</a></li>
    @else
      <li><span class="disabled">بعدی</span></li>
    @endif
  </ul>
</nav>
@endif
