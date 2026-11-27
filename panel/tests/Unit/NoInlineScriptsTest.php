<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The CSP (script-src 'self'; style-src 'self') ignores inline handlers and style attributes: keep them out of the views. */
class NoInlineScriptsTest extends TestCase
{
    public function test_views_have_no_inline_handlers_or_style_attributes(): void
    {
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/views'));
        foreach ($it as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php') || str_contains($f->getPathname(), '/emails/')) {
                continue;
            }
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match('/\son(click|change|input|submit|load)="|\sstyle="/', $line)) {
                    $bad[] = basename($f->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
