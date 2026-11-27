<?php

namespace App\Support;

/** SVG path data for the dashboard cards (admin and reseller). */
final class DashIcons
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17" cy="9" r="2.6"/><path d="M16.5 14.2c2.9.3 5 2.4 5 5.3"/>',
            'wifi' => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.4a9.5 9.5 0 0 1 13 0M8.6 15.7a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/>',
            'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
            'db' => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>',
            'cart' => '<path d="M3 4h2.5l2.2 10.5h10.6L20.5 7H7"/><circle cx="9.5" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
            'cal' => '<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/>',
            'wallet' => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><path d="M16 13.5h2"/>',
            'profit' => '<path d="M4 17l5-5 4 3 7-8"/><path d="M15 7h5v5"/>',
            'chart' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        ];
    }
}
