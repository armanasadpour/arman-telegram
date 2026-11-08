<?php
declare(strict_types=1);

// Minimal dependency-free test runner:  php tests/run.php [filter]
// DB tests run when TEST_DB_NAME/TEST_DB_USER/TEST_DB_PASS are set (database is wiped!).
require dirname(__DIR__) . '/app/bootstrap.php';

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }
function ok(bool $cond, string $msg = 'assertion failed'): void { if (!$cond) { throw new RuntimeException($msg); } }
function eq(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function throws(callable $fn, string $class = Throwable::class): void {
    try { $fn(); } catch (Throwable $e) { ok($e instanceof $class, 'wrong exception ' . $e::class . ': ' . $e->getMessage()); return; }
    throw new RuntimeException('expected exception ' . $class);
}

foreach (glob(__DIR__ . '/Unit/*Test.php') as $f) { require $f; }
$filter = $argv[1] ?? '';
$pass = $fail = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    if ($filter !== '' && !str_contains($name, $filter)) { continue; }
    try {
        $fn();
        $pass++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (SkipTest $e) {
        echo "  - $name (skipped: {$e->getMessage()})\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  \033[31m✘ $name\033[0m\n      " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

final class SkipTest extends RuntimeException {}
