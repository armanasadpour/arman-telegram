{{-- Status card with a shield illustration. $label string, $tone success|danger|warning|primary --}}
@php($tone = $tone ?? 'primary')
<div class="card stat status-card tone-{{ $tone }}">
  <div class="status-text">
    <div class="label">وضعیت</div>
    <span class="status-pill"><i></i>{{ $label }}</span>
  </div>
  <svg class="status-art" viewBox="0 0 120 110" aria-hidden="true">
    <defs>
      <linearGradient id="sh-{{ $tone }}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" class="sh-a"/><stop offset="1" class="sh-b"/></linearGradient>
      <linearGradient id="pd-{{ $tone }}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#eef2ff"/><stop offset="1" stop-color="#c7d2fe"/></linearGradient>
    </defs>
    <ellipse cx="60" cy="96" rx="40" ry="9" fill="url(#pd-{{ $tone }})"/>
    <ellipse cx="60" cy="92" rx="40" ry="9" fill="#f8faff" stroke="#dbe2ff"/>
    <g class="status-shield">
      <path d="M60 10 L88 21 V45 C88 64 76 77 60 84 C44 77 32 64 32 45 V21 Z" fill="url(#sh-{{ $tone }})"/>
      <path d="M60 10 L88 21 V45 C88 64 76 77 60 84 Z" fill="#000" opacity=".08"/>
      <path d="M60 16 L82 25 V45" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="2" stroke-linecap="round"/>
      @if($tone === 'success')
        <path d="M48 46 L57 55 L73 37" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
      @elseif($tone === 'danger')
        <path d="M50 37 L70 57 M70 37 L50 57" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round"/>
      @elseif($tone === 'warning')
        <path d="M60 33 V50" stroke="#fff" stroke-width="7" stroke-linecap="round"/><circle cx="60" cy="60" r="4" fill="#fff"/>
      @else
        <circle cx="60" cy="47" r="12" fill="none" stroke="#fff" stroke-width="5"/><path d="M60 40 V47 L65 51" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/>
      @endif
    </g>
  </svg>
</div>
