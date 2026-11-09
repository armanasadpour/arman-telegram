<?php
declare(strict_types=1);

// Builds the single-file installer:  php tools/make-installer.php <staged private-messenger dir> <version> <output install.php>
[$_, $src, $version, $out] = $argv;
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen(rtrim($src, '/')) + 1);
    if (str_starts_with($rel, 'docs/')) {
        continue; // documentation is in the ZIP, not needed on the server
    }
    if ($f->isDir()) {
        $files[$rel . '/.keep'] = '';
        continue;
    }
    $files[$rel] = base64_encode((string)file_get_contents($f->getPathname()));
}
ksort($files);
$payload = gzdeflate((string)json_encode($files, JSON_UNESCAPED_SLASHES), 9);
$stub = (string)file_get_contents(__DIR__ . '/installer-stub.php');
$stub = strtr($stub, ['__PM_VERSION__' => $version, '__PM_SHA256__' => hash('sha256', $payload)]);
if (!str_ends_with(rtrim($stub), '__halt_compiler();')) {
    fwrite(STDERR, "stub must end with __halt_compiler();\n");
    exit(1);
}
file_put_contents($out, rtrim($stub) . $payload);
printf("installer: %s (%d files, %.0f KB)\n", $out, count($files), filesize($out) / 1024);
