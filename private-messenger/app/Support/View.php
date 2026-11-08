<?php
declare(strict_types=1);

namespace PM\Support;

final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        $__view = $this;
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}

/** HTML-escape helper available to templates. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
